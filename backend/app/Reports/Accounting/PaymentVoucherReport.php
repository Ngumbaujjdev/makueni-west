<?php

namespace App\Reports\Accounting;

use App\Models\PaymentVoucher;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Accounting\Books;
use App\Services\Accounting\Trail;

/**
 * The payment voucher: pay whom, from which account, what it is charged to,
 * where it stands and who approved it when - with the boxes to sign.
 */
final class PaymentVoucherReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.voucher';
    }

    public function title(): string
    {
        return 'Payment voucher';
    }

    public function description(): string
    {
        return 'One payment voucher on the letterhead: payee, what it pays for, the approvals and the signatures.';
    }

    public function icon(): string
    {
        return 'ri-file-list-3-line';
    }

    public function subject(): string
    {
        return 'payment voucher';
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
        return $this->voucher($c) ? null : 'That voucher isn\'t in these books.';
    }

    private function voucher(ReportContext $c): ?PaymentVoucher
    {
        return PaymentVoucher::where('territory_id', $c->territory->id)->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $pv = $this->voucher($context);
        $v = app(Books::class)->presentVoucher($pv, true);
        $how = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'bank' => 'Bank', 'cheque' => 'Cheque'][$v['method']] ?? null;
        $lines = array_map(fn ($l) => [$l['account']['code'].' '.$l['account']['name'], trim(($l['budget_line'] ?? '').($l['description'] ? ' - '.$l['description'] : ''), ' -'), $l['fund']['name'] ?? '', $l['amount']], $v['lines']);
        $trail = app(Trail::class)->for($pv);
        $events = array_map(fn ($e) => [$this->day(substr($e['at'], 0, 10)), $e['who'] ?? '', $e['text'].($e['note'] ? ' - "'.$e['note'].'"' : '')], array_reverse($trail['events']));

        return new ReportData(
            kicker: $context->kicker('payment voucher'),
            title: "Payment voucher {$v['number']}",
            periodLabel: $this->day($v['date']),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Amount', 'value' => $this->money($v['amount']), 'tone' => 'danger'],
                ['label' => 'Status', 'value' => $v['status_label'], 'tone' => $v['status'] === 'paid' ? 'success' : ($v['status'] === 'rejected' ? 'danger' : 'warning')],
                ['label' => 'Pay from', 'value' => $v['pay_from']['name'] ?? '-', 'tone' => 'primary'],
            ],
            meta: array_filter(['Pay to' => $v['payee_name'], 'Phone' => $v['payee_phone'], 'For' => $v['narration'], 'Paid' => $v['status'] === 'paid' ? trim(($how ?? '').' '.($v['reference'] ?? '').' on '.$this->day($v['paid_on'])) : null, 'In the books' => $v['journal_number']]),
            sections: [
                new ReportSection('What it pays for', [ReportColumn::text('Charged to', true), ReportColumn::text('Budget line'), ReportColumn::text('Fund'), ReportColumn::money('KES')], $lines),
                new ReportSection('Approvals and history', [ReportColumn::text('Date'), ReportColumn::text('Who', true), ReportColumn::text('What')], $events),
            ],
            signatures: [
                ['label' => 'Prepared by', 'name' => $v['prepared_by'] ?? '', 'date' => $v['prepared_at'] ? $this->day(substr($v['prepared_at'], 0, 10)) : null],
                ['label' => 'Authorised by', 'name' => $v['authorised_by'] ?? '', 'date' => $v['authorised_at'] ? $this->day(substr($v['authorised_at'], 0, 10)) : null],
                ['label' => 'Paid by', 'name' => $v['paid_by'] ?? '', 'date' => $v['paid_on'] ? $this->day($v['paid_on']) : null],
                ['label' => 'Payee', 'name' => $v['payee_name']],
            ],
        );
    }
}
