<?php

namespace App\Reports\Budget;

use App\Enums\TerritoryType;
use App\Models\FiscalYear;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportChart;
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

    /**
     * A year's months (from dashboard()'s trend). A whole-year budget is
     * shared out in twelfths, rounded to the cent - the last month takes
     * what rounding left over, so the months add up to the year's plan.
     */
    protected function balancedMonths(array $d): array
    {
        $points = $d['trend']['points'];
        foreach (['in_planned', 'out_planned'] as $key) {
            $drift = round($d['totals'][$key] - array_sum(array_column($points, $key)), 2);
            $points[11][$key] = round($points[11][$key] + $drift, 2);
        }

        return $points;
    }

    /** Received and spent per month, as columns. */
    protected function monthsChart(array $points, string $title = 'Money in and out, month by month'): ReportChart
    {
        return ReportChart::bars($title, array_column($points, 'label'), [
            ['name' => 'Received', 'tone' => 'success', 'values' => array_column($points, 'in_actual')],
            ['name' => 'Spent', 'tone' => 'danger', 'values' => array_column($points, 'out_actual')],
        ], 'By the date each amount was recorded.');
    }

    /** Planned (soft) against received or spent (solid) for the biggest lines of a side. */
    protected function linesChart(array $lines, string $side, int $limit = 10, ?string $title = null): ReportChart
    {
        $top = array_slice($lines, 0, $limit);
        $in = $side === 'in';

        return ReportChart::hbars(
            $title ?? ($in ? 'Money in: planned against received' : 'Money out: planned against spent'),
            array_column($top, 'name'),
            [
                ['name' => 'Planned', 'tone' => $in ? 'success' : 'primary', 'soft' => true, 'values' => array_column($top, 'planned')],
                ['name' => $in ? 'Received' : 'Spent', 'tone' => $in ? 'success' : 'primary', 'values' => array_column($top, 'actual')],
            ],
            count($lines) > $limit ? 'The biggest '.$limit.' of '.count($lines).' lines.' : null,
            ! $in,
        );
    }

    /** How many entries each line has, and its last date, from periodEntries(). */
    protected function lineActivity(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $key = $e['line_id'];
            $out[$key] ??= ['count' => 0, 'last' => null];
            $out[$key]['count']++;
            $out[$key]['last'] = max($out[$key]['last'] ?? '', $e['entry_date']);
        }

        return $out;
    }

    protected static function day(?string $date): ?string
    {
        return $date ? date('j M Y', strtotime($date)) : null;
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
