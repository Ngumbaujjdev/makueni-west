<?php

namespace App\Reports\Budget;

use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\BudgetAccess;
use App\Support\Reports\Insights\Insight;

/**
 * The budgets of the places below (docs/specs/budgets-spec.md, phase 5):
 * a region's churches, or the diocese's churches by region - who has a
 * budget, planned against received and spent, and deductions still owed.
 * Same figures as the "Churches' budgets" / "Regions and churches" page.
 */
final class BudgetRollupReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.rollup';
    }

    public function title(): string
    {
        return 'Budgets of the places below';
    }

    public function description(): string
    {
        return 'Each church\'s budget for a month or the year: who has one, planned against received and spent, and deductions still owed.';
    }

    public function icon(): string
    {
        return 'ri-node-tree';
    }

    public function subject(): string
    {
        return 'budgets below report';
    }

    public function scopes(): array
    {
        return [TerritoryType::REGION, TerritoryType::DIOCESE];
    }

    /** Export and "below" of the acting region / diocese role, for its own place. */
    public function authorize(User $user, Territory $territory): ?string
    {
        if ($user->hasGlobalAccess()) {
            return null;
        }
        if (! BudgetAccess::can($user, 'export') || ! BudgetAccess::can($user, 'below')) {
            return 'Your role cannot export the budgets of the places below.';
        }
        if (! BudgetAccess::isOwn($user, $territory->territory_type->value, (int) $territory->id)) {
            return 'You can export the budgets below your own region or diocese.';
        }

        return null;
    }

    public function build(ReportContext $context): ReportData
    {
        [$year, $month] = $this->period($context);
        $s = app(BudgetRollup::class)->summary($context->territory, $year, $month);
        $t = $s['totals'];
        $periodLabel = $this->periodLabel($s['period']);
        $isDiocese = $context->type() === TerritoryType::DIOCESE;
        $groups = collect($s['rows'])->groupBy(fn ($r) => $r['group'] ?? ($isDiocese ? 'Not in a region' : 'Not in a subregion'));

        // Each group lists its churches with a budget; those without one are listed once, at the end.
        $sections = $groups->map(function ($rows, $name) use ($isDiocese, $groups, $periodLabel) {
            $with = $rows->where('status', '!=', 'none');

            return new ReportSection(
                $isDiocese ? $name : ($groups->count() > 1 ? $name : 'Churches'),
                $this->columns(),
                $with->map(fn ($r) => $this->row($r))->values()->all(),
                $with->isEmpty() ? "No church here has a budget for {$periodLabel} yet ({$rows->count()} churches)." : null,
            );
        })->values()->all();
        $none = array_values(array_filter($s['rows'], fn ($r) => $r['status'] === 'none'));
        $grouped = collect($none)->whereNotNull('group')->isNotEmpty();
        $sections[] = new ReportSection('No budget yet', [
            ReportColumn::text('Church', true),
            ...($grouped ? [ReportColumn::text($isDiocese ? 'Region' : 'Subregion')] : []),
        ], array_map(fn ($r) => $grouped ? [$r['name'], $r['group'] ?? '-'] : [$r['name']], $none), $none === [] ? "Every church has a budget for {$periodLabel}." : null);

        $top = collect($s['rows'])->filter(fn ($r) => $r['out_planned'] > 0 || $r['out_actual'] > 0)
            ->sortByDesc(fn ($r) => max($r['out_planned'], $r['out_actual']))->take(10)->values();

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $isDiocese ? 'Churches\' budgets across the diocese' : 'Churches\' budgets',
            periodLabel: $periodLabel,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'In use', 'value' => "{$t['in_use']} of {$t['places']}", 'tone' => $t['none'] ? 'warning' : 'success'],
                ['label' => 'Received', 'value' => self::money($t['in_actual']), 'tone' => 'success'],
                ['label' => 'Spent', 'value' => self::money($t['out_actual']), 'tone' => 'danger'],
                ['label' => 'Over plan', 'value' => (string) $t['over'], 'tone' => $t['over'] ? 'danger' : 'success'],
                ['label' => 'Still owed', 'value' => self::money($t['owed']), 'tone' => $t['owed'] > 0 ? 'warning' : 'success'],
            ],
            meta: $this->meta($context, [
                'Period' => $periodLabel,
                'Churches' => "{$t['places']} ({$t['with_budget']} with a budget)",
                'Planned in / out' => self::money($t['in_planned']).' / '.self::money($t['out_planned']),
            ]),
            sections: $sections,
            insights: array_map(fn ($i) => new Insight($i['tone'], $i['title'], $i['detail'], $i['recommendation']), $s['insights']),
            charts: $top->isEmpty() ? [] : [ReportChart::hbars('Spent against plan, by church', $top->pluck('name')->all(), [
                ['name' => 'Planned', 'tone' => 'primary', 'soft' => true, 'values' => $top->pluck('out_planned')->all()],
                ['name' => 'Spent', 'tone' => 'danger', 'values' => $top->pluck('out_actual')->all()],
            ], 'The ten churches with the most money out.', true)],
        );
    }

    private function columns(): array
    {
        return [
            ReportColumn::text('Church', true),
            ReportColumn::text('Budget'),
            ReportColumn::money('Planned in'),
            ReportColumn::money('Received'),
            ReportColumn::money('Planned out'),
            ReportColumn::money('Spent'),
            ReportColumn::number('% used'),
            ReportColumn::money('Still owed'),
        ];
    }

    private function row(array $r): array
    {
        $status = ['active' => 'In use', 'draft' => 'Draft', 'closed' => 'Closed', 'none' => 'No budget'][$r['status']];

        return [
            $r['name'],
            $status.($r['months'] > 1 ? " ({$r['months']} months)" : ($r['is_year'] ? ' (year)' : '')),
            $r['in_planned'],
            $r['in_actual'],
            $r['out_planned'],
            $r['out_actual'],
            self::pct($r['pct_used']),
            $r['deductions']['owed'] ?? 0.0,
        ];
    }
}
