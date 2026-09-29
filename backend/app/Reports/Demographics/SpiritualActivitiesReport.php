<?php

namespace App\Reports\Demographics;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\SacramentsRule;

/** Baptisms, Holy Communion, conversions and departures through the year. */
final class SpiritualActivitiesReport extends FiscalYearReport
{
    private const FIELDS = [
        'baptisms_count' => 'Baptisms',
        'communion_participants_count' => 'Holy Communion',
        'conversions_count' => 'Conversions',
        'transferred_out_count' => 'Departures',
    ];

    public function key(): string
    {
        return 'demographics.spiritual';
    }

    public function title(): string
    {
        return 'Spiritual activities';
    }

    public function subject(): string
    {
        return 'Spiritual activities report';
    }

    public function description(): string
    {
        return 'Baptisms, Holy Communion, conversions and departures for each period of the year.';
    }

    public function group(): ?string
    {
        return 'Spiritual activities';
    }

    public function icon(): string
    {
        return 'ri-hand-heart-line';
    }

    public function build(ReportContext $context): ReportData
    {
        [$periods, $periodLabel] = $this->scope($context);
        $mode = $this->data->mode($context);
        $missing = $this->missingLabels($periods);
        $reported = array_values(array_filter($periods, fn ($p) => $p['status'] === 'approved'));

        $totals = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $totals[$f] = (int) $this->sum($periods, $f);
        }
        $all = array_sum($totals);

        $mix = [];
        foreach (self::FIELDS as $f => $label) {
            $best = null;
            foreach ($reported as $p) {
                if ($p[$f] !== null && ($best === null || $p[$f] > $best[$f])) {
                    $best = $p;
                }
            }
            $mix[] = [
                $label,
                $totals[$f],
                $all ? round($totals[$f] / $all * 100, 1).'%' : '-',
                $best && $best[$f] > 0 ? "{$best['label']} (".number_format($best[$f]).')' : '-',
                $reported === [] ? null : round($totals[$f] / count($reported), 1),
            ];
        }

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $this->title(),
            periodLabel: $periodLabel,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Baptisms', 'value' => number_format($totals['baptisms_count']), 'tone' => 'primary'],
                ['label' => 'Holy Communion', 'value' => number_format($totals['communion_participants_count']), 'tone' => 'warning'],
                ['label' => 'Conversions', 'value' => number_format($totals['conversions_count']), 'tone' => 'purple'],
                ['label' => 'Departures', 'value' => number_format($totals['transferred_out_count']), 'tone' => 'danger'],
            ],
            meta: $this->meta($context, $periodLabel, $mode, $periods),
            sections: [
                new ReportSection(
                    'By period',
                    [ReportColumn::text('Period', true), ...array_map(fn ($label) => ReportColumn::number($label, 'sum'), array_values(self::FIELDS))],
                    array_map(fn ($p) => [$p['label'], ...array_map(fn ($f) => DemographicsData::int($p[$f] ?? null), array_keys(self::FIELDS))], $periods),
                    $missing === [] ? null : 'Not reported: '.implode(', ', $missing).'.',
                    'Year total',
                ),
                new ReportSection(
                    'The year at a glance',
                    [ReportColumn::text('Activity', true), ReportColumn::number('Year total'), ReportColumn::number('Share'), ReportColumn::text('Best period'), ReportColumn::number('Average')],
                    $mix,
                ),
            ],
            insights: InsightEngine::run([
                new SacramentsRule,
                new HolyCommunionRule,
                new DeparturesVsNewMembersRule,
            ], DemographicsData::facts($periods, $missing, $mode)),
        );
    }
}
