<?php

namespace App\Reports\Accounting;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/** Each fund from the start of a period to its end: income, spending and transfers. */
final class ChangesInFundsReport extends StatementReport
{
    public function key(): string
    {
        return 'accounting.statement.funds';
    }

    public function title(): string
    {
        return 'Changes in funds';
    }

    public function description(): string
    {
        return 'Each fund - General, Building and the others - at the start, what came in, what was spent, transfers, and at the end.';
    }

    public function icon(): string
    {
        return 'ri-safe-2-line';
    }

    public function subject(): string
    {
        return 'statement of changes in funds';
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->yearDates($context);
        $s = $this->statements()->changesInFunds($context->territory, $from, $to, $this->consolidated($context));
        $t = $s['totals'];

        return new ReportData(
            kicker: $context->kicker('statement of changes in funds'),
            title: 'Changes in funds',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $this->scope($context, $s),
            tiles: [
                ['label' => 'At the start', 'value' => $this->money($t['opening']), 'tone' => 'muted'],
                ['label' => $t['surplus'] >= 0 ? 'Surplus' : 'Deficit', 'value' => $this->money($t['surplus']), 'tone' => $t['surplus'] >= 0 ? 'success' : 'danger'],
                ['label' => 'Transfers', 'value' => $this->money($t['transfers']), 'tone' => 'warning'],
                ['label' => 'At the end', 'value' => $this->money($t['closing']), 'tone' => 'primary'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Prepared by' => $context->preparedBy()],
            sections: $this->fundsSections($s),
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Approved by']],
        );
    }
}
