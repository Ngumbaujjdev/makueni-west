<?php

namespace App\Reports\Accounting;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/** What is owned, what is owed and what is held in each fund, at a date, beside a year earlier. */
final class FinancialPositionReport extends StatementReport
{
    public function key(): string
    {
        return 'accounting.statement.position';
    }

    public function title(): string
    {
        return 'Financial position';
    }

    public function description(): string
    {
        return 'What we own, what we owe and what is held in each fund at a date - the balance sheet.';
    }

    public function icon(): string
    {
        return 'ri-scales-line';
    }

    public function subject(): string
    {
        return 'statement of financial position';
    }

    public function build(ReportContext $context): ReportData
    {
        [, $at] = $this->yearDates($context);
        $s = $this->statements()->position($context->territory, $at, $this->consolidated($context));
        $t = $s['totals'];

        return new ReportData(
            kicker: $context->kicker('statement of financial position'),
            title: 'Financial position',
            periodLabel: 'As at '.$this->day($at),
            scopeLabel: $this->scope($context, $s),
            tiles: [
                ['label' => 'Assets', 'value' => $this->money($t['assets']), 'tone' => 'primary'],
                ['label' => 'Liabilities', 'value' => $this->money($t['liabilities']), 'tone' => 'warning'],
                ['label' => 'Net assets', 'value' => $this->money($t['net_assets']), 'tone' => $t['net_assets'] >= 0 ? 'success' : 'danger'],
                ['label' => 'Funds', 'value' => $this->money($t['funds']), 'tone' => $t['balanced'] ? 'success' : 'danger'],
            ],
            meta: ['As at' => $this->day($at), 'Compared with' => $this->day($s['compare']['at']), 'Prepared by' => $context->preparedBy()],
            sections: $this->positionSections($s),
            signatures: [['label' => 'Prepared by', 'name' => $context->preparedBy()], ['label' => 'Approved by']],
        );
    }
}
