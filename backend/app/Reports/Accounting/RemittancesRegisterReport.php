<?php

namespace App\Reports\Accounting;

use App\Models\Remittance;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** Money sent up or down between places in a period - shares, support, paybill settlements - and where each stands. */
final class RemittancesRegisterReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.remittances';
    }

    public function title(): string
    {
        return 'Remittances register';
    }

    public function description(): string
    {
        return 'What this place sent and received between levels in a period - shares, support, settlements - and whether each was confirmed.';
    }

    public function icon(): string
    {
        return 'ri-send-plane-line';
    }

    public function subject(): string
    {
        return 'remittances register';
    }

    public function inputs(): array
    {
        return ['dates'];
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->dates($context);
        $id = $context->territory->id;
        $list = Remittance::with(['from', 'to', 'lines'])->where(fn ($q) => $q->where('from_territory_id', $id)->orWhere('to_territory_id', $id))
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('created_at')->get();
        $row = fn ($r) => [
            $this->day($r->created_at->toDateString()), $r->number, Remittance::KINDS[$r->kind],
            (int) $r->from_territory_id === $id ? ($r->to?->name ?? '-') : ($r->from?->name ?? '-'),
            $r->lines->pluck('month')->implode(', ') ?: '-', Remittance::STATUSES[$r->status], $r->sent_on ? $this->day($r->sent_on->toDateString()) : '-', (float) $r->amount,
        ];
        $cols = fn ($who) => [ReportColumn::text('Raised'), ReportColumn::text('Number', true), ReportColumn::text('Kind'), ReportColumn::text($who), ReportColumn::text('Months'), ReportColumn::text('Status'), ReportColumn::text('Sent on'), ReportColumn::money('KES')];
        $sent = $list->where('from_territory_id', $id);
        $received = $list->where('to_territory_id', $id);

        return new ReportData(
            kicker: $context->kicker('remittances register'),
            title: 'Remittances register',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Sent', 'value' => $this->money($sent->sum('amount')), 'tone' => 'danger'],
                ['label' => 'Received', 'value' => $this->money($received->sum('amount')), 'tone' => 'success'],
                ['label' => 'Not yet confirmed', 'value' => (string) $list->whereIn('status', ['waiting', 'sent', 'queried'])->count(), 'tone' => 'warning'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: [
                new ReportSection('What we sent', $cols('To'), $sent->map($row)->values()->all()),
                new ReportSection('What we received', $cols('From'), $received->map($row)->values()->all()),
            ],
            orientation: 'L',
        );
    }
}
