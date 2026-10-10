<?php

namespace App\Reports\Accounting;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/** The cash in and out for a period: cash and bank at the start, receipts, payments, and at the end. */
final class ReceiptsPaymentsReport extends StatementReport
{
    public function key(): string
    {
        return 'accounting.statement.receipts-payments';
    }

    public function title(): string
    {
        return 'Receipts and payments';
    }

    public function description(): string
    {
        return 'The cash, bank and M-Pesa at the start, every shilling received and paid by what it was for, and what was left at the end.';
    }

    public function icon(): string
    {
        return 'ri-exchange-funds-line';
    }

    public function subject(): string
    {
        return 'receipts and payments';
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->yearDates($context);
        $s = $this->statements()->receiptsPayments($context->territory, $from, $to, $this->consolidated($context));
        $t = $s['totals'];

        return new ReportData(
            kicker: $context->kicker('receipts and payments'),
            title: 'Receipts and payments',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $this->scope($context, $s),
            tiles: [
                ['label' => 'At the start', 'value' => $this->money($t['opening']), 'tone' => 'muted'],
                ['label' => 'Received', 'value' => $this->money($t['receipts']), 'tone' => 'success'],
                ['label' => 'Paid', 'value' => $this->money($t['payments']), 'tone' => 'danger'],
                ['label' => 'At the end', 'value' => $this->money($t['closing']), 'tone' => 'primary'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: $this->receiptsPaymentsSections($s),
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Approved by']],
        );
    }
}
