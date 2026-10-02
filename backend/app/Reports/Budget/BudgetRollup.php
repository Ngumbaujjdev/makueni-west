<?php

namespace App\Reports\Budget;

use App\Models\Budget;
use App\Models\BudgetDeductionItem;
use App\Models\Territory;
use App\Services\Budgets\Deductions;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\ReportFacts;
use App\Support\Reports\Insights\Rules\BudgetRollupCoverageRule;
use App\Support\Reports\Insights\Rules\BudgetRollupDraftsRule;
use App\Support\Reports\Insights\Rules\BudgetRollupOverPlanRule;
use App\Support\Reports\Insights\Rules\BudgetRollupOwedRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The budgets of the places below one, read-only (docs/specs/budgets-spec.md,
 * phase 5): a region's churches, or the diocese's churches and regions -
 * one row per place for a month or a whole year, with totals and what we
 * noticed. The page (GET /budgets/below) and the budget.rollup report share
 * it, so they always agree.
 *
 * Which budget counts for a period is BudgetData::plansFrom(), the same rule
 * as the Overview; what was received and spent is by entry date; deductions
 * come from Deductions::status(), the one source of due / sent / still owed.
 *
 * owedFor() gives one place's deductions - who each is owed to, due, sent,
 * still owed, budget by budget - for a "what we owe upward" view.
 */
final class BudgetRollup
{
    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** @var Collection<int, object>|null every territory, by id - for names and groups */
    private ?Collection $territories = null;

    public function __construct(private Deductions $deductions) {}

    /**
     * The places below one: its churches, or - for the diocese, with
     * $level = 'region' - its regions. Each with the group it is shown
     * under: a church's region (diocese) or subregion (region).
     *
     * @return array<int, array{id: int, name: string, type: string, group_id: ?int, group: ?string}>
     */
    public function placesBelow(Territory $place, string $level = 'church'): array
    {
        $all = $this->territories();
        $type = $place->territory_type->value;
        $groupType = $type === 'diocese' ? 'region' : 'subregion';
        $isBelow = function (int $id) use ($all, $place) {
            for ($t = $all->get($id), $depth = 0; $t && $depth < 6; $t = $all->get($t->parent_territory_id), $depth++) {
                if ((int) $t->parent_territory_id === (int) $place->id) {
                    return true;
                }
            }

            return false;
        };

        return $all->filter(fn ($t) => $t->territory_type === $level && $isBelow((int) $t->id))
            ->map(function ($t) use ($level, $groupType) {
                $group = $level === 'church' ? $this->ancestor((int) $t->id, $groupType) : null;

                return ['id' => (int) $t->id, 'name' => $t->name, 'type' => $level, 'group_id' => $group ? (int) $group->id : null, 'group' => $group?->name];
            })
            ->sortBy(fn ($p) => [$p['group'] ?? '~', $p['name']])
            ->values()->all();
    }

    /**
     * One row per place below for a month (or the whole year): its budget
     * and status, planned and received / spent, money left, % used, and
     * the deductions it still owes.
     */
    public function rows(Territory $place, int $year, ?int $month, string $level = 'church'): array
    {
        $places = $this->placesBelow($place, $level);
        $ids = array_column($places, 'id');
        if ($ids === []) {
            return [];
        }
        [$start, $end] = $this->range($year, $month);
        $budgets = Budget::where('territory_type', $level)->whereIn('territory_id', $ids)->where('fiscal_year', $year)->get()->groupBy('territory_id');
        $plans = collect($ids)->mapWithKeys(fn ($id) => [$id => BudgetData::plansFrom($budgets->get($id, collect()), $month)]);
        $planIds = $plans->flatten(1)->map(fn ($p) => $p['budget']->id)->all();
        $planned = $this->planned($planIds);
        $actual = $this->actual($level, $ids, $start, $end);
        $withDeductions = BudgetDeductionItem::whereIn('budget_id', $planIds)->distinct()->pluck('budget_id')->flip();

        return array_map(function ($p) use ($plans, $planned, $actual, $withDeductions) {
            $mine = $plans[$p['id']];
            $in = $out = 0.0;
            $owed = $due = $sent = null;
            $whole = $mine->every(fn ($x) => $x['share'] >= 1);
            foreach ($mine as $x) {
                $in += ($planned[$x['budget']->id]['income'] ?? 0) * $x['share'];
                $out += ($planned[$x['budget']->id]['expense'] ?? 0) * $x['share'];
                if ($whole && $withDeductions->has($x['budget']->id)) {
                    foreach ($this->deductions->status($x['budget']) as $d) {
                        $due += $d['due'];
                        $sent += $d['sent'];
                        $owed += $d['owed'];
                    }
                }
            }
            $received = $actual[$p['id']]['in'] ?? 0.0;
            $spent = $actual[$p['id']]['out'] ?? 0.0;
            $one = $mine->count() === 1 ? $mine->first()['budget'] : null;

            return [
                ...$p,
                'status' => $this->statusOf($mine),
                'budget_id' => $one?->id,
                'budget_label' => $one ? $one->period_label : ($mine->isEmpty() ? null : $mine->count().' month budgets'),
                'is_year' => $one && $one->period_month === null,
                'months' => $mine->count(),
                'in_planned' => round($in, 2),
                'in_actual' => round($received, 2),
                'out_planned' => round($out, 2),
                'out_actual' => round($spent, 2),
                'left' => round($received - $spent, 2),
                'pct_used' => $out > 0 ? round($spent / $out * 100, 1) : null,
                'over' => $out > 0 && $spent > $out ? round($spent - $out, 2) : 0.0,
                'deductions' => $due === null ? null : ['due' => round($due, 2), 'sent' => round($sent, 2), 'owed' => round($owed, 2)],
            ];
        }, $places);
    }

