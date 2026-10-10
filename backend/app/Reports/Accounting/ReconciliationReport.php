<?php

namespace App\Reports\Accounting;

use App\Models\BankReconciliation;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Accounting\Reconciliations;

/**
 * The bank (or M-Pesa) reconciliation statement: the statement's balance,
 * plus deposits in transit, less unpresented payments, against the books -
 * and the items still open.
 */
final class ReconciliationReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.reconciliation';
    }

    public function title(): string
    {
        return 'Bank reconciliation statement';
    }

    public function description(): string
    {
        return 'The statement balance, deposits in transit and unpresented payments against the book balance, the items still open, and sign-off.';
    }

    public function icon(): string
    {
        return 'ri-bank-line';
    }

    public function subject(): string
    {
        return 'bank reconciliation';
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
        return $this->rec($c) ? null : 'That reconciliation isn\'t in these books.';
    }

    private function rec(ReportContext $c): ?BankReconciliation
    {
        return BankReconciliation::where('territory_id', $c->territory->id)->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $r = app(Reconciliations::class)->present($this->rec($context));
        $open = array_values(array_filter($r['book'], fn ($l) => ! $l['cleared']));
        $row = fn ($l) => [$this->day($l['date']), $l['number'], trim(($l['party'] ?? '').' '.($l['details'] ?? '')), $l['in'] ?: null, $l['out'] ?: null];
        $cols = [ReportColumn::text('Date'), ReportColumn::text('Document', true), ReportColumn::text('Details'), ReportColumn::money('In'), ReportColumn::money('Out')];

        return new ReportData(
            kicker: $context->kicker('bank reconciliation'),
            title: 'Reconciliation - '.$r['account']['name'],
            periodLabel: 'Statement at '.$this->day($r['statement_date']),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Statement balance', 'value' => $this->money($r['statement_balance']), 'tone' => 'primary'],
                ['label' => 'Book balance', 'value' => $this->money($r['book_balance']), 'tone' => 'primary'],
                ['label' => 'Difference', 'value' => $this->money($r['difference']), 'tone' => abs($r['difference']) < 0.005 ? 'success' : 'danger'],
            ],
            meta: array_filter(['Account' => $r['account']['code'].' '.$r['account']['name'], 'Status' => $r['status_label'], 'Notes' => $r['notes']]),
            sections: [
                new ReportSection('The statement worked out', [ReportColumn::text('', true), ReportColumn::money('KES', null)], [
                    ['Balance per statement', $r['statement_balance']],
                    ['Add: deposits in transit', $r['in_transit']],
                    ['Less: unpresented payments', -1 * $r['unpresented']],
                    ['Adjusted statement balance', round($r['statement_balance'] + $r['in_transit'] - $r['unpresented'], 2)],
                    ['Balance per the books', $r['book_balance']],
                    ['Difference', $r['difference']],
                ]),
                new ReportSection('Items still open', $cols, array_map($row, $open), $open ? null : 'Every item in the books is on the statement.'),
            ],
            signatures: [
                ['label' => 'Prepared by', 'name' => $r['prepared_by'] ?? '', 'date' => $r['prepared_at'] ? $this->day(substr($r['prepared_at'], 0, 10)) : null],
                ['label' => 'Approved by', 'name' => $r['approved_by'] ?? '', 'date' => $r['approved_at'] ? $this->day(substr($r['approved_at'], 0, 10)) : null],
            ],
        );
    }
}
