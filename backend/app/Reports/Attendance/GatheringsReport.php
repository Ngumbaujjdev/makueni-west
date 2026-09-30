<?php

namespace App\Reports\Attendance;

use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Support\Reports\Insights\InsightEngine;

/**
 * Ministries or special events: the leaderboard and every meeting. With a
 * gathering_type_id it's that one ministry's report ("Kesha") - what the
 * Ministries page exports when a ministry is picked.
 */
abstract class GatheringsReport extends AttendanceReport
{
    abstract protected function slug(): string;

    abstract protected function noun(): string;

    abstract protected function plural(): string;

    abstract protected function rules(): array;

    public function inputs(): array
    {
        return ['fiscal_year', 'fiscal_month', 'gathering_type'];
    }

    public function titleFor(array $params): string
    {
        $type = isset($params['gathering_type_id']) ? \App\Models\GatheringType::find($params['gathering_type_id']) : null;

        return $type?->name ?? $this->title();
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $type = $this->gatheringType($context);
        $items = $data->gatherings($this->slug(), $type?->id);
        $meetings = $data->meetings($this->slug(), $type?->id);
        $held = array_values(array_filter($items, fn ($g) => $g['times'] > 0));
        $top = collect($held)->sortByDesc('average')->first();
        $totals = $meetings->map(fn ($r) => AttendanceData::total($r));
        $noun = $this->noun();
        $title = $type?->name ?? $this->title();

        $tiles = $type
            ? [
                ['label' => 'Times met', 'value' => (string) $meetings->count(), 'tone' => 'primary'],
                ['label' => 'Average', 'value' => $totals->count() ? number_format(round($totals->avg())) : '-', 'tone' => 'success'],
                ['label' => 'Most', 'value' => $totals->count() ? number_format($totals->max()) : '-', 'tone' => 'purple'],
                ['label' => 'Last met', 'value' => ($items[0]['last'] ?? null) ? \Carbon\CarbonImmutable::parse($items[0]['last'])->format('j M Y') : 'Never', 'tone' => 'warning'],
            ]
            : [
                ['label' => 'Meetings', 'value' => (string) $meetings->count(), 'tone' => 'primary'],
                ['label' => 'Average', 'value' => $totals->count() ? number_format(round($totals->avg())) : '-', 'tone' => 'success'],
                ['label' => ucfirst($this->plural()).' that met', 'value' => count($held).' of '.count($items), 'tone' => 'purple'],
                ['label' => 'Most attended', 'value' => $top ? $top['name'] : '-', 'tone' => 'warning'],
            ];

        return new ReportData(
            kicker: $context->kicker($type ? "{$type->name} report" : $this->subject()),
            title: $title,
            periodLabel: $this->periodLabel($data),
            scopeLabel: $context->scopeLabel(),
            tiles: $tiles,
            meta: $this->meta($context, $data, $type ? [ucfirst($noun) => $type->name] : []),
            sections: array_values(array_filter([
                $type ? null : $this->gatheringsSection($items, ucfirst($this->plural()), $noun),
                $this->meetingsSection($meetings, $noun === 'event' && ! $type ? 'Every event' : 'Every meeting', $noun),
            ])),
            insights: InsightEngine::run($type ? [] : $this->rules(), $this->facts($data)),
        );
    }
}