    /**
     * Everything the page and the report show: the rows, their totals, the
     * period before (for "vs last month"), six months of sparkline, and
     * what we noticed.
     */
    public function summary(Territory $place, int $year, ?int $month, string $level = 'church'): array
    {
        $rows = $this->rows($place, $year, $month, $level);
        $ids = array_column($rows, 'id');
        [$prevYear, $prevMonth] = $month === null ? [$year - 1, null] : ($month === 1 ? [$year - 1, 12] : [$year, $month - 1]);
        [$prevStart, $prevEnd] = $this->range($prevYear, $prevMonth);
        $previous = $this->actual($level, $ids, $prevStart, $prevEnd);
        [$start, $end] = $this->range($year, $month);
        $totals = self::totals($rows);
        $label = $this->label($year, $month);
        $words = $level === 'region' ? ['region', 'regions'] : ['church', 'churches'];

        return [
            'period' => ['year' => $year, 'month' => $month, 'label' => $label, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'previous_label' => $this->label($prevYear, $prevMonth)],
            'level' => $level,
            'words' => $words,
            'rows' => $rows,
            'totals' => $totals,
            'previous' => [
                'in_actual' => round(array_sum(array_column($previous, 'in')), 2),
                'out_actual' => round(array_sum(array_column($previous, 'out')), 2),
            ],
            'spark' => $this->spark($level, $ids, $end),
            'groups' => collect($rows)->whereNotNull('group')->groupBy('group')->map(fn ($g, $name) => ['name' => $name, 'places' => $g->count(), ...self::totals($g->all())])->values()->all(),
            'insights' => array_map(fn ($i) => $i->toArray(), InsightEngine::run([
                new BudgetRollupCoverageRule,
                new BudgetRollupOverPlanRule,
                new BudgetRollupOwedRule,
                new BudgetRollupDraftsRule,
            ], new ReportFacts(['period_label' => $label, 'words' => $words, 'rows' => $rows, ...$totals]), 5)),
        ];
    }

    /** The totals of some rows. */
    public static function totals(array $rows): array
    {
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
        $owed = array_filter(array_column($rows, 'deductions'));
        $status = array_count_values(array_column($rows, 'status'));

        return [
            'places' => count($rows),
            'with_budget' => count($rows) - ($status['none'] ?? 0),
            'in_use' => $status['active'] ?? 0,
            'drafts' => $status['draft'] ?? 0,
            'closed' => $status['closed'] ?? 0,
            'none' => $status['none'] ?? 0,
            'over' => count(array_filter($rows, fn ($r) => $r['over'] > 0)),
            'in_planned' => $sum('in_planned'),
            'in_actual' => $sum('in_actual'),
            'out_planned' => $sum('out_planned'),
            'out_actual' => $sum('out_actual'),
            'left' => $sum('left'),
            'due' => round(array_sum(array_column($owed, 'due')), 2),
            'sent' => round(array_sum(array_column($owed, 'sent')), 2),
            'owed' => round(array_sum(array_column($owed, 'owed')), 2),
        ];
    }

