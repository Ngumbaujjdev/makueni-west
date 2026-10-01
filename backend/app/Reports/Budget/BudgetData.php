<?php

namespace App\Reports\Budget;

use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\ReportFacts;
use App\Support\Reports\Insights\Rules\BudgetBalanceRule;
use App\Support\Reports\Insights\Rules\BudgetIncomeShortfallRule;
use App\Support\Reports\Insights\Rules\BudgetOverPlanRule;
use App\Support\Reports\Insights\Rules\BudgetSpendingPaceRule;
use App\Support\Reports\Insights\Rules\BudgetStatusRule;
use App\Support\Reports\Insights\Rules\BudgetUnplannedRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the budget Overview shows for one place (church, region or
 * diocese) and one period - a month, or a whole year: what was planned,
 * what actually came in and went out, line by line, against the period
 * before, how the money moved over time, the latest entries, and what we
 * noticed. The budget PDF reports are built from the same figures.
 *
 * What counts as planned: the period's own budget - a month budget for a
 * month, the month budgets (or the whole-year budget) for a year. Looking at
 * one month of a whole-year budget counts a twelfth of its plan.
 */
final class BudgetData
{
    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public function __construct(private string $type, private int $id, private int $year, private ?int $month) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(int $year, ?int $month): array
    {
        $start = CarbonImmutable::create($year, $month ?? 1, 1);

        return [$start, $month ? $start->endOfMonth()->startOfDay() : $start->endOfYear()->startOfDay()];
    }

    private function label(int $year, ?int $month): string
    {
        return $month ? self::MONTHS[$month].' '.$year : (string) $year;
    }

    /** The period before: last month, or last year. */
    private function previous(): array
    {
        if ($this->month === null) {
            return [$this->year - 1, null];
        }

        return $this->month === 1 ? [$this->year - 1, 12] : [$this->year, $this->month - 1];
    }

    /**
     * The budgets whose plan counts for a period, each with the share of it
     * that counts (1, or 1/12 when a month is looked at in a year budget).
     *
     * @return Collection<int, array{budget: Budget, share: float}>
     */
    private function plans(int $year, ?int $month): Collection
    {
        $budgets = Budget::with('budgetLineItems.budgetLine', 'budgetLineItems.budgetCategory')
            ->where('territory_type', $this->type)->where('territory_id', $this->id)
            ->where('fiscal_year', $year)
            ->get();
        $yearBudget = $budgets->firstWhere('period_month', null);

        if ($month !== null) {
            $own = $budgets->firstWhere('period_month', $month);

            return collect($own ? [['budget' => $own, 'share' => 1.0]] : ($yearBudget ? [['budget' => $yearBudget, 'share' => 1 / 12]] : []));
        }

        return $yearBudget
            ? collect([['budget' => $yearBudget, 'share' => 1.0]])
            : $budgets->whereNotNull('period_month')->map(fn ($b) => ['budget' => $b, 'share' => 1.0])->values();
    }

    /** Entries of this place in a date range. */
    private function entries(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return BudgetEntry::with('lineItem.budgetLine', 'recorder:id,firstname,lastname')
            ->whereHas('budget', fn ($q) => $q->where('territory_type', $this->type)->where('territory_id', $this->id))
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->get();
    }

    private static function totals(Collection $plans, Collection $entries): array
    {
        $planned = fn (string $slug) => round($plans->sum(fn ($p) => $p['budget']->budgetLineItems
            ->filter(fn ($i) => $i->budgetCategory?->slug === $slug)->sum('budgeted_amount') * $p['share']), 2);
        $in = round((float) $entries->where('direction', 'in')->sum('amount'), 2);
        $out = round((float) $entries->where('direction', 'out')->sum('amount'), 2);
        $inPlanned = $planned('income');
        $outPlanned = $planned('expense');

        return [
            'in_planned' => $inPlanned,
            'in_actual' => $in,
            'out_planned' => $outPlanned,
            'out_actual' => $out,
            'left_planned' => round($inPlanned - $outPlanned, 2),
            'left_actual' => round($in - $out, 2),
            'entries' => $entries->count(),
        ];
    }

