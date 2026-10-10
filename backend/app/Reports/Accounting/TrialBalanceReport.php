<?php

namespace App\Reports\Accounting;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/**
 * Every account's balance as at a date, debits beside credits - one section
 * per kind, the two totals equal. With every place below added in
 * (consolidated), and before or after the year's closing journal (A9).
 */
final class TrialBalanceReport extends StatementReport
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
        return ['dates', 'consolidated', 'before_close'];
    }

    public function group(): ?string
    {
        return null;
    }

    public function build(ReportContext $context): ReportData
    {
        [, $to] = $this->dates($context);
        $before = filter_var($context->param('before_close', false), FILTER_VALIDATE_BOOLEAN);
        $tb = $this->statements()->trialBalance($context->territory, $to, $this->consolidated($context), $before);

        return new ReportData(
            kicker: $context->kicker('trial balance'),
            title: 'Trial balance'.($before ? ' before the year-end close' : ''),
            periodLabel: 'As at '.$this->day($to),
            scopeLabel: $this->scope($context, $tb),
            tiles: [
                ['label' => 'Debits', 'value' => $this->money($tb['debit']), 'tone' => 'primary'],
                ['label' => 'Credits', 'value' => $this->money($tb['credit']), 'tone' => 'primary'],
                ['label' => 'Difference', 'value' => $this->money($tb['debit'] - $tb['credit']), 'tone' => $tb['balanced'] ? 'success' : 'danger'],
                ['label' => 'Accounts', 'value' => (string) count($tb['lines']), 'tone' => 'muted'],
            ],
            meta: ['As at' => $this->day($to), 'Prepared by' => $context->preparedBy()],
            sections: $this->trialBalanceSections($tb),
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Reviewed by']],
        );
    }
}
