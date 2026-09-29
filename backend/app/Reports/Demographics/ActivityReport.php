<?php

namespace App\Reports\Demographics;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\InsightRule;

/**
 * One spiritual activity on its own - the report behind each tab on the
 * Spiritual Activities page (Baptisms, Holy Communion, Conversions,
 * Departures). A subclass names the field and the rules that fit it.
 */
abstract class ActivityReport extends FiscalYearReport
{
    /** The ChurchDemographic column this report is about. */
    abstract protected function field(): string;

    /** @return InsightRule[] */
    abstract protected function rules(): array;

    public function group(): ?string
    {
        return 'Spiritual activities';
    }

    /** Tile tone for the activity's own figures. */
    protected function tone(): string
    {
        return 'primary';
    }

    public function build(ReportContext $context): ReportData
    {
        [$periods, $periodLabel] = $this->scope($context);
        $mode = $this->data->mode($context);
        $missing = $this->isAllTime($context) ? [] : $this->missingLabels($periods);
        $field = $this->field();
        $name = $this->title();
        $noun = DemographicsData::periodNoun($mode);

        $reported = array_values(array_filter($periods, fn ($p) => $p['status'] === 'approved' && $p[$field] !== null));
        $total = (int) array_sum(array_column($reported, $field));
        $best = null;
        foreach ($reported as $p) {
            if ($best === null || $p[$field] > $best[$field]) {
                $best = $p;
            }
        }
        $withAny = count(array_filter($reported, fn ($p) => (int) $p[$field] > 0));
        $average = $reported === [] ? null : round($total / count($reported), 1);

        $share = fn ($p) => $p[$field] === null || ! $p['total_members'] ? null : round($p[$field] / $p['total_members'] * 100, 1).'%';

        return new ReportData(
            kicker: $context->kicker('demographics'),
            title: $name,
            periodLabel: $periodLabel,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Total', 'value' => number_format($total), 'tone' => $this->tone()],
                ['label' => "Best {$noun}", 'value' => $best && $best[$field] > 0 ? "{$best['label']} (".number_format($best[$field]).')' : '-', 'tone' => 'success'],
                ['label' => 'Average', 'value' => $average === null ? '-' : "{$average} per {$noun}", 'tone' => 'purple'],
                ['label' => ucfirst($noun).'s with any', 'value' => $withAny.' of '.count($periods), 'tone' => 'warning'],
            ],
            meta: $this->meta($context, $periodLabel, $mode, $periods),
            sections: [
                new ReportSection(
                    'By period',
                    [ReportColumn::text('Period', true), ReportColumn::number($name, 'sum', true)],
                    array_map(fn ($p) => [$p['label'], DemographicsData::int($p[$field] ?? null)], $periods),
                    $missing === [] ? null : 'Not reported: '.implode(', ', $missing).'.',
                    $this->isAllTime($context) ? 'All-time total' : 'Year total',
                ),
                new ReportSection(
                    'Compared with membership',
                    [ReportColumn::text('Period', true), ReportColumn::number($name), ReportColumn::number('Members'), ReportColumn::number('Share of members')],
                    array_map(fn ($p) => [$p['label'], DemographicsData::int($p[$field]), DemographicsData::int($p['total_members']), $share($p)], $reported),
                    'Share of members is the figure divided by total members for that period.',
                ),
            ],
            insights: InsightEngine::run($this->rules(), DemographicsData::facts($periods, $missing, $mode)),
        );
    }
}
