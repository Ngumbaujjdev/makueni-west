<?php

namespace App\Reports\Budget;

use App\Models\Budget;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** A year at a glance: every month's budget, planned against received and spent. */
final class BudgetYearReport extends BudgetReport
{
    private const STATUS = ['draft' => 'Draft', 'active' => 'In use', 'closed' => 'Closed'];

    public function key(): string
    {
        return 'budget.year';
    }

    public function title(): string
    {
        return 'Year at a glance';
    }

    public function description(): string
    {
        return 'All twelve months side by side: each month\'s budget, planned against received and spent, and money left.';
    }

    public function icon(): string
    {
        return 'ri-calendar-2-line';
    }

    /** A whole year only - no month to pick. */
    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    public function subject(): string
    {
        return 'year at a glance';
    }

    public function build(ReportContext $context): ReportData
    {
        [$year] = $this->period($context);
        $data = new BudgetData($context->type()->value, (int) $context->territory->id, $year, null);
        $d = $data->dashboard();
        $points = $this->balancedMonths($d);
        $budgets = Budget::where('territory_type', $context->type()->value)->where('territory_id', $context->territory->id)->where('fiscal_year', $year)->get();
        $yearBudget = $budgets->firstWhere('period_month', null);
        $budgetFor = function (int $m) use ($budgets, $yearBudget) {
            $b = $budgets->firstWhere('period_month', $m);

            return $b ? self::STATUS[$b->status] ?? $b->status : ($yearBudget ? 'Year budget' : 'None');
        };
        $planned = $budgets->whereNotNull('period_month')->count();
        $busiest = collect($points)->sortByDesc(fn ($p) => $p['in_actual'] + $p['out_actual'])->first();
        $t = $d['totals'];

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: "{$year} at a glance",
            periodLabel: "Whole of {$year}",
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Months planned', 'value' => $yearBudget ? 'Whole year' : "{$planned} of 12", 'tone' => 'primary'],
                ['label' => 'Received', 'value' => self::money($t['in_actual']), 'tone' => 'success'],
                ['label' => 'Spent', 'value' => self::money($t['out_actual']), 'tone' => 'danger'],
                ['label' => 'Money left', 'value' => self::money($t['left_actual']), 'tone' => $t['left_actual'] < 0 ? 'danger' : 'purple'],
                ['label' => 'Busiest month', 'value' => $busiest && ($busiest['in_actual'] + $busiest['out_actual']) > 0 ? $busiest['label'] : '-', 'tone' => 'warning'],
            ],
            meta: $this->meta($context, ['Year' => (string) $year, 'Budgets' => $yearBudget ? "Whole of {$year} (".(self::STATUS[$yearBudget->status] ?? '').')' : "{$planned} month budgets"]),
            sections: [new ReportSection('Month by month', [
                ReportColumn::text('Month', true),
                ReportColumn::text('Budget'),
                ReportColumn::money('Planned in'),
                ReportColumn::money('Received'),
                ReportColumn::money('Planned out'),
                ReportColumn::money('Spent'),
                ReportColumn::money('Left'),
            ], array_map(fn ($p, $i) => [
                $p['label'],
                $budgetFor($i + 1),
                $p['in_planned'],
                $p['in_actual'],
                $p['out_planned'],
                $p['out_actual'],
                round($p['in_actual'] - $p['out_actual'], 2),
            ], $points, array_keys($points)), 'Left is received minus spent in that month.')],
            insights: $data->insights(),
            charts: [$this->monthsChart($points)],
        );
    }
}
