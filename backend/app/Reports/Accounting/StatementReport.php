<?php

namespace App\Reports\Accounting;

use App\Models\AccountingAccount;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportSection;
use App\Services\Accounting\Statements;
use App\Support\AccountingAccess;
use Carbon\CarbonImmutable;

/**
 * What the financial statements share (docs/specs/accounting-spec.md, A9):
 * the year to date unless a range is asked for, "with every place below"
 * (consolidated) for whoever reads the books below, and the tables of each
 * statement - so the audit pack is the same tables put together.
 */
abstract class StatementReport extends AccountingReport
{
    public function inputs(): array
    {
        return ['dates', 'consolidated'];
    }

    public function group(): ?string
    {
        return 'statements';
    }

    public function checkParams(ReportContext $context): ?string
    {
        return $this->consolidated($context) && ! AccountingAccess::canConsolidate($context->user, $context->territory)
            ? 'Adding in the places below needs you to read their books.' : null;
    }

    protected function statements(): Statements
    {
        return app(Statements::class);
    }

    protected function consolidated(ReportContext $c): bool
    {
        return filter_var($c->param('consolidated', false), FILTER_VALIDATE_BOOLEAN) && $c->territory->territory_type->value !== 'church';
    }

    /** @return array{0: string, 1: string} the range asked for, or 1 January to today */
    protected function yearDates(ReportContext $c): array
    {
        $to = $c->param('date_to') ?: CarbonImmutable::today()->toDateString();
        $from = $c->param('date_from') ?: CarbonImmutable::parse($to)->startOfYear()->toDateString();

        return [$from, $to];
    }

    protected function scope(ReportContext $c, array $s): string
    {
        return $s['consolidated'] ? "{$c->territory->name} and the ".($s['places'] - 1).' places below it' : $c->territory->name;
    }

    // ------------------------------------------------------------ the tables

    /** @return ReportSection[] */
    protected function ieSections(array $s): array
    {
        $funds = count($s['funds']) > 1 ? $s['funds'] : [];
        $columns = fn (string $total) => [ReportColumn::text('Code'), ReportColumn::text('Account', true),
            ...array_map(fn ($f) => ReportColumn::money($f['name']), $funds), ReportColumn::money($total, 'sum', true), ReportColumn::money('Last year')];
        $rows = fn (array $lines) => array_map(fn ($l) => [$l['code'], $l['name'], ...array_map(fn ($f) => $l['by_fund'][$f['fund_id']] ?? null, $funds), $l['amount'], $l['last_year'] ?: null], $lines);
        $t = $s['totals'];
        $out = [
            new ReportSection('Income', $columns('Total'), $rows($s['income']), $s['income'] ? null : 'No income in this period.', 'Total income'),
            new ReportSection('Expenditure', $columns('Total'), $rows($s['expense']), $s['expense'] ? null : 'No spending in this period.', 'Total expenditure'),
            new ReportSection('Surplus', [ReportColumn::text(''), ReportColumn::text('', true), ...array_map(fn ($f) => ReportColumn::money($f['name'], null), $funds), ReportColumn::money('Total', null, true), ReportColumn::money('Last year', null)], [
                ['', $t['surplus'] >= 0 ? 'Surplus for the period' : 'Deficit for the period', ...array_map(fn ($f) => $t['by_fund'][$f['fund_id']]['surplus'] ?? 0, $funds), $t['surplus'], $t['last_year']['surplus']],
            ]),
        ];
        if ($s['eliminated']) {
            $out[] = new ReportSection('Taken out between places', [ReportColumn::text('Code'), ReportColumn::text('Account', true), ReportColumn::money('Debit'), ReportColumn::money('Credit')],
                array_map(fn ($e) => [$e['code'], $e['name'], $e['debit'] ?: null, $e['credit'] ?: null], $s['eliminated']),
                'Shares, support and paybill money that moved between the places added together here - counted once, not twice.');
        }

        return $out;
    }

