<?php

namespace App\Reports\Accounting;

use App\Models\Journal;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Accounting\Books;

/** The official receipt: who gave, how, for what (and into which fund), the total - and who received it. */
final class ReceiptReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.receipt';
    }

    public function title(): string
    {
        return 'Official receipt';
    }

    public function description(): string
    {
        return 'One receipt on the letterhead: received from, how, what for and the total, with a place to sign.';
    }

    public function icon(): string
    {
        return 'ri-bill-line';
    }

    public function subject(): string
    {
        return 'official receipt';
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
        return $this->journal($c) ? null : 'That receipt isn\'t in these books.';
    }

    private function journal(ReportContext $c): ?Journal
    {
        return Journal::where('territory_id', $c->territory->id)->where('doc_type', 'receipt')->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $j = app(Books::class)->presentJournal($this->journal($context), true);
        $rows = array_values(array_map(fn ($l) => [$l['account']['name'].($l['memo'] ? ' - '.$l['memo'] : ''), $l['fund']['name'] ?? '', $l['credit']], array_filter($j['lines'], fn ($l) => $l['credit'] > 0)));
        $reversed = $j['status'] === 'reversed';

        return new ReportData(
            kicker: $reversed ? 'REVERSED - no longer valid' : $context->kicker('official receipt'),
            title: "Receipt {$j['number']}",
            periodLabel: $this->day($j['date']),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Amount received', 'value' => $this->money($j['amount']), 'tone' => $reversed ? 'danger' : 'success'],
                ['label' => 'Date', 'value' => $this->day($j['date']), 'tone' => 'primary'],
                ['label' => 'Paid by', 'value' => $j['method_label'] ?? '-', 'tone' => 'primary'],
            ],
            meta: array_filter(['Received from' => $j['party_name'] ?: '-', 'Phone' => $j['party_phone'], 'Reference' => $j['reference'] ?: '-', 'Note' => $j['narration'], 'Recorded by' => $j['posted_by'] ?: '-']),
            sections: [new ReportSection('Received for', [ReportColumn::text('For', true), ReportColumn::text('Fund'), ReportColumn::money('KES')], $rows)],
            signatures: [['label' => 'Received by', 'name' => $j['posted_by'] ?? ''], ['label' => 'Given by', 'name' => $j['party_name'] ?? '']],
        );
    }
}
