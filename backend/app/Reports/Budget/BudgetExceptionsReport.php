<?php

namespace App\Reports\Budget;

use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** For a treasurer's review: lines that went over plan, and money on lines the budget didn't plan. */
final class BudgetExceptionsReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.exceptions';
    }

    public function title(): string
    {
        return 'Unplanned and over-plan';
    }

    public function description(): string
    {
        return 'Lines that went over plan, and money on lines the budget didn\'t plan: what, when and by how much.';
    }

    public function icon(): string
    {
        return 'ri-alarm-warning-line';
    }

    public function subject(): string
    {
        return 'unplanned and over-plan report';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $d = $data->dashboard();
        $entries = $data->periodEntries();
        $activity = $this->lineActivity($entries);
        $over = array_values(array_filter($d['lines']['out'], fn ($l) => $l['actual'] > $l['planned'] && $l['planned'] > 0));
        usort($over, fn ($a, $b) => ($b['actual'] - $b['planned']) <=> ($a['actual'] - $a['planned']));
        $unplannedIds = collect([...$d['lines']['in'], ...$d['lines']['out']])->where('is_unplanned', true)->pluck('line_id')->all();
        $unplanned = array_values(array_filter($entries, fn ($e) => in_array($e['line_id'], $unplannedIds, true)));
        $overTotal = array_sum(array_map(fn ($l) => $l['actual'] - $l['planned'], $over));
        $unplannedTotal = array_sum(array_column($unplanned, 'amount'));

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Unplanned and over-plan',
            periodLabel: $this->periodLabel($d['period']),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Lines over plan', 'value' => (string) count($over), 'tone' => $over ? 'danger' : 'success'],
                ['label' => 'Over plan by', 'value' => self::money($overTotal), 'tone' => $overTotal > 0 ? 'danger' : 'success'],
                ['label' => 'Unplanned lines', 'value' => (string) count($unplannedIds), 'tone' => $unplannedIds ? 'warning' : 'success'],
                ['label' => 'Unplanned money', 'value' => self::money($unplannedTotal), 'tone' => $unplannedTotal > 0 ? 'warning' : 'success'],
            ],
            meta: $this->meta($context, ['Period' => $this->periodLabel($d['period'])]),
            sections: [
                new ReportSection('Lines over plan', [
                    ReportColumn::text('Line', true),
                    ReportColumn::money('Planned'),
                    ReportColumn::money('Spent'),
                    ReportColumn::money('Over by'),
                    ReportColumn::number('% used'),
                    ReportColumn::number('Entries', 'sum'),
                    ReportColumn::text('Last entry'),
                ], array_map(fn ($l) => [
                    $l['name'], $l['planned'], $l['actual'], round($l['actual'] - $l['planned'], 2), self::pct($l['pct']),
                    $activity[$l['line_id']]['count'] ?? 0, self::day($activity[$l['line_id']]['last'] ?? null),
                ], $over), $over === [] ? 'No line went over its plan.' : null),
                new ReportSection('Money on unplanned lines', [
                    ReportColumn::text('Date'),
                    ReportColumn::text('What for', true),
                    ReportColumn::text('Line'),
                    ReportColumn::text('Income or expense'),
                    ReportColumn::text('Recorded by'),
                    ReportColumn::money('Amount'),
                ], array_map(fn ($e) => [
                    self::day($e['entry_date']), $e['description'], $e['line'], $e['direction'] === 'in' ? 'Income' : 'Expense', $e['recorded_by'], $e['amount'],
                ], $unplanned), $unplanned === [] ? 'No money was recorded on an unplanned line.' : 'Lines the budget didn\'t plan, added when money was recorded on them.'),
            ],
            insights: $data->insights(),
            charts: $over ? [ReportChart::hbars('Lines over plan', array_column($over, 'name'), [
                ['name' => 'Planned', 'tone' => 'primary', 'soft' => true, 'values' => array_column($over, 'planned')],
                ['name' => 'Spent', 'tone' => 'danger', 'values' => array_column($over, 'actual')],
            ], null, true)] : [],
        );
    }
}
