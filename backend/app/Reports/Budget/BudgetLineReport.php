<?php

namespace App\Reports\Budget;

use App\Models\Budget;
use App\Reports\ReportChart;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use Illuminate\Validation\ValidationException;

/** One line of one budget: planned against received or spent, when it moved, and every amount. */
final class BudgetLineReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.line';
    }

    public function title(): string
    {
        return 'Budget line';
    }

    public function description(): string
    {
        return 'One line of a budget: planned against received or spent, when the money moved, and every amount on it.';
    }

    public function icon(): string
    {
        return 'ri-file-list-2-line';
    }

    public function inputs(): array
    {
        return ['budget', 'line'];
    }

    /** Only from the line's own page. */
    public function lockedOnly(): bool
    {
        return true;
    }

    public function subject(): string
    {
        return 'budget line report';
    }

    public function titleFor(array $params): string
    {
        $item = Budget::find($params['budget_id'] ?? null)?->budgetLineItems()->with('budgetLine')->where('budget_line_id', $params['line_id'] ?? null)->first();

        return $item ? "{$item->budgetLine?->name} - line report" : $this->title();
    }

    public function build(ReportContext $context): ReportData
    {
        $budget = Budget::find($context->param('budget_id'));
        $item = $budget?->budgetLineItems()->with('budgetLine', 'budgetCategory')->where('budget_line_id', $context->param('line_id'))->first();
        if (! $budget || ! $item) {
            throw ValidationException::withMessages(['line_id' => 'Choose the budget line to report on.']);
        }
        $isIn = $item->budgetCategory?->slug === 'income';
        $entries = $item->entries()->with('lineItem.budgetLine', 'recorder:id,firstname,lastname')->orderByDesc('entry_date')->orderByDesc('id')->get();
        $planned = (float) $item->budgeted_amount;
        $actual = (float) $item->actual_amount;
        $chart = BudgetData::moneyOverTime($budget, $entries, $planned);
        $rows = $entries->map(fn ($e) => BudgetData::entryRow($e))->all();
        $name = $item->budgetLine?->name ?? 'Line';
        $verb = $isIn ? 'Received' : 'Spent';
        $left = $planned - $actual;

        $charts = $chart['kind'] === 'months'
            ? [ReportChart::bars("{$verb} each month", array_column($chart['points'], 'label'), [
                ['name' => 'A twelfth of the plan', 'tone' => 'primary', 'soft' => true, 'values' => array_column($chart['points'], 'planned')],
                ['name' => $verb, 'tone' => $isIn ? 'success' : 'danger', 'values' => array_column($chart['points'], $isIn ? 'in' : 'out')],
            ], 'By the date each amount was recorded.')]
            : [ReportChart::hbars('Planned against '.strtolower($verb), [$name], [
                ['name' => 'Planned', 'tone' => $isIn ? 'success' : 'primary', 'soft' => true, 'values' => [$planned]],
                ['name' => $verb, 'tone' => $isIn ? 'success' : 'primary', 'values' => [$actual]],
            ], null, ! $isIn)];

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $name,
            periodLabel: $budget->period_month ? "{$budget->period_label} budget" : "Whole of {$budget->fiscal_year} budget",
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Planned', 'value' => self::money($planned), 'tone' => 'primary'],
                ['label' => $verb, 'value' => self::money($actual), 'tone' => $isIn ? 'success' : 'danger'],
                ['label' => $isIn ? 'Still to come' : ($left < 0 ? 'Over by' : 'Left'), 'value' => self::money(abs($isIn ? max($left, 0) : $left)), 'tone' => ! $isIn && $left < 0 ? 'danger' : 'purple'],
                ['label' => 'Entries', 'value' => (string) count($rows), 'tone' => 'warning'],
            ],
            meta: $this->meta($context, array_filter([
                'Line' => $name.($item->is_unplanned ? ' (unplanned)' : ''),
                'Kind' => $isIn ? 'Income' : 'Expense',
                'Budget' => "{$budget->period_label} ({$budget->status_label})",
                'Last entry' => self::day($rows[0]['entry_date'] ?? null),
            ])),
            sections: [$this->entriesSection($rows, $isIn ? 'in' : 'out')],
            charts: $charts,
        );
    }
}
