<?php

namespace App\Reports\Accounting;

use App\Enums\TerritoryType;
use App\Models\Collection;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** Every service's giving in a period: cash, M-Pesa, total, who counted and confirmed it, when it was banked. */
final class CollectionsRegisterReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.collections';
    }

    public function title(): string
    {
        return 'Collections register';
    }

    public function description(): string
    {
        return 'Every service\'s giving in a period: cash and M-Pesa, who counted and who confirmed it, and when the cash was banked.';
    }

    public function icon(): string
    {
        return 'ri-hand-heart-line';
    }

    public function subject(): string
    {
        return 'collections register';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    public function inputs(): array
    {
        return ['dates'];
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->dates($context);
        $list = Collection::with(['counter', 'confirmer', 'bankingJournal'])->where('territory_id', $context->territory->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        $rows = $list->map(fn ($c) => [
            $this->day($c->date->toDateString()), $c->title, (float) $c->cash_total, (float) $c->mpesa_total, (float) $c->total,
            $c->counter?->full_name ?? '-', $c->confirmer?->full_name ?? '-', $c->bankingJournal ? $this->day($c->bankingJournal->date->toDateString()) : ($c->cash_total > 0 ? 'Not yet' : '-'),
        ])->all();

        return new ReportData(
            kicker: $context->kicker('collections register'),
            title: 'Collections register',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Given', 'value' => $this->money($list->sum('total')), 'tone' => 'success'],
                ['label' => 'Cash', 'value' => $this->money($list->sum('cash_total')), 'tone' => 'primary'],
                ['label' => 'M-Pesa', 'value' => $this->money($list->sum('mpesa_total')), 'tone' => 'primary'],
                ['label' => 'Services', 'value' => (string) $list->count(), 'tone' => 'muted'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: [new ReportSection('Every service', [ReportColumn::text('Date'), ReportColumn::text('Service', true), ReportColumn::money('Cash'), ReportColumn::money('M-Pesa'), ReportColumn::money('Total', 'sum', true), ReportColumn::text('Counted by'), ReportColumn::text('Confirmed by'), ReportColumn::text('Banked')], $rows)],
            orientation: 'L',
        );
    }
}