    /**
     * One place's deductions for a month (or the whole year): per deduction,
     * who set it (who it's owed to), its rate, and due / sent / still owed -
     * in total and budget by budget (each month budget is a month). Due,
     * sent and owed are only counted on whole budgets, as on the Overview.
     */
    public function owedFor(Territory $place, int $year, ?int $month): array
    {
        $plans = BudgetData::plansFrom(
            Budget::where('territory_type', $place->territory_type->value)->where('territory_id', $place->id)->where('fiscal_year', $year)->get(),
            $month,
        );
        $rows = [];
        foreach ($plans as $p) {
            $whole = $p['share'] >= 1;
            foreach ($this->deductions->status($p['budget']) as $d) {
                $key = $d['id'] ?? $d['name'];
                $owner = $d['owner_id'] ? $this->territories()->get($d['owner_id']) : null;
                $rows[$key] ??= [
                    'id' => $d['id'],
                    'name' => $d['name'],
                    'rule' => $d['rule'],
                    'rate_type' => $d['rate_type'],
                    'rate_value' => $d['rate_value'],
                    'line_id' => $d['line_id'],
                    'line' => $d['line'],
                    'owed_to' => ['type' => $d['owner_type'], 'id' => $d['owner_id'], 'name' => $owner?->name],
                    'set_by' => $d['set_by'],
                    'planned' => 0.0, 'due' => 0.0, 'sent' => 0.0, 'owed' => 0.0,
                    'budgets' => [],
                ];
                $rows[$key]['planned'] += $d['planned'] * $p['share'];
                foreach (['due', 'sent', 'owed'] as $k) {
                    $rows[$key][$k] += $whole ? $d[$k] : 0.0;
                }
                $rows[$key]['budgets'][] = [
                    'budget_id' => $p['budget']->id,
                    'label' => $p['budget']->period_label,
                    'month' => $p['budget']->period_month,
                    'planned' => round($d['planned'] * $p['share'], 2),
                    'due' => $whole ? $d['due'] : null,
                    'sent' => $whole ? $d['sent'] : null,
                    'owed' => $whole ? $d['owed'] : null,
                ];
            }
        }

        return array_values(array_map(fn ($r) => [...$r, 'planned' => round($r['planned'], 2), 'due' => round($r['due'], 2), 'sent' => round($r['sent'], 2), 'owed' => round($r['owed'], 2)], $rows));
    }

    /**
     * What a place sends up for a year (Contributions): one row per budget and
     * deduction - tithes (or money) received, due, sent, still to send - with
     * a status from the budget's period:
     *   sent     - something was due and all of it was sent
     *   pending  - still to send, and the period is still running
     *   late     - still to send after the period ended
     *   none     - nothing due yet (nothing received on the lines it counts)
     * Due, sent and owed come from Deductions::status(), the one source.
     */
    public function contributionsOf(string $type, int $id, int $year): array
    {
        $today = CarbonImmutable::today();
        $budgets = Budget::where('territory_type', $type)->where('territory_id', $id)->where('fiscal_year', $year)
            ->whereIn('id', BudgetDeductionItem::query()->select('budget_id'))
            ->orderByRaw('period_month IS NULL DESC, period_month')->get();
        $rows = [];
        foreach ($budgets as $b) {
            foreach ($this->deductions->status($b) as $d) {
                $owner = $d['owner_id'] ? $this->territories()->get($d['owner_id']) : null;
                $ended = $today->gt(CarbonImmutable::parse($b->end_date));
                $rows[] = [
                    'budget_id' => $b->id,
                    'budget_status' => $b->status,
                    'label' => $b->period_label,
                    'month' => $b->period_month,
                    'end' => CarbonImmutable::parse($b->end_date)->toDateString(),
                    'deduction_id' => $d['id'],
                    'name' => $d['name'],
                    'rule' => $d['rule'],
                    'line_id' => $d['line_id'],
                    'line' => $d['line'],
                    'to' => $owner?->name,
                    'received' => $d['base_received'],
                    'due' => $d['due'],
                    'sent' => $d['sent'],
                    'owed' => $d['owed'],
                    'status' => $d['due'] <= 0 && $d['sent'] <= 0 ? 'none' : ($d['owed'] <= 0 ? 'sent' : ($ended ? 'late' : 'pending')),
                ];
            }
        }

        return $rows;
    }