    public function dashboard(): array
    {
        [$start, $end] = $this->range($this->year, $this->month);
        [$prevYear, $prevMonth] = $this->previous();
        [$prevStart, $prevEnd] = $this->range($prevYear, $prevMonth);
        $plans = $this->plans($this->year, $this->month);
        $entries = $this->entries($start, $end);
        $totals = self::totals($plans, $entries);
        $previous = self::totals($this->plans($prevYear, $prevMonth), $this->entries($prevStart, $prevEnd));
        $lines = $this->lines($plans, $entries);
        $today = CarbonImmutable::today();
        $running = $today->betweenIncluded($start, $end);
        $timePct = $running ? round(($start->diffInDays($today) + 1) / ($start->diffInDays($end) + 1) * 100, 1) : null;
        $label = $this->label($this->year, $this->month);
        $budget = $this->budgetInView($plans);

        $lastEntry = $entries->max('entry_date');
        $facts = new ReportFacts([
            'period_label' => $label,
            'status' => $budget['status'] ?? null,
            'running' => $running,
            'ended' => $today->gt($end),
            'time_pct' => $timePct,
            ...$totals,
            'over_lines' => collect($lines['out'])->filter(fn ($l) => $l['actual'] > $l['planned'])
                ->map(fn ($l) => ['name' => $l['name'], 'over' => round($l['actual'] - $l['planned'], 2)])->sortByDesc('over')->values()->all(),
            'unplanned' => collect([...$lines['in'], ...$lines['out']])->where('is_unplanned', true)->values()->all(),
            'days_since_entry' => $lastEntry ? (int) CarbonImmutable::parse($lastEntry)->diffInDays($today) : null,
        ]);

        return [
            'period' => [
                'year' => $this->year,
                'month' => $this->month,
                'label' => $label,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'previous_label' => $this->label($prevYear, $prevMonth),
                'time_pct' => $timePct,
                'ended' => $today->gt($end),
            ],
            'budget' => $budget,
            'budgets' => $plans->map(fn ($p) => ['id' => $p['budget']->id, 'period_label' => $p['budget']->period_label, 'status' => $p['budget']->status])->values(),
            'totals' => $totals,
            'previous' => $previous,
            'lines' => $lines,
            'trend' => $this->month === null ? $this->yearTrend($entries) : $this->monthTrend($entries, $start, $end, $totals),
            'spark' => $this->spark($end),
            'recent' => $entries->take(8)->map(fn ($e) => self::entryRow($e))->values(),
            'insights' => array_map(fn ($i) => $i->toArray(), InsightEngine::run([
                new BudgetStatusRule,
                new BudgetOverPlanRule,
                new BudgetBalanceRule,
                new BudgetSpendingPaceRule,
                new BudgetIncomeShortfallRule,
                new BudgetUnplannedRule,
            ], $facts, 6)),
        ];
    }

    /** The budget being looked at: the month's or the whole year's (null when there is none, or several months). */
    private function budgetInView(Collection $plans): ?array
    {
        if ($plans->count() !== 1) {
            return null;
        }
        $p = $plans->first();
        $b = $p['budget'];

        return ['id' => $b->id, 'status' => $b->status, 'period_label' => $b->period_label, 'is_year' => $b->period_month === null, 'share' => round($p['share'], 4)];
    }

    /** Line by line: planned (from the plans) and received/spent (from the entries). */
    private function lines(Collection $plans, Collection $entries): array
    {
        $rows = [];
        foreach ($plans as $p) {
            foreach ($p['budget']->budgetLineItems as $item) {
                $key = $item->budget_line_id;
                $rows[$key] ??= [
                    'line_id' => $key,
                    'name' => $item->budgetLine?->name ?? "Line {$key}",
                    'side' => $item->budgetCategory?->slug === 'income' ? 'in' : 'out',
                    'planned' => 0.0,
                    'actual' => 0.0,
                    'is_unplanned' => false,
                ];
                $rows[$key]['planned'] += (float) $item->budgeted_amount * $p['share'];
                $rows[$key]['is_unplanned'] = $rows[$key]['is_unplanned'] || ($item->is_unplanned && (float) $item->budgeted_amount == 0.0);
            }
        }
        foreach ($entries as $e) {
            $key = $e->lineItem?->budget_line_id;
            if ($key === null) {
                continue;
            }
            $rows[$key] ??= ['line_id' => $key, 'name' => $e->lineItem->budgetLine?->name ?? "Line {$key}", 'side' => $e->direction, 'planned' => 0.0, 'actual' => 0.0, 'is_unplanned' => true];
            $rows[$key]['actual'] += (float) $e->amount;
        }
        $shape = function ($r) {
            $r['planned'] = round($r['planned'], 2);
            $r['actual'] = round($r['actual'], 2);
            $r['left'] = round($r['planned'] - $r['actual'], 2);
            $r['pct'] = $r['planned'] > 0 ? round($r['actual'] / $r['planned'] * 100, 1) : null;

            return $r;
        };
        $all = collect($rows)->map($shape)->sortByDesc(fn ($r) => max($r['planned'], $r['actual']))->values();

        return [
            'in' => $all->where('side', 'in')->values()->all(),
            'out' => $all->where('side', 'out')->values()->all(),
        ];
    }

