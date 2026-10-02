<?php

namespace App\Reports\Budget;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/**
 * Budget summary for a month or a year: what was planned against what came
 * in and went out, line by line, month by month for a year, and what we
 * noticed. The same figures as the Overview page.
 */
final class BudgetSummaryReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.summary';
    }

    public function title(): string
    {
        return 'Budget summary';
    }

    public function description(): string
    {
        return 'Planned against received and spent, line by line, for a month or a year, with what we noticed.';
    }

    public function icon(): string
    {
        return 'ri-pie-chart-2-line';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $d = $data->dashboard();
        $t = $d['totals'];
        $budget = $d['budget'];

        $sections = [$this->inSection($d['lines']['in']), $this->outSection($d['lines']['out'])];
        if ($d['deductions']['rows'] !== []) {
            $sections[] = $this->deductionsSection($d['deductions']['rows']);
        }
        $charts = [];
        if ($d['trend']['kind'] === 'months') {
            $points = $this->balancedMonths($d);
            $charts[] = $this->monthsChart($points);
            $sections[] = new ReportSection('Month by month', [
                ReportColumn::text('Month', true),
                ReportColumn::money('Planned in'),
                ReportColumn::money('Received'),
                ReportColumn::money('Planned out'),
                ReportColumn::money('Spent'),
            ], array_map(fn ($p) => [$p['label'], $p['in_planned'], $p['in_actual'], $p['out_planned'], $p['out_actual']], $points));
        }

        return new ReportData(
            kicker: $context->kicker('budget summary'),
            title: 'Budget summary',
            periodLabel: $this->periodLabel($d['period']),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Planned in', 'value' => self::money($t['in_planned']), 'tone' => 'primary'],
                ['label' => 'Received', 'value' => self::money($t['in_actual']), 'tone' => 'success'],
                ['label' => 'Planned out', 'value' => self::money($t['out_planned']), 'tone' => 'warning'],
                ['label' => 'Spent', 'value' => self::money($t['out_actual']), 'tone' => 'danger'],
                ['label' => 'Money left', 'value' => self::money($t['left_actual']), 'tone' => $t['left_actual'] < 0 ? 'danger' : 'purple', 'hint' => 'Received minus spent'],
            ],
            meta: $this->meta($context, [
                'Period' => $this->periodLabel($d['period']),
                'Budget' => $budget ? "{$budget['period_label']} (".(['draft' => 'Draft', 'active' => 'In use', 'closed' => 'Closed'][$budget['status']] ?? $budget['status']).')' : (count($d['budgets']) ? count($d['budgets']).' month budgets' : 'No budget'),
                'Entries' => (string) $t['entries'],
            ]),
            sections: $sections,
            insights: $data->insights(),
            charts: [...$charts, $this->linesChart($d['lines']['out'], 'out', 8), $this->linesChart($d['lines']['in'], 'in', 8)],
        );
    }
}
