<?php

namespace App\Reports\Budget;

use App\Enums\TerritoryType;
use App\Models\FiscalYear;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportSection;
use App\Support\BudgetAccess;
use Illuminate\Validation\ValidationException;

/**
 * Budget reports for a church, a region or the diocese - each about the
 * place's own budgets, a month or a whole year (docs/specs/budgets-spec.md).
 * The figures come from BudgetData, the same as the Overview page, so the
 * page and the PDF always agree. Fiscal years here are calendar years.
 */
abstract class BudgetReport extends Report
{
    public function module(): string
    {
        return 'budget';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH, TerritoryType::REGION, TerritoryType::DIOCESE];
    }

    public function inputs(): array
    {
        return ['fiscal_year', 'fiscal_month'];
    }

    public function icon(): string
    {
        return 'ri-wallet-3-line';
    }

    /** Export is a budget permission of the acting role; the place is its own, or one below. */
    public function authorize(User $user, Territory $territory): ?string
    {
        if (! BudgetAccess::can($user, 'export')) {
            return 'Your role cannot export budget reports.';
        }
        if (! BudgetAccess::canView($user, $territory->territory_type->value, (int) $territory->id)) {
            return 'You can export your own budgets, or those of places below you.';
        }

        return null;
    }

    /** @return array{0: int, 1: int|null} the calendar year, and the month (null = the whole year) */
    protected function period(ReportContext $context): array
    {
        $yearId = $context->param('fiscal_year_id');
        $year = $yearId && $yearId !== 'all' ? FiscalYear::whereKey($yearId)->value('year') : null;
        if (! $year) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'Pick a year - a budget report covers a month or a whole year.']);
        }
        $month = $context->param('month');

        return [(int) $year, $month ? (int) $month : null];
    }

    protected function data(ReportContext $context): BudgetData
    {
        [$year, $month] = $this->period($context);

        return new BudgetData($context->type()->value, (int) $context->territory->id, $year, $month);
    }

    protected function placeLabel(ReportContext $context): string
    {
        return match ($context->type()) {
            TerritoryType::REGION => 'Region',
            TerritoryType::DIOCESE => 'Diocese',
            default => 'Church',
        };
    }

    /** "October 2026", or "Whole of 2026". */
    protected function periodLabel(array $period): string
    {
        return $period['month'] ? $period['label'] : 'Whole of '.$period['year'];
    }

    /** The details panel. */
    protected function meta(ReportContext $context, array $extra = []): array
    {
        return [
            $this->placeLabel($context) => $context->territory->name,
            ...$extra,
            'Prepared by' => $context->preparedBy(),
            'Prepared' => now()->format('j M Y, H:i'),
        ];
    }

    protected static function money(float $value): string
    {
        return 'KES '.number_format($value, 2);
    }

    protected static function pct(?float $value): ?string
    {
        return $value === null ? null : round($value).'%';
    }

    /** Money in, line by line: planned, received, still to come. */
    protected function inSection(array $lines, string $heading = 'Money in by line'): ReportSection
    {
        return new ReportSection($heading, [
            ReportColumn::text('Line', true),
            ReportColumn::money('Planned'),
            ReportColumn::money('Received'),
            ReportColumn::money('Still to come'),
            ReportColumn::number('% received'),
        ], array_map(fn ($l) => [
            $l['name'].($l['is_unplanned'] ? ' (unplanned)' : ''),
            $l['planned'],
            $l['actual'],
            max($l['left'], 0.0),
            self::pct($l['pct']),
        ], $lines), $lines === [] ? 'No money in planned or recorded.' : null);
    }

    /** Money out, line by line: planned, spent, left - or over. */
    protected function outSection(array $lines, string $heading = 'Money out by line'): ReportSection
    {
        return new ReportSection($heading, [
            ReportColumn::text('Line', true),
            ReportColumn::money('Planned'),
            ReportColumn::money('Spent'),
            ReportColumn::money('Left'),
            ReportColumn::number('% used'),
            ReportColumn::text('Note'),
        ], array_map(fn ($l) => [
            $l['name'],
            $l['planned'],
            $l['actual'],
            $l['left'],
            self::pct($l['pct']),
            $l['actual'] > $l['planned'] ? ($l['planned'] > 0 ? 'Over by '.self::money($l['actual'] - $l['planned']) : 'Unplanned') : null,
        ], $lines), $lines === [] ? 'No money out planned or recorded.' : null);
    }

    /** Entries of one direction: date, what for, line, who, how, reference, recorded by, amount. */
    protected function entriesSection(array $entries, string $direction): ReportSection
    {
        $in = $direction === 'in';
        $methods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque'];

        return new ReportSection($in ? 'Money in' : 'Money out', [
            ReportColumn::text('Date'),
            ReportColumn::text('What for', true),
            ReportColumn::text('Line'),
            ReportColumn::text($in ? 'Received from' : 'Paid to'),
            ReportColumn::text('How'),
            ReportColumn::text('Reference'),
            ReportColumn::text('Recorded by'),
            ReportColumn::money('Amount'),
        ], array_map(fn ($e) => [
            date('j M Y', strtotime($e['entry_date'])),
            $e['description'],
            $e['line'],
            $e['counterparty'],
            $methods[$e['method']] ?? null,
            $e['reference'],
            $e['recorded_by'],
            $e['amount'],
        ], array_values(array_filter($entries, fn ($e) => $e['direction'] === $direction))), null, $in ? 'Total received' : 'Total spent');
    }
}
