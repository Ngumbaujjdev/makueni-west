<?php

namespace App\Reports\Demographics;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\MembershipTrendRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;

/**
 * Every figure for every period of the year - the on-screen Monthly
 * Statistics table as two tables (membership & groups, then changes &
 * sacraments), because 16 columns side by side won't fit even landscape
 * without cutting the numbers.
 */
final class MonthlyStatisticsReport extends FiscalYearReport
{
    private const HEADCOUNTS = [
        'total_members' => 'Total', 'male_count' => 'Male', 'female_count' => 'Female', 'youth_count' => 'Youth',
        'seniors_count' => 'Seniors', 'mens_fellowship_count' => "Men's fellowship", 'womens_fellowship_count' => "Women's fellowship",
        'sunday_school_male_count' => 'SS boys', 'sunday_school_female_count' => 'SS girls',
    ];

    private const FLOWS = [
        'new_members_count' => 'New members', 'transferred_out_count' => 'Departures', 'baptisms_count' => 'Baptisms',
        'communion_participants_count' => 'Holy Communion', 'conversions_count' => 'Conversions',
    ];

    public function key(): string
    {
        return 'demographics.monthly';
    }

    public function title(): string
    {
        return 'Monthly statistics';
    }

    public function description(): string
    {
        return 'Every figure for every period of the year in one table, with a year summary.';
    }

    public function icon(): string
    {
        return 'ri-table-line';
    }

    public function build(ReportContext $context): ReportData
    {
        $year = $this->year($context);
        $mode = $this->data->mode($context);
        $periods = $this->data->yearPeriods($context, $year);
        $missing = $this->missingLabels($periods);

        $table = fn (array $fields, string $total) => [
            [ReportColumn::text('Period', true), ...array_map(fn ($f, $label) => ReportColumn::number($label, $total, $f === 'total_members'), array_keys($fields), array_values($fields))],
            array_map(fn ($p) => [$p['label'], ...array_map(fn ($f) => DemographicsData::int($p[$f] ?? null), array_keys($fields))], $periods),
        ];
        [$headCols, $headRows] = $table(self::HEADCOUNTS, 'latest');
        [$flowCols, $flowRows] = $table(self::FLOWS, 'sum');
        $notReported = $missing === [] ? null : 'Not reported: '.implode(', ', $missing).'.';

        $reported = count($periods) - count($missing);

        return new ReportData(
            kicker: $context->kicker('demographics'),
            title: $this->title(),
            periodLabel: 'Fiscal year '.$year->year,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Members', 'value' => number_format((int) $this->latest($periods, 'total_members')), 'tone' => 'primary'],
                ['label' => 'New members', 'value' => number_format((int) $this->sum($periods, 'new_members_count')), 'tone' => 'success'],
                ['label' => 'Departures', 'value' => number_format((int) $this->sum($periods, 'transferred_out_count')), 'tone' => 'danger'],
                ['label' => 'Reported', 'value' => "{$reported} of ".count($periods), 'tone' => $missing === [] ? 'success' : 'warning'],
            ],
            meta: $this->meta($context, $year, $mode, $periods),
            sections: [
                new ReportSection('Membership & groups', $headCols, $headRows, trim(($notReported ?? '').' The last row is the latest reported headcount.'), 'Latest'),
                new ReportSection('Changes & sacraments', $flowCols, $flowRows, null, 'Year total'),
            ],
            insights: InsightEngine::run([
                new ReportingGapsRule,
                new MembershipTrendRule,
                new DeparturesVsNewMembersRule,
            ], DemographicsData::facts($periods, $missing, $mode)),
        );
    }
}
