<?php

namespace App\Reports\Budget;

use App\Reports\ReportContext;
use App\Reports\ReportData;

/** Every amount received and spent in a month or a year, with totals. */
final class BudgetSpendingReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.spending';
    }

    public function title(): string
    {
        return 'Money in and out';
    }

    public function description(): string
    {
        return 'Every amount received and spent in the period: what for, which line, how it was paid, with totals.';
    }

    public function icon(): string
    {
        return 'ri-exchange-dollar-line';
    }

    public function subject(): string
    {
        return 'money in and out report';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $d = $data->dashboard();
        $entries = $data->periodEntries();
        $in = array_sum(array_map(fn ($e) => $e['amount'], array_filter($entries, fn ($e) => $e['direction'] === 'in')));
        $out = array_sum(array_map(fn ($e) => $e['amount'], array_filter($entries, fn ($e) => $e['direction'] === 'out')));

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Money in and out',
            periodLabel: $this->periodLabel($d['period']),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Money in', 'value' => self::money($in), 'tone' => 'success'],
                ['label' => 'Money out', 'value' => self::money($out), 'tone' => 'danger'],
                ['label' => 'Difference', 'value' => self::money($in - $out), 'tone' => $in - $out < 0 ? 'danger' : 'purple'],
                ['label' => 'Entries', 'value' => (string) count($entries), 'tone' => 'primary'],
            ],
            meta: $this->meta($context, ['Period' => $this->periodLabel($d['period'])]),
            sections: [$this->entriesSection($entries, 'in'), $this->entriesSection($entries, 'out')],
            charts: $d['trend']['kind'] === 'months' ? [$this->monthsChart($d['trend']['points'])] : [],
        );
    }
}
