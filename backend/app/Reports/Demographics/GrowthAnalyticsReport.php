<?php

namespace App\Reports\Demographics;

use App\Models\ChurchDemographic;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\MembershipTrendRule;
use App\Support\Reports\Insights\Rules\YouthShareRule;

/**
 * Year over year for every figure, across 1, 3 or 5 years (or all). A
 * comparison, not a ledger - so no totals rows.
 */
final class GrowthAnalyticsReport extends Report
{
    private const YEAR_FIELDS = [
        'total_members', 'youth_count', 'womens_fellowship_count', 'mens_fellowship_count',
        'sunday_school_male_count', 'sunday_school_female_count', 'seniors_count',
    ];

    public function __construct(private DemographicsData $data) {}

    public function key(): string
    {
        return 'demographics.growth';
    }

    public function title(): string
    {
        return 'Growth analytics';
    }

    public function subject(): string
    {
        return 'Growth analytics report';
    }

    public function description(): string
    {
        return 'How each group has changed year over year, across 1, 3 or 5 years.';
    }

    public function icon(): string
    {
        return 'ri-line-chart-line';
    }

    public function inputs(): array
    {
        return ['years'];
    }

    public function build(ReportContext $context): ReportData
    {
        $range = (string) $context->param('years', '3');
        $all = $this->data->approvedRows($context)->filter(fn (ChurchDemographic $r) => $r->total_members !== null)->values();
        $yearsAvailable = $all->map(fn ($r) => (int) $r->fiscalYear?->year)->unique()->sort()->values();
        $keep = $range === 'all' ? $yearsAvailable : $yearsAvailable->slice(-max(1, (int) $range))->values();
        $rows = $all->filter(fn ($r) => $keep->contains((int) $r->fiscalYear?->year))->values();

        // The latest approved submission in each fiscal year.
        $perYear = $rows->groupBy(fn ($r) => (int) $r->fiscalYear?->year)->map(fn ($g) => $g->last())->sortKeys();
        $years = $perYear->keys()->all();

        $yoy = [];
        foreach (self::YEAR_FIELDS as $field) {
            $values = array_map(fn ($y) => DemographicsData::int($perYear[$y]->{$field}), $years);
            $first = collect($values)->first(fn ($v) => $v !== null);
            $last = collect($values)->last(fn ($v) => $v !== null);
            $change = '-';
            if ($first !== null && $last !== null && count($years) > 1) {
                $diff = $last - $first;
                $pct = $first ? ' ('.($diff >= 0 ? '+' : '').round($diff / $first * 100).'%)' : '';
                $change = ($diff > 0 ? '+' : '').number_format($diff).$pct;
            }
            $yoy[] = [DemographicsData::LABELS[$field], ...$values, $change];
        }

        $figures = $rows->map(fn ($r) => DemographicsData::toFigures($r))->all();
        $first = $rows->first();
        $latest = $rows->last();
        $growth = $first && $latest && $first->total_members
            ? round(($latest->total_members - $first->total_members) / $first->total_members * 100, 1)
            : null;

        $rangeLabel = match ($range) {
            'all' => 'All years',
            '1' => 'Last year',
            default => "Last {$range} years",
        };

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $this->title(),
            periodLabel: $years === [] ? $rangeLabel : $rangeLabel.' ('.$years[0].($years[0] !== end($years) ? '-'.end($years) : '').')',
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Members now', 'value' => $latest ? number_format($latest->total_members) : '-', 'tone' => 'primary'],
                ['label' => 'Growth', 'value' => $growth === null ? '-' : ($growth > 0 ? '+' : '').$growth.'%', 'tone' => $growth !== null && $growth < 0 ? 'danger' : 'success'],
                ['label' => 'New members', 'value' => number_format($rows->sum('new_members_count')), 'tone' => 'success'],
                ['label' => 'Submissions', 'value' => (string) $rows->count(), 'tone' => 'purple'],
            ],
            meta: [
                'Church' => $context->territory->name,
                'Range' => $rangeLabel,
                'Submissions' => $rows->count().' approved',
                'Prepared by' => $context->preparedBy(),
                'Prepared' => now()->format('j M Y, H:i'),
            ],
            sections: [
                new ReportSection(
                    'Year over year',
                    [ReportColumn::text('Figure', true), ...array_map(fn ($y) => ReportColumn::number((string) $y), $years), ReportColumn::number('Change')],
                    $yoy,
                    'Each year shows its latest approved submission.',
                ),
                new ReportSection(
                    'Every approved submission',
                    [ReportColumn::text('Period', true), ReportColumn::number('Total', null, true), ReportColumn::number('Youth'), ReportColumn::number("Women's"), ReportColumn::number("Men's"), ReportColumn::number('Sunday school'), ReportColumn::number('Seniors')],
                    $rows->map(fn ($r) => [
                        DemographicsData::label($r),
                        DemographicsData::int($r->total_members),
                        DemographicsData::int($r->youth_count),
                        DemographicsData::int($r->womens_fellowship_count),
                        DemographicsData::int($r->mens_fellowship_count),
                        $r->sunday_school_male_count === null && $r->sunday_school_female_count === null ? null : (int) $r->sunday_school_male_count + (int) $r->sunday_school_female_count,
                        DemographicsData::int($r->seniors_count),
                    ])->all(),
                ),
            ],
            insights: InsightEngine::run([
                new MembershipTrendRule,
                new YouthShareRule,
                GenderBalanceRule::members(),
                GenderBalanceRule::sundaySchool(),
            ], DemographicsData::facts($figures)),
        );
    }
}
