<?php

namespace App\Reports\Accounting;

use App\Models\AccountingAccount;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Accounting\Books;

/**
 * The cashbook of one money account for a date range, as a book: a cover
 * with the church's logo, then the balance brought forward, every movement
 * with its running balance, and the balance carried forward.
 */
final class CashbookReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.cashbook';
    }

    public function title(): string
    {
        return 'Cashbook';
    }

    public function description(): string
    {
        return 'One account\'s money in and out for a period - brought forward, every movement with its running balance, carried forward - with a cover page.';
    }

    public function icon(): string
    {
        return 'ri-book-open-line';
    }

    public function subject(): string
    {
        return 'cashbook';
    }

    public function inputs(): array
    {
        return ['account', 'dates'];
    }

    public function checkParams(ReportContext $c): ?string
    {
        return $this->account($c) ? null : 'Choose one of this place\'s cash, bank or M-Pesa accounts.';
    }

    private function account(ReportContext $c): ?AccountingAccount
    {
        return AccountingAccount::usableBy($c->territory->id)->whereNotNull('cash_kind')->where('is_header', false)->find((int) $c->param('account_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->dates($context);
        $account = $this->account($context);
        $book = app(Books::class)->cashbook($context->territory, $account, $from, $to);
        $rows = [[$this->day($from), '', 'Balance brought forward', null, null, $book['opening']]];
        foreach ($book['rows'] as $r) {
            $rows[] = [
                $this->day($r['date']),
                preg_replace('#^[^/]+/#', '', $r['number']).($r['reversed'] ? ' (reversed)' : ''), // the place is on every page already
                $this->details($r),
                $r['in'] ?: null,
                $r['out'] ?: null,
                $r['balance'],
            ];
        }
        $rows[] = [$this->day($to), '', 'Balance carried forward', $book['in'], $book['out'], $book['closing']];
        $number = $account->bank_name ? trim("{$account->bank_name} ".($account->account_number ?? '')) : ($account->mpesa_number ?: $account->name);

        return new ReportData(
            kicker: $context->kicker('cashbook'),
            title: "Cashbook - {$account->name}",
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Brought forward', 'value' => $this->money($book['opening']), 'tone' => 'primary'],
                ['label' => 'Money in', 'value' => $this->money($book['in']), 'tone' => 'success'],
                ['label' => 'Money out', 'value' => $this->money($book['out']), 'tone' => 'danger'],
                ['label' => 'Carried forward', 'value' => $this->money($book['closing']), 'tone' => 'primary'],
            ],
            meta: ['Account' => "{$account->code} {$account->name}", 'Movements' => (string) count($book['rows']), 'Prepared by' => $context->preparedBy(), 'Printed' => now()->format('j M Y')],
            sections: [new ReportSection('Every movement', [
                ReportColumn::text('Date'),
                new ReportColumn('Document', 'C', null, true), // fixed width: only Details gives way on a long line
                ReportColumn::text('Details'),
                ReportColumn::money('In', null),
                ReportColumn::money('Out', null),
                ReportColumn::money('Balance', null, true),
            ], $rows)],
            cover: [
                'title' => 'Cashbook',
                'subtitle' => $account->name,
                'lines' => ['Account' => "{$account->code} · {$number}", 'Period' => $this->rangeLabel($from, $to), 'Carried forward' => $this->money($book['closing']), 'Prepared by' => $context->preparedBy(), 'Printed' => now()->format('j M Y')],
                'logo_path' => $this->placeLogo($context->territory),
            ],
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Checked by'], ['label' => 'Approved by']],
            orientation: 'L',
        );
    }

    /** Who and what, without saying it twice ("Sunday service - Sunday service - 6 Sep"). */
    private function details(array $r): string
    {
        $party = (string) ($r['party'] ?? '');
        $note = (string) ($r['details'] ?? '');
        $text = $party !== '' && ! str_starts_with($note, $party) ? trim("{$party} - {$note}", ' -') : ($note ?: $party);
        if ($r['against']) {
            $text .= ' ('.implode(', ', $r['against']).')';
        }

        return $text.($r['reference'] ? ' · '.$r['reference'] : '');
    }
}
