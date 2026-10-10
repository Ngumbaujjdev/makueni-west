<?php

namespace App\Reports\Accounting;

use App\Models\PaymentVoucher;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** Every payment voucher dated in a period: payee, what for, where it stands, who authorised and when it was paid. */
final class VouchersRegisterReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.vouchers';
    }

    public function title(): string
    {
        return 'Payment vouchers register';
    }

    public function description(): string
    {
        return 'Every payment voucher in a period: payee, what for, status, who authorised it and when it was paid, with totals by status.';
    }

    public function icon(): string
    {
        return 'ri-file-list-3-line';
    }

    public function subject(): string
    {
        return 'payment vouchers register';
    }

    public function inputs(): array
    {
        return ['dates'];
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->dates($context);
        $list = PaymentVoucher::with(['authoriser', 'preparer'])->where('territory_id', $context->territory->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        $rows = $list->map(fn ($v) => [
            $this->day($v->date->toDateString()), $v->number, $v->payee_name, $v->narration, PaymentVoucher::STATUSES[$v->status],
            $v->authoriser?->full_name ?? '-', $v->paid_on ? $this->day($v->paid_on->toDateString()) : '-', (float) $v->amount,
        ])->all();
        $byStatus = $list->groupBy('status')->map(fn ($g, $s) => [PaymentVoucher::STATUSES[$s], $g->count(), round($g->sum('amount'), 2)])->values()->all();
        $paid = $list->where('status', 'paid');

        return new ReportData(
            kicker: $context->kicker('payment vouchers register'),
            title: 'Payment vouchers register',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Paid', 'value' => $this->money($paid->sum('amount')), 'tone' => 'danger'],
                ['label' => 'Vouchers', 'value' => (string) $list->count(), 'tone' => 'primary'],
                ['label' => 'Waiting', 'value' => (string) $list->whereIn('status', ['prepared', 'authorised'])->count(), 'tone' => 'warning'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: [
                new ReportSection('By status', [ReportColumn::text('Status', true), ReportColumn::number('Vouchers', 'sum'), ReportColumn::money('KES')], $byStatus),
                new ReportSection('Every voucher', [ReportColumn::text('Date'), ReportColumn::text('Voucher', true), ReportColumn::text('Pay to'), ReportColumn::text('For'), ReportColumn::text('Status'), ReportColumn::text('Authorised by'), ReportColumn::text('Paid on'), ReportColumn::money('KES')], $rows),
            ],
            orientation: 'L',
        );
    }
}