    /** One place's contribution totals for a year: received, due, sent, still to send, late periods, and an overall status. */
    public static function contributionTotals(array $rows): array
    {
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
        $late = count(array_filter($rows, fn ($r) => $r['status'] === 'late'));
        $owed = $sum('owed');
        $due = $sum('due');

        return [
            'received' => $sum('received'), 'due' => $due, 'sent' => $sum('sent'), 'owed' => $owed, 'late' => $late,
            'status' => $late ? 'late' : ($owed > 0 ? 'pending' : ($due > 0 ? 'sent' : 'none')),
        ];
    }

    /** The churches below a region or the diocese, each with its contribution totals for a year. */
    public function contributionsBelow(Territory $place, int $year): array
    {
        return array_map(fn ($p) => [...$p, ...self::contributionTotals($this->contributionsOf('church', $p['id'], $year))], $this->placesBelow($place, 'church'));
    }

    /** In use / draft / closed for one budget; for several month budgets the liveliest; "none" when there is none. */
    private function statusOf(Collection $plans): string
    {
        $statuses = $plans->map(fn ($p) => $p['budget']->status)->all();
        foreach (['active', 'draft', 'closed'] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }

        return 'none';
    }

    /** Planned money in and out per budget: [budget id => ['income' => x, 'expense' => y]]. */
    private function planned(array $budgetIds): array
    {
        $out = [];
        if ($budgetIds === []) {
            return $out;
        }
        DB::table('budget_line_items as i')
            ->join('budget_categories as c', 'c.id', '=', 'i.budget_category_id')
            ->whereIn('i.budget_id', $budgetIds)->whereNull('i.deleted_at')
            ->groupBy('i.budget_id', 'c.slug')
            ->selectRaw('i.budget_id, c.slug, SUM(i.budgeted_amount) AS planned')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[$r->budget_id][$r->slug] = (float) $r->planned;
            });

        return $out;
    }

    /** Received and spent per place, by entry date: [place id => ['in' => x, 'out' => y]]. */
    private function actual(string $level, array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $this->entries($level, $ids)
            ->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('b.territory_id', 'e.direction')
            ->selectRaw('b.territory_id, e.direction, SUM(e.amount) AS total')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[$r->territory_id][$r->direction] = (float) $r->total;
            });

        return $out;
    }

    /** The last six months received and spent across the places (ending with this period). */
    private function spark(string $level, array $ids, CarbonImmutable $end): array
    {
        $from = $end->startOfMonth()->subMonths(5);
        $byMonth = $ids === [] ? collect() : $this->entries($level, $ids)
            ->whereBetween('e.entry_date', [$from->toDateString(), $end->toDateString()])
            ->groupByRaw("DATE_FORMAT(e.entry_date, '%Y-%m'), e.direction")
            ->selectRaw("DATE_FORMAT(e.entry_date, '%Y-%m') AS ym, e.direction, SUM(e.amount) AS total")
            ->get();
        $labels = $in = $out = [];
        for ($m = 0; $m < 6; $m++) {
            $month = $from->addMonths($m);
            $labels[] = $month->format('M');
            $of = $byMonth->where('ym', $month->format('Y-m'));
            $in[] = round((float) $of->where('direction', 'in')->sum('total'), 2);
            $out[] = round((float) $of->where('direction', 'out')->sum('total'), 2);
        }

        return ['labels' => $labels, 'in' => $in, 'out' => $out];
    }

    private function entries(string $level, array $ids)
    {
        return DB::table('budget_entries as e')
            ->join('budgets as b', 'b.id', '=', 'e.budget_id')
            ->whereNull('e.deleted_at')->whereNull('b.deleted_at')
            ->where('b.territory_type', $level)->whereIn('b.territory_id', $ids);
    }

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

    /** Every territory (there are under a hundred), keyed by id. */
    private function territories(): Collection
    {
        return $this->territories ??= DB::table('territories')->whereNull('deleted_at')
            ->get(['id', 'name', 'territory_type', 'parent_territory_id'])->keyBy('id');
    }

    /** The nearest territory of a type above one. */
    private function ancestor(int $id, string $type): ?object
    {
        $all = $this->territories();
        for ($t = $all->get($all->get($id)?->parent_territory_id), $depth = 0; $t && $depth < 6; $t = $all->get($t->parent_territory_id), $depth++) {
            if ($t->territory_type === $type) {
                return $t;
            }
        }

        return null;
    }
}
