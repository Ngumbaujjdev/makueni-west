<?php

namespace App\Reports\Facilities;

use App\Models\Equipment;
use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/**
 * Bought in a year (P5 round 3): what the church bought - each month and by
 * kind - with the shop, whether the receipt is kept and whether it is
 * recorded in Budgets, so the treasurer can see what still needs doing.
 */
final class BoughtInYearReport extends FacilitiesReport
{
    public function key(): string
    {
        return 'facilities.bought';
    }

    public function title(): string
    {
        return 'Bought in a year';
    }

    public function description(): string
    {
        return 'What we bought in the year: each month and by kind, the shop, and whether each receipt is kept and recorded in Budgets.';
    }

    public function icon(): string
    {
        return 'ri-shopping-bag-3-line';
    }

    public function subject(): string
    {
        return 'purchases';
    }

    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    public function build(ReportContext $context): ReportData
    {
        $f = $this->facilities();
        $church = $context->territory;
        [$from, $to, $label] = $this->year($context);
        $items = Equipment::withCount(['media as receipts_count' => fn ($q) => $q->where('collection_name', 'receipts')])
            ->where('territory_id', $church->id)->whereBetween('bought_on', [$from->toDateString(), $to->toDateString()])->orderBy('bought_on')->get();
        $total = fn (Equipment $e) => $e->value !== null ? round((float) $e->value * (int) $e->quantity, 2) : 0.0;
        $spent = (float) $items->sum($total);
        // A year by month; all time by year.
        $all = $label === 'all time';
        $months = $all
            ? $items->map(fn ($e) => $e->bought_on->startOfYear())->unique()->sort()->values()
            : collect(range(0, 11))->map(fn ($i) => $from->addMonths($i)->startOfMonth())->filter(fn ($m) => $m->lte($to));
        $fmt = $all ? 'Y' : 'Y-m';
        $perMonth = $months->map(fn ($m) => round((float) $items->filter(fn ($e) => $e->bought_on->format($fmt) === $m->format($fmt))->sum($total), 2))->values()->all();
        $byKind = $items->groupBy('category')->map(fn ($list, $k) => [$f->kind($church, $k)[0], $list->count(), round((float) $list->sum($total), 2)])->sortByDesc(2)->values();
        $yes = fn ($v) => $v ? 'Yes' : 'No';

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $label === 'all time' ? 'Everything we bought' : "Bought in {$label}",
            periodLabel: $label === 'all time' ? 'All time' : $from->format('j M Y').' - '.$to->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Spent', 'value' => self::money($spent), 'tone' => 'primary'],
                ['label' => 'Things bought', 'value' => (string) $items->count(), 'tone' => 'purple'],
                ['label' => 'Receipt kept', 'value' => $items->where('receipts_count', '>', 0)->count().' of '.$items->count(), 'tone' => 'success'],
                ['label' => 'In Budgets', 'value' => $items->whereNotNull('budget_entry_id')->count().' of '.$items->count(), 'tone' => 'warning'],
            ],
            meta: ['Year' => $label, 'Prepared by' => $context->preparedBy(), 'Note' => 'By the date each item was bought, at the price recorded on it.'],
            sections: [
                new ReportSection('What we bought', [
                    ReportColumn::text('Bought'), ReportColumn::text('Asset no'), ReportColumn::text('Item', true), ReportColumn::text('Kind'), ReportColumn::number('How many', 'sum'),
                    ReportColumn::money('Price each', null), ReportColumn::money('Total'), ReportColumn::text('Where bought'), ReportColumn::text('Receipt'), ReportColumn::text('In Budgets'),
                ], $items->map(fn (Equipment $e) => [
                    $e->bought_on->format('j M'), $e->asset_no ?: '-', $e->name, $f->kind($church, $e->category)[0], (int) $e->quantity,
                    $e->value !== null ? (float) $e->value : null, $e->value !== null ? $total($e) : null, $e->supplier ?: '-', $yes($e->receipts_count > 0), $yes((bool) $e->budget_entry_id),
                ])->values()->all(), $items->isEmpty() ? "Nothing recorded as bought in {$label}." : null),
                new ReportSection('By kind', [ReportColumn::text('Kind', true), ReportColumn::number('Things', 'sum'), ReportColumn::money('Spent')], $byKind->all(), $byKind->isEmpty() ? 'Nothing yet.' : null),
            ],
            insights: [],
            charts: $spent > 0 ? [ReportChart::bars($all ? 'Spent each year' : 'Spent each month', $months->map(fn ($m) => $m->format($all ? 'Y' : 'M'))->values()->all(), [
                ['name' => 'Spent (KES)', 'tone' => 'primary', 'values' => $perMonth],
            ], 'By the month each item was bought.')] : [],
        );
    }
}
