<?php

namespace App\Reports\Budget;

use App\Models\Budget;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use Illuminate\Validation\ValidationException;

/**
 * One budget: its lines planned against received and spent, every entry
 * recorded against it, and its recent History. Opened from the budget's page.
 */
final class BudgetStatementReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.statement';
    }

    public function title(): string
    {
        return 'Budget statement';
    }

    public function description(): string
    {
        return 'One budget: each line planned against received and spent, every entry, and its history.';
    }

    public function icon(): string
    {
        return 'ri-file-list-3-line';
    }

    public function inputs(): array
    {
        return ['budget'];
    }

    /** Only from a budget's own page. */
    public function lockedOnly(): bool
    {
        return true;
    }

    public function titleFor(array $params): string
    {
        $budget = Budget::find($params['budget_id'] ?? null);

        return $budget ? "{$budget->period_label} budget statement" : $this->title();
    }

    public function build(ReportContext $context): ReportData
    {
        $budget = Budget::with('creator:id,firstname,lastname', 'starter:id,firstname,lastname')->find($context->param('budget_id'));
        if (! $budget) {
            throw ValidationException::withMessages(['budget_id' => 'Choose the budget to report on.']);
        }
        $data = new BudgetData($budget->territory_type, (int) $budget->territory_id, (int) $budget->fiscal_year, $budget->period_month);
        $d = $data->dashboard();
        $t = $d['totals'];
        $entries = $data->periodEntries($budget->id);
        $name = fn ($u) => $u ? trim("{$u->firstname} {$u->lastname}") : null;

        $history = $budget->budgetLogs()->with('performer:id,firstname,lastname')->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get();

        return new ReportData(
            kicker: $context->kicker('budget statement'),
            title: "{$budget->period_label} budget",
            periodLabel: $budget->period_month ? $budget->period_label : "Whole of {$budget->fiscal_year}",
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Planned in', 'value' => self::money($t['in_planned']), 'tone' => 'primary'],
                ['label' => 'Received', 'value' => self::money($t['in_actual']), 'tone' => 'success'],
                ['label' => 'Planned out', 'value' => self::money($t['out_planned']), 'tone' => 'warning'],
                ['label' => 'Spent', 'value' => self::money($t['out_actual']), 'tone' => 'danger'],
                ['label' => 'Money left', 'value' => self::money($t['left_actual']), 'tone' => $t['left_actual'] < 0 ? 'danger' : 'purple', 'hint' => 'Received minus spent'],
            ],
            meta: $this->meta($context, array_filter([
                'Budget' => "{$budget->period_label} ({$budget->status_label})",
                'Budget prepared by' => $name($budget->creator),
                'In use since' => $budget->started_at ? $budget->started_at->format('j M Y').($budget->starter ? ' · '.$name($budget->starter) : '') : null,
                'Entries' => (string) count($entries),
            ])),
            sections: [
                $this->inSection($d['lines']['in']),
                $this->outSection($d['lines']['out']),
                ...(($deductions = app(\App\Services\Budgets\Deductions::class)->status($budget)) ? [$this->deductionsSection($deductions)] : []),
                $this->entriesSection($entries, 'in'),
                $this->entriesSection($entries, 'out'),
                new ReportSection('History', [
                    ReportColumn::text('When'),
                    ReportColumn::text('Who'),
                    ReportColumn::text('What happened', true),
                ], $history->map(fn ($log) => [$log->created_at?->format('j M Y, H:i'), $name($log->performer), $log->description])->all(), 'The latest 15 changes.'),
            ],
            insights: $data->insights(),
            charts: [
                ...($d['trend']['kind'] === 'months' ? [$this->monthsChart($d['trend']['points'])] : []),
                $this->linesChart($d['lines']['out'], 'out'),
                $this->linesChart($d['lines']['in'], 'in'),
            ],
        );
    }
}
