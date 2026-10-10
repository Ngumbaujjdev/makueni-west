<?php

namespace App\Reports\Accounting;

use App\Models\AccountingAccount;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Accounting\Ledger;

/** Every account's balance as at a date, debits beside credits - one section per kind, the two totals equal. */
final class TrialBalanceReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.trial_balance';
    }

    public function title(): string
    {
        return 'Trial balance';
    }

    public function description(): string
    {
        return 'Every account\'s balance as at a date, debits beside credits, by kind - the proof the books balance.';
    }

    public function icon(): string
    {
        return 'ri-scales-3-line';
    }

    public function subject(): string
    {
        return 'trial balance';
    }

    public function inputs(): array
    {
        return ['dates'];
    }

    public function build(ReportContext $context): ReportData
    {
        [, $to] = $this->dates($context);
        $tb = app(Ledger::class)->trialBalance($context->territory, $to);
        $sections = [];
        foreach (AccountingAccount::TYPES as $type => $label) {
            $rows = array_values(array_map(fn ($l) => [$l['code'], $l['name'], $l['debit'] ?: null, $l['credit'] ?: null], array_filter($tb['lines'], fn ($l) => $l['type'] === $type)));
            if ($rows) {
                $sections[] = new ReportSection($label, [ReportColumn::text('Code'), ReportColumn::text('Account', true), ReportColumn::money('Debit'), ReportColumn::money('Credit')], $rows);
            }
        }
        $sections[] = new ReportSection('Totals', [ReportColumn::text(''), ReportColumn::text('All accounts', true), ReportColumn::money('Debit', null, true), ReportColumn::money('Credit', null, true)], [['All', $tb['balanced'] ? 'The books balance' : 'NOT BALANCED - check the journals', $tb['debit'], $tb['credit']]]);

        return new ReportData(
            kicker: $context->kicker('trial balance'),
            title: 'Trial balance',
            periodLabel: 'As at '.$this->day($to),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Debits', 'value' => $this->money($tb['debit']), 'tone' => 'primary'],
                ['label' => 'Credits', 'value' => $this->money($tb['credit']), 'tone' => 'primary'],
                ['label' => 'Difference', 'value' => $this->money($tb['debit'] - $tb['credit']), 'tone' => $tb['balanced'] ? 'success' : 'danger'],
                ['label' => 'Accounts', 'value' => (string) count($tb['lines']), 'tone' => 'muted'],
            ],
            meta: ['As at' => $this->day($to), 'Prepared by' => $context->preparedBy()],
            sections: $sections,
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Reviewed by']],
        );
    }
}
