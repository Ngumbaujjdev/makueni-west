<?php

namespace App\Reports\Accounting;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/** Income and expenditure for a period, a column per fund, beside the same period last year. */
final class IncomeExpenditureReport extends StatementReport
{
    public function key(): string
    {
        return 'accounting.statement.ie';
    }

    public function title(): string
    {
        return 'Income and expenditure';
    }

    public function description(): string
    {
        return 'What came in and what was spent, by account and fund, beside the same period last year - the surplus or deficit.';
    }

    public function icon(): string
    {
        return 'ri-line-chart-line';
    }

    public function subject(): string
    {
        return 'income and expenditure';
    }

    public function build(ReportContext $context): ReportData
    {
        [$from, $to] = $this->yearDates($context);
        $s = $this->statements()->incomeExpenditure($context->territory, $from, $to, $this->consolidated($context));
        $t = $s['totals'];

        return new ReportData(
            kicker: $context->kicker('income and expenditure'),
            title: 'Income and expenditure',
            periodLabel: $this->rangeLabel($from, $to),
            scopeLabel: $this->scope($context, $s),
            tiles: [
                ['label' => 'Income', 'value' => $this->money($t['income']), 'tone' => 'success'],
                ['label' => 'Expenditure', 'value' => $this->money($t['expense']), 'tone' => 'danger'],
                ['label' => $t['surplus'] >= 0 ? 'Surplus' : 'Deficit', 'value' => $this->money($t['surplus']), 'tone' => $t['surplus'] >= 0 ? 'success' : 'danger'],
                ['label' => 'Last year', 'value' => $this->money($t['last_year']['surplus']), 'tone' => 'muted'],
            ],
            meta: ['Period' => $this->rangeLabel($from, $to), 'Compared with' => $this->rangeLabel($s['compare']['from'], $s['compare']['to']), 'Prepared by' => $context->preparedBy()],
            sections: $this->ieSections($s),
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Approved by']],
        );
    }
}
