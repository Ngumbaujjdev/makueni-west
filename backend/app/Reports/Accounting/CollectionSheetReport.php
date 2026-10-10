<?php

namespace App\Reports\Accounting;

use App\Enums\TerritoryType;
use App\Models\CashCount;
use App\Models\Collection;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** A service's collection sheet: what was given by kind (cash, M-Pesa), the notes and coins counted, the witnesses. */
final class CollectionSheetReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.collection';
    }

    public function title(): string
    {
        return 'Collection sheet';
    }

    public function description(): string
    {
        return 'One service\'s giving: each kind in cash and M-Pesa, the notes and coins counted, the witnesses, and the signatures.';
    }

    public function icon(): string
    {
        return 'ri-hand-coin-line';
    }

    public function subject(): string
    {
        return 'collection sheet';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    public function lockedOnly(): bool
    {
        return true;
    }

    public function inputs(): array
    {
        return ['record'];
    }

    public function checkParams(ReportContext $c): ?string
    {
        return $this->collection($c) ? null : 'That collection isn\'t in these books.';
    }

    private function collection(ReportContext $c): ?Collection
    {
        return Collection::with(['lines.fund', 'counter', 'confirmer', 'journal', 'bankingJournal'])->where('territory_id', $c->territory->id)->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $c = $this->collection($context);
        $rows = $c->lines->map(fn ($l) => [$l->label, $l->fund?->name ?? '', (float) $l->cash_amount, (float) $l->mpesa_amount, (float) $l->cash_amount + (float) $l->mpesa_amount])->all();
        $sections = [new ReportSection('Given', [ReportColumn::text('Kind', true), ReportColumn::text('Fund'), ReportColumn::money('Cash'), ReportColumn::money('M-Pesa'), ReportColumn::money('Total', 'sum', true)], $rows)];
        $den = (array) ($c->denominations ?? []);
        if ($den) {
            $count = [];
            foreach (CashCount::DENOMINATIONS as $d) {
                if (! empty($den[$d])) {
                    $count[] = ['KES '.number_format((float) $d), (int) $den[$d], (int) $den[$d] * (float) $d];
                }
            }
            $sections[] = new ReportSection('Cash counted', [ReportColumn::text('Note / coin', true), ReportColumn::number('How many', 'sum'), ReportColumn::money('KES')], $count);
        }

        return new ReportData(
            kicker: $context->kicker('collection sheet'),
            title: $c->title.' - '.$this->day($c->date->toDateString()),
            periodLabel: $this->day($c->date->toDateString()),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Total given', 'value' => $this->money($c->total), 'tone' => 'success'],
                ['label' => 'Cash', 'value' => $this->money($c->cash_total), 'tone' => 'primary'],
                ['label' => 'M-Pesa', 'value' => $this->money($c->mpesa_total), 'tone' => 'primary'],
            ],
            meta: array_filter(['Status' => Collection::STATUSES[$c->status], 'Receipt' => $c->journal?->number, 'Banked' => $c->bankingJournal ? $c->bankingJournal->number.' on '.$this->day($c->bankingJournal->date->toDateString()) : null, 'Witnesses' => implode(', ', (array) ($c->witnesses ?? [])) ?: null, 'Notes' => $c->notes]),
            sections: $sections,
            signatures: [['label' => 'Counted by', 'name' => $c->counter?->full_name ?? ''], ['label' => 'Confirmed by', 'name' => $c->confirmer?->full_name ?? '', 'date' => $c->confirmed_at ? $this->day($c->confirmed_at->toDateString()) : null], ['label' => 'Witness']],
        );
    }
}