    /** @return ReportSection[] */
    protected function positionSections(array $s): array
    {
        $cols = fn (string $total) => [ReportColumn::text('Code'), ReportColumn::text('', true), ReportColumn::money($total, 'sum', true), ReportColumn::money('A year earlier')];
        $rows = fn (array $lines) => array_map(fn ($l) => [$l['code'], $l['name'], $l['amount'], $l['last_year'] ?: null], $lines);
        $t = $s['totals'];

        return [
            new ReportSection('What we own', $cols('Amount'), $rows($s['assets']), null, 'Total assets'),
            new ReportSection('What we owe', $cols('Amount'), $rows($s['liabilities']), $s['liabilities'] ? null : 'Nothing owed.', 'Total liabilities'),
            new ReportSection('Net assets', [ReportColumn::text(''), ReportColumn::text('', true), ReportColumn::money('Amount', null, true), ReportColumn::money('A year earlier', null)],
                [['', 'What we own less what we owe', $t['net_assets'], $t['last_year']['net_assets']]]),
            new ReportSection('Held in the funds', [ReportColumn::text('Fund'), ReportColumn::text('', true), ReportColumn::money('Amount', 'sum', true), ReportColumn::money('A year earlier')],
                array_map(fn ($f) => [$f['code'], $f['name'].($f['restricted'] ? ' (restricted)' : ''), $f['amount'], $f['last_year'] ?: null], $s['funds']),
                $t['balanced'] ? null : 'The funds don\'t equal the net assets - check the journals.', 'Total funds'),
        ];
    }

    /** @return ReportSection[] */
    protected function receiptsPaymentsSections(array $s): array
    {
        $cols = fn (string $h) => [ReportColumn::text('Code'), ReportColumn::text($h, true), ReportColumn::money('Amount')];
        $rows = fn (array $lines) => array_map(fn ($l) => [$l['code'], $l['name'], $l['amount']], $lines);
        $t = $s['totals'];

        return [
            new ReportSection('Cash and bank at the start', $cols('Account'), $rows($s['opening']), $s['opening'] ? null : 'Nothing at the start.', 'Opening balance'),
            new ReportSection('Receipts', $cols('Received for'), $rows($s['receipts']), $s['receipts'] ? null : 'Nothing received.', 'Total receipts'),
            new ReportSection('Payments', $cols('Paid for'), $rows($s['payments']), $s['payments'] ? null : 'Nothing paid.', 'Total payments'),
            new ReportSection('Cash and bank at the end', $cols('Account'), $rows($s['closing']), $t['balanced'] ? null : 'Opening + receipts - payments doesn\'t equal the closing balance - check the journals.', 'Closing balance'),
        ];
    }

    /** @return ReportSection[] */
    protected function fundsSections(array $s): array
    {
        return [new ReportSection('Changes in funds', [
            ReportColumn::text('Fund', true), ReportColumn::money('At the start'), ReportColumn::money('Income'), ReportColumn::money('Spending'),
            ReportColumn::money('Transfers & opening entries'), ReportColumn::money('At the end', 'sum', true),
        ], array_map(fn ($f) => [$f['name'].($f['restricted'] ? ' (restricted)' : ''), $f['opening'], $f['income'], $f['expense'], $f['transfers'] ?: null, $f['closing']], $s['funds']),
            'Restricted funds are kept for their purpose only.', 'All funds')];
    }

    /** @return ReportSection[] */
    protected function trialBalanceSections(array $tb): array
    {
        $sections = [];
        foreach (AccountingAccount::TYPES as $type => $label) {
            $rows = array_values(array_map(fn ($l) => [$l['code'], $l['name'], $l['debit'] ?: null, $l['credit'] ?: null], array_filter($tb['lines'], fn ($l) => $l['type'] === $type)));
            if ($rows) {
                $sections[] = new ReportSection($label, [ReportColumn::text('Code'), ReportColumn::text('Account', true), ReportColumn::money('Debit'), ReportColumn::money('Credit')], $rows);
            }
        }
        $sections[] = new ReportSection('Totals', [ReportColumn::text(''), ReportColumn::text('All accounts', true), ReportColumn::money('Debit', null, true), ReportColumn::money('Credit', null, true)],
            [['All', $tb['balanced'] ? 'The books balance' : 'NOT BALANCED - check the journals', $tb['debit'], $tb['credit']]]);

        return $sections;
    }
}
