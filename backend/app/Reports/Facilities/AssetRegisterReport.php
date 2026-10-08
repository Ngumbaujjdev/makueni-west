<?php

namespace App\Reports\Facilities;

use App\Enums\TerritoryType;
use App\Models\Equipment;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Facilities\Facilities;
use App\Support\PeopleAccess;

/**
 * What the church owns - the asset register (docs/specs/people-and-care-spec.md,
 * P5 round 2): every item with its asset number, room, how many, the price
 * each and in all, when and where it was bought, and whether its receipt and
 * its Budgets entry are kept. One section per kind, each with its total.
 */
final class AssetRegisterReport extends Report
{
    public function key(): string
    {
        return 'facilities.assets';
    }

    public function title(): string
    {
        return 'What we own (asset register)';
    }

    public function description(): string
    {
        return 'Everything the church owns: asset number, room, how many, what it cost, when and where it was bought, and whether the receipt and the Budgets entry are kept. A total for each kind.';
    }

    public function module(): string
    {
        return 'facilities';
    }

    public function icon(): string
    {
        return 'ri-archive-line';
    }

    public function subject(): string
    {
        return 'asset register';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    public function inputs(): array
    {
        return [];
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        return PeopleAccess::canNamed($user, $territory, 'facilities', 'export')
            ? null
            : 'Only your own church\'s leaders with the export permission can export what the church owns.';
    }

    public function build(ReportContext $context): ReportData
    {
        $facilities = app(Facilities::class);
        $today = $facilities->today();
        $summary = $facilities->assets($context->territory);
        $items = Equipment::with(['room', 'budgetEntry'])->withCount(['media as receipts_count' => fn ($q) => $q->where('collection_name', 'receipts')])
            ->where('territory_id', $context->territory->id)->orderBy('category')->orderBy('name')->get();
        $yes = fn (bool $v) => $v ? 'Yes' : 'No';
        $sections = $items->groupBy('category')->sortBy(fn ($list, $k) => array_search($k, array_keys(Equipment::CATEGORIES), true))
            ->map(fn ($list, $k) => new ReportSection(Equipment::CATEGORIES[$k][0] ?? 'Other', [
                ReportColumn::text('Asset no'), ReportColumn::text('Item', true), ReportColumn::text('Room'), ReportColumn::number('How many', 'sum'),
                ReportColumn::money('Price each', null), ReportColumn::money('Total'), ReportColumn::text('Bought'), ReportColumn::text('Where bought'),
                ReportColumn::text('Condition'), ReportColumn::text('Receipt'), ReportColumn::text('In Budgets'),
            ], $list->map(fn (Equipment $e) => [
                $e->asset_no ?: '-', $e->name, $e->room?->name ?? '-', (int) $e->quantity,
                $e->value !== null ? (float) $e->value : null, $e->value !== null ? round((float) $e->value * (int) $e->quantity, 2) : null,
                $e->bought_on?->format('j M Y') ?? '-', $e->supplier ?: '-', Equipment::CONDITIONS[$e->condition][0] ?? '-',
                $yes($e->receipts_count > 0), $yes((bool) $e->budget_entry_id),
            ])->values()->all(), null, 'Total '.strtolower(Equipment::CATEGORIES[$k][0] ?? 'other')))->values()->all();
        $money = fn (float $v) => 'KES '.number_format($v, 0);

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'What we own',
            periodLabel: 'As at '.$today->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'What it is worth', 'value' => $money($summary['worth']), 'tone' => 'primary'],
                ['label' => 'Items', 'value' => number_format($summary['items']).' ('.$summary['kinds'].' kinds)', 'tone' => 'purple'],
                ['label' => 'Recorded in Budgets', 'value' => $summary['in_budgets'].' of '.$summary['kinds'], 'tone' => 'success'],
                ['label' => 'Spent on repairs', 'value' => $money($summary['repairs_spent']), 'tone' => 'warning'],
            ],
            meta: [
                'As at' => $today->format('j M Y'), 'Prepared by' => $context->preparedBy(),
                'With a receipt' => $summary['with_receipt'].' of '.$summary['kinds'],
                'Without a price' => (string) ($summary['kinds'] - $summary['priced']),
                'Note' => 'Worth is what was paid (price each x how many), not what it would sell for today.',
            ],
            sections: $sections ?: [new ReportSection('What we own', [ReportColumn::text('Item')], [], 'Nothing recorded yet.')],
            insights: [],
            charts: array_values(array_filter([
                $summary['by_kind'] ? ReportChart::hbars('Worth by kind', array_column($summary['by_kind'], 'label'), [
                    ['name' => 'Worth (KES)', 'tone' => 'primary', 'values' => array_column($summary['by_kind'], 'worth')],
                ]) : null,
                $summary['by_year'] ? ReportChart::bars('Spent on things each year', array_map('strval', array_column($summary['by_year'], 'year')), [
                    ['name' => 'Spent (KES)', 'tone' => 'success', 'values' => array_column($summary['by_year'], 'spent')],
                ], 'By the year each item was bought.') : null,
            ])),
        );
    }
}
