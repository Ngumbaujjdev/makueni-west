<?php

namespace App\Reports\Accounting;

use App\Models\Journal;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** Every receipt written in a period: who gave, how, into which account - totalled by how it came. */
final class ReceiptsRegisterReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.receipts';
    }

    public function title(): string
    {
        return 'Receipts register';
    }

    public function description(): string
    {
        return 'Every receipt in a period: number, date, received from, how and into which account, with totals by how the money came.';
    }

    public function icon(): string
    {
        return 'ri-bill-line';
    }

    public function subject(): string
    {
        return 'receipts register';
    }

    public function inputs(): array
    {
        return ['dates'];
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->dates($context);
        $list = Journal::with(['lines.account:id,name,cash_kind'])->where('territory_id', $context->territory->id)->where('doc_type', 'receipt')
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        $rows = $list->map(fn ($j) => [
            $this->day($j->date->toDateString()),
            $j->number.($j->status === 'reversed' ? ' (reversed)' : ''),
            $j->party_name ?: '-',
            Journal::METHODS[$j->method] ?? '-',
            $j->lines->filter(fn ($l) => $l->debit > 0 && $l->account?->cash_kind)->map(fn ($l) => $l->account->name)->unique()->implode(', ') ?: '-',
            $j->status === 'reversed' ? 0 : (float) $j->amount,
        ])->all();
        $live = $list->where('status', '!=', 'reversed');
        $byMethod = $live->groupBy(fn ($j) => Journal::METHODS[$j->method] ?? 'Other')->map(fn ($g, $k) => [$k, $g->count(), round($g->sum('amount'), 2)])->values()->all();

        return new ReportData(
            kicker: $context->kicker('receipts register'),
            title: 'Receipts register',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Received', 'value' => $this->money($live->sum('amount')), 'tone' => 'success'],
                ['label' => 'Receipts', 'value' => (string) $live->count(), 'tone' => 'primary'],
                ['label' => 'Reversed', 'value' => (string) ($list->count() - $live->count()), 'tone' => 'muted'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: [
                new ReportSection('By how it came', [ReportColumn::text('How', true), ReportColumn::number('Receipts', 'sum'), ReportColumn::money('KES')], $byMethod),
                new ReportSection('Every receipt', [ReportColumn::text('Date'), ReportColumn::text('Receipt', true), ReportColumn::text('Received from'), ReportColumn::text('How'), ReportColumn::text('Into'), ReportColumn::money('KES')], $rows),
            ],
            orientation: 'L',
        );
    }
}
