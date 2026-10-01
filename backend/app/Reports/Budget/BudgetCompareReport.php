<?php

namespace App\Reports\Budget;

use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** This month (or year) next to the one before: every line, and what changed. */
final class BudgetCompareReport extends BudgetReport
{
    public function key(): string
    {
        return 'budget.compare';
    }

    public function title(): string
    {
        return 'Compare two periods';
    }

    public function description(): string
    {
        return 'This month or year next to the one before: every line\'s planned and actual, and the change in KES and %.';
    }

    public function icon(): string
    {
        return 'ri-arrow-left-right-line';
    }

    public function subject(): string
    {
        return 'budget comparison';
    }

    public function build(ReportContext $context): ReportData
    {
        [$year, $month] = $this->period($context);
        [$pYear, $pMonth] = $month === null ? [$year - 1, null] : ($month === 1 ? [$year - 1, 12] : [$year, $month - 1]);
        $type = $context->type()->value;
        $id = (int) $context->territory->id;
        $now = (new BudgetData($type, $id, $year, $month))->dashboard();
        $before = (new BudgetData($type, $id, $pYear, $pMonth))->dashboard();
        $label = $this->periodLabel($now['period']);
        $prevLabel = $this->periodLabel($before['period']);

        $section = function (string $side) use ($now, $before, $label, $prevLabel) {
            $prev = collect($before['lines'][$side])->keyBy('line_id');
            $cur = collect($now['lines'][$side])->keyBy('line_id');
            $ids = $cur->keys()->merge($prev->keys())->unique();
            $rows = $ids->map(function ($lineId) use ($prev, $cur) {
                $a = $prev->get($lineId);
                $b = $cur->get($lineId);
                $was = $a['actual'] ?? 0.0;
                $is = $b['actual'] ?? 0.0;

                return [
                    $b['name'] ?? $a['name'],
                    $a['planned'] ?? 0.0,
                    $was,
                    $b['planned'] ?? 0.0,
                    $is,
                    round($is - $was, 2),
                    $was > 0 ? round(($is - $was) / $was * 100).'%' : ($is > 0 ? 'New' : null),
                ];
            })->sortByDesc(fn ($r) => max($r[3], $r[4], $r[1], $r[2]))->values()->all();
            $verb = $side === 'in' ? 'received' : 'spent';

            return new ReportSection($side === 'in' ? 'Money in' : 'Money out', [
                ReportColumn::text('Line', true),
                ReportColumn::money("Planned, {$prevLabel}"),
                ReportColumn::money(ucfirst($verb).", {$prevLabel}"),
                ReportColumn::money("Planned, {$label}"),
                ReportColumn::money(ucfirst($verb).", {$label}"),
                ReportColumn::money('Change'),
                ReportColumn::number('Change %'),
            ], $rows, $rows === [] ? 'Nothing in either period.' : null);
        };

        $t = $now['totals'];
        $p = $before['totals'];
        $change = fn ($a, $b) => $b > 0 ? ($a >= $b ? '+' : '').round(($a - $b) / $b * 100).'%' : ($a > 0 ? 'New' : '-');

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Compare two periods',
            periodLabel: "{$label} against {$prevLabel}",
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Received now', 'value' => self::money($t['in_actual']), 'tone' => 'success', 'hint' => $change($t['in_actual'], $p['in_actual'])],
                ['label' => 'Received before', 'value' => self::money($p['in_actual']), 'tone' => 'muted'],
                ['label' => 'Spent now', 'value' => self::money($t['out_actual']), 'tone' => 'danger', 'hint' => $change($t['out_actual'], $p['out_actual'])],
                ['label' => 'Spent before', 'value' => self::money($p['out_actual']), 'tone' => 'muted'],
                ['label' => 'Left, change', 'value' => self::money($t['left_actual'] - $p['left_actual']), 'tone' => $t['left_actual'] - $p['left_actual'] < 0 ? 'danger' : 'purple'],
            ],
            meta: $this->meta($context, ['This period' => $label, 'Compared with' => $prevLabel, 'Money in' => $change($t['in_actual'], $p['in_actual']), 'Money out' => $change($t['out_actual'], $p['out_actual'])]),
            sections: [$section('in'), $section('out')],
            charts: [ReportChart::bars("{$label} against {$prevLabel}", ['Planned in', 'Received', 'Planned out', 'Spent'], [
                ['name' => $prevLabel, 'tone' => 'muted', 'soft' => true, 'values' => [$p['in_planned'], $p['in_actual'], $p['out_planned'], $p['out_actual']]],
                ['name' => $label, 'tone' => 'primary', 'values' => [$t['in_planned'], $t['in_actual'], $t['out_planned'], $t['out_actual']]],
            ])],
        );
    }
}