    /** A year: money in and out received/spent each month, with each month's plan. */
    private function yearTrend(Collection $entries): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $ofMonth = $entries->filter(fn ($e) => (int) $e->entry_date->month === $m);
            $plan = self::totals($this->plans($this->year, $m), collect());
            $months[] = [
                'label' => substr(self::MONTHS[$m], 0, 3),
                'in_actual' => round((float) $ofMonth->where('direction', 'in')->sum('amount'), 2),
                'out_actual' => round((float) $ofMonth->where('direction', 'out')->sum('amount'), 2),
                'in_planned' => $plan['in_planned'],
                'out_planned' => $plan['out_planned'],
            ];
        }

        return ['kind' => 'months', 'points' => $months];
    }

    /** A month: money out spent so far, day by day, against an even pace through the month. */
    private function monthTrend(Collection $entries, CarbonImmutable $start, CarbonImmutable $end, array $totals): array
    {
        $days = $start->diffInDays($end) + 1;
        $today = CarbonImmutable::today();
        $points = [];
        $spent = 0.0;
        $received = 0.0;
        for ($d = 0; $d < $days; $d++) {
            $day = $start->addDays($d);
            $ofDay = $entries->filter(fn ($e) => $e->entry_date->isSameDay($day));
            $spent += (float) $ofDay->where('direction', 'out')->sum('amount');
            $received += (float) $ofDay->where('direction', 'in')->sum('amount');
            $future = $day->gt($today);
            $points[] = [
                'label' => (string) $day->day,
                'out_actual' => $future ? null : round($spent, 2),
                'in_actual' => $future ? null : round($received, 2),
                'out_pace' => round($totals['out_planned'] * ($d + 1) / $days, 2),
            ];
        }

        return ['kind' => 'days', 'points' => $points];
    }

    /** The last six months received and spent (ending with this period), for sparklines. */
    private function spark(CarbonImmutable $end): array
    {
        $from = $end->startOfMonth()->subMonths(5);
        $entries = BudgetEntry::whereHas('budget', fn ($q) => $q->where('territory_type', $this->type)->where('territory_id', $this->id))
            ->whereBetween('entry_date', [$from->toDateString(), $end->toDateString()])
            ->get(['entry_date', 'direction', 'amount']);
        $labels = $in = $out = [];
        for ($m = 0; $m < 6; $m++) {
            $month = $from->addMonths($m);
            $ofMonth = $entries->filter(fn ($e) => $e->entry_date->format('Y-m') === $month->format('Y-m'));
            $labels[] = $month->format('M');
            $in[] = round((float) $ofMonth->where('direction', 'in')->sum('amount'), 2);
            $out[] = round((float) $ofMonth->where('direction', 'out')->sum('amount'), 2);
        }

        return ['labels' => $labels, 'in' => $in, 'out' => $out, 'left' => array_map(fn ($a, $b) => round($a - $b, 2), $in, $out)];
    }

    public static function entryRow(BudgetEntry $e): array
    {
        return [
            'id' => $e->id,
            'budget_id' => $e->budget_id,
            'line_id' => $e->lineItem?->budget_line_id,
            'line' => $e->lineItem?->budgetLine?->name,
            'direction' => $e->direction,
            'amount' => (float) $e->amount,
            'entry_date' => $e->entry_date?->toDateString(),
            'description' => $e->description,
            'counterparty' => $e->counterparty,
            'method' => $e->method,
            'reference' => $e->reference,
            'recorded_by' => $e->recorder ? trim("{$e->recorder->firstname} {$e->recorder->lastname}") : null,
            'deleted' => $e->trashed(),
        ];
    }
}
