<?php

namespace App\Reports\Budget;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** Line by line: how much of each line's plan was used, biggest first, over-plan lines flagged. */
final class BudgetLinesReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.lines';
    }

    public function title(): string
    {
        return 'Line by line';
    }

    public function description(): string
    {
        return 'Every line: planned, used, % used and over or under, with how many entries and the last one.';
    }

    public function icon(): string
    {
        return 'ri-list-check-2';
    }

    public function subject(): string
    {
        return 'budget lines report';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $d = $data->dashboard();
        $activity = $this->lineActivity($data->periodEntries());
        $out = $d['lines']['out'];
        $in = $d['lines']['in'];
        $over = array_values(array_filter($out, fn ($l) => $l['actual'] > $l['planned'] && $l['planned'] > 0));
        $t = $d['totals'];

        $section = function (array $lines, bool $isIn) use ($activity) {
            return new ReportSection($isIn ? 'Income lines' : 'Expense lines', [
                ReportColumn::text('Line', true),
                ReportColumn::money('Planned'),
                ReportColumn::money($isIn ? 'Received' : 'Spent'),
                ReportColumn::money($isIn ? 'Still to come' : 'Left'),
                ReportColumn::number($isIn ? '% received' : '% used'),
                ReportColumn::number('Entries', 'sum'),
                ReportColumn::text('Last entry'),
                ReportColumn::text('Note'),
            ], array_map(fn ($l) => [
                $l['name'],
                $l['planned'],
                $l['actual'],
                $isIn ? max($l['left'], 0.0) : $l['left'],
                self::pct($l['pct']),
                $activity[$l['line_id']]['count'] ?? 0,
                self::day($activity[$l['line_id']]['last'] ?? null),
                $l['is_unplanned'] ? 'Unplanned' : (! $isIn && $l['actual'] > $l['planned'] ? 'Over by '.self::money($l['actual'] - $l['planned']) : null),
            ], $lines), $lines === [] ? 'No lines.' : null);
        };

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Line by line',
            periodLabel: $this->periodLabel($d['period']),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Lines', 'value' => (string) (count($in) + count($out)), 'tone' => 'primary'],
                ['label' => 'Over plan', 'value' => count($over).' '.(count($over) === 1 ? 'line' : 'lines'), 'tone' => $over ? 'danger' : 'success'],
                ['label' => 'Expenses used', 'value' => ($t['out_planned'] > 0 ? round($t['out_actual'] / $t['out_planned'] * 100) : 0).'%', 'tone' => 'warning'],
                ['label' => 'Income received', 'value' => ($t['in_planned'] > 0 ? round($t['in_actual'] / $t['in_planned'] * 100) : 0).'%', 'tone' => 'success'],
                ['label' => 'Spent', 'value' => self::money($t['out_actual']), 'tone' => 'danger'],
            ],
            meta: $this->meta($context, ['Period' => $this->periodLabel($d['period'])]),
            sections: [$section($out, false), $section($in, true)],
            insights: $data->insights(),
            charts: [$this->linesChart($out, 'out', 8), $this->linesChart($in, 'in', 8)],
        );
    }
}
