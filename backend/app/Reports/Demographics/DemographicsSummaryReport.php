<?php

namespace App\Reports\Demographics;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\MembershipTrendRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;
use App\Support\Reports\Insights\Rules\SacramentsRule;
use App\Support\Reports\Insights\Rules\SundaySchoolTeachersRule;
use App\Support\Reports\Insights\Rules\YouthShareRule;

/** The year at a glance: membership, groups, changes and Holy Communion, with insights. */
final class DemographicsSummaryReport extends FiscalYearReport
{
    public function key(): string
    {
        return 'demographics.summary';
    }

    public function title(): string
    {
        return 'Demographics summary';
    }

    public function subject(): string
    {
        return 'Demographics report';
    }

    public function description(): string
    {
        return 'Membership, groups, changes and Holy Communion for the year or all time, with insights and recommendations.';
    }

    public function icon(): string
    {
        return 'ri-file-chart-2-line';
    }

    public function build(ReportContext $context): ReportData
    {
        [$periods, $periodLabel] = $this->scope($context);
        $mode = $this->data->mode($context);
        $missing = $this->missingLabels($periods);
        $reported = array_values(array_filter($periods, fn ($p) => $p['status'] === 'approved'));
        $ssLatest = $this->latest($periods, 'sunday_school_male_count') === null && $this->latest($periods, 'sunday_school_female_count') === null
            ? null
            : ($this->latest($periods, 'sunday_school_male_count') ?? 0) + ($this->latest($periods, 'sunday_school_female_count') ?? 0);

        $n = fn ($v) => $v === null ? '-' : number_format($v);
        $tiles = [
            ['label' => 'Members', 'value' => $n($this->latest($periods, 'total_members')), 'tone' => 'primary'],
            ['label' => 'Sunday school', 'value' => $n($ssLatest), 'tone' => 'purple'],
            ['label' => 'New members', 'value' => $n($this->sum($periods, 'new_members_count')), 'tone' => 'success'],
            ['label' => 'Baptisms', 'value' => $n($this->sum($periods, 'baptisms_count')), 'tone' => 'primary'],
            ['label' => 'Reported', 'value' => count($reported).' of '.count($periods), 'tone' => $missing === [] ? 'success' : 'warning'],
        ];

        $row = fn (array $p, array $fields) => [$p['label'], ...array_map(fn ($f) => DemographicsData::int($p[$f] ?? null), $fields)];
        $membershipFields = ['total_members', 'male_count', 'female_count', 'youth_count', 'seniors_count'];
        $groupFields = ['womens_fellowship_count', 'mens_fellowship_count', 'sunday_school_male_count', 'sunday_school_female_count', 'sunday_school_teachers_count'];
        $changeFields = DemographicsData::FLOWS;
        $note = $missing === [] ? null : 'Not reported: '.implode(', ', $missing).'.';

        $sections = [
            new ReportSection(
                'Membership',
                [ReportColumn::text('Period', true), ...array_map(fn ($f) => ReportColumn::number($f === 'total_members' ? 'Total' : DemographicsData::LABELS[$f], 'latest', $f === 'total_members'), $membershipFields)],
                array_map(fn ($p) => $row($p, $membershipFields), $periods),
                $note,
                'Latest',
            ),
            new ReportSection(
                'Groups',
                [ReportColumn::text('Period', true), ...array_map(fn ($f) => ReportColumn::number(str_replace('Sunday school ', 'SS ', DemographicsData::LABELS[$f]), 'latest'), $groupFields)],
                array_map(fn ($p) => $row($p, $groupFields), $periods),
                'Fellowships and Sunday school are groups within total members.',
                'Latest',
            ),
            new ReportSection(
                'Changes & Holy Communion',
                [ReportColumn::text('Period', true), ...array_map(fn ($f) => ReportColumn::number(DemographicsData::LABELS[$f], 'sum'), $changeFields)],
                array_map(fn ($p) => $row($p, $changeFields), $periods),
                null,
                'Year total',
            ),
        ];

        $insights = InsightEngine::run([
            new ReportingGapsRule,
            new MembershipTrendRule,
            new DeparturesVsNewMembersRule,
            new YouthShareRule,
            GenderBalanceRule::members(),
            GenderBalanceRule::sundaySchool(),
            new SundaySchoolTeachersRule,
            new HolyCommunionRule,
            new SacramentsRule,
        ], DemographicsData::facts($periods, $missing, $mode));

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $this->title(),
            periodLabel: $periodLabel,
            scopeLabel: $context->scopeLabel(),
            tiles: $tiles,
            meta: $this->meta($context, $periodLabel, $mode, $periods),
            sections: $sections,
            insights: $insights,
        );
    }
}
