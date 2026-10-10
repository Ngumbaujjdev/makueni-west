<?php

namespace App\Reports\Accounting;

use App\Models\Remittance;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** The advice that goes with money sent between places: what for, which months, how much, how it was sent. */
final class RemittanceAdviceReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.remittance';
    }

    public function title(): string
    {
        return 'Remittance advice';
    }

    public function description(): string
    {
        return 'What was sent between two places: the purpose, the months and amounts, how and when it went, and who confirmed it.';
    }

    public function icon(): string
    {
        return 'ri-send-plane-line';
    }

    public function subject(): string
    {
        return 'remittance advice';
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
        return $this->remittance($c) ? null : 'That remittance isn\'t in these books.';
    }

    private function remittance(ReportContext $c): ?Remittance
    {
        $id = $c->territory->id;

        return Remittance::with(['from', 'to', 'lines', 'voucher', 'confirmer'])->where(fn ($q) => $q->where('from_territory_id', $id)->orWhere('to_territory_id', $id))->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $r = $this->remittance($context);
        $month = fn ($ym) => preg_match('/^\d{4}-\d{2}$/', (string) $ym) ? \Carbon\CarbonImmutable::parse("{$ym}-01")->format('F Y') : (string) $ym;
        $rows = $r->lines->map(fn ($l) => [$month($l->month), (float) $l->due, (float) $l->amount])->all();
        $how = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'bank' => 'Bank', 'cheque' => 'Cheque'][$r->method] ?? ($r->method ?: '-');

        return new ReportData(
            kicker: $context->kicker('remittance advice'),
            title: "Remittance {$r->number}",
            periodLabel: Remittance::KINDS[$r->kind],
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Amount', 'value' => $this->money($r->amount), 'tone' => 'primary'],
                ['label' => 'Status', 'value' => Remittance::STATUSES[$r->status], 'tone' => $r->status === 'confirmed' ? 'success' : 'warning'],
                ['label' => 'Sent on', 'value' => $r->sent_on ? $this->day($r->sent_on->toDateString()) : 'Not yet', 'tone' => 'muted'],
            ],
            meta: array_filter(['From' => $r->from?->name, 'To' => $r->to?->name, 'For' => $r->purpose, 'How' => $how.($r->reference ? " · {$r->reference}" : ''), 'Voucher' => $r->voucher?->number, 'Confirmed by' => $r->confirmer?->full_name]),
            sections: [new ReportSection('Months', [ReportColumn::text('Month', true), ReportColumn::money('Due'), ReportColumn::money('Sent')], $rows)],
            signatures: [['label' => 'Sent by'], ['label' => 'Received and confirmed by', 'name' => $r->confirmer?->full_name ?? '', 'date' => $r->confirmed_at ? $this->day($r->confirmed_at->toDateString()) : null]],
        );
    }
}
