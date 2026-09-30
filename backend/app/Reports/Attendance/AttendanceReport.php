<?php

namespace App\Reports\Attendance;

use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringType;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\ReportFacts;
use Carbon\CarbonImmutable;

/**
 * Shared plumbing for the attendance reports: the period (a fiscal year,
 * one month of it, or all time - params fiscal_year_id and month), the
 * AttendanceData behind it, and the tables more than one report uses.
 * Same numbers as the Attendance Analytics page.
 */
abstract class AttendanceReport extends Report
{
    public function module(): string
    {
        return 'attendance';
    }

    public function inputs(): array
    {
        return ['fiscal_year', 'fiscal_month'];
    }

    protected function data(ReportContext $context): AttendanceData
    {
        $yearId = $context->param('fiscal_year_id');
        if ($yearId !== 'all') {
            $yearId = ($yearId ? FiscalYear::whereKey($yearId)->value('id') : null)
                ?? FiscalYear::where('year', now()->year)->value('id')
                ?? FiscalYear::orderByDesc('year')->value('id');
        }
        $month = $context->param('month');
        $monthId = $yearId !== 'all' && $month ? FiscalMonth::where('number', (int) $month)->value('id') : null;

        return new AttendanceData($context->churchIds(), AttendancePeriod::resolve($yearId ?? 'all', $monthId, $context->churchIds()));
    }

    protected function periodLabel(AttendanceData $data): string
    {
        $p = $data->period;
        if ($p->mode === AttendancePeriod::ALL) {
            return 'All time (since '.$p->start->format('j M Y').')';
        }

        return $p->mode === AttendancePeriod::MONTH ? $p->label : "Fiscal year {$p->label}";
    }

    /** The details panel. */
    protected function meta(ReportContext $context, AttendanceData $data, array $extra = []): array
    {
        return [
            'Church' => $context->territory->name,
            'Period' => $this->periodLabel($data),
            'Sundays recorded' => $data->coverageText(),
            ...$extra,
            'Prepared by' => $context->preparedBy(),
            'Prepared' => now()->format('j M Y, H:i'),
        ];
    }

    /** The same facts the Analytics page gives its insight rules. */
    protected function facts(AttendanceData $data): ReportFacts
    {
        $analytics = $data->analytics();

        return new ReportFacts([
            'coverage' => $analytics['summary']['coverage'],
            'sunday_average' => $analytics['summary']['sunday_average'],
            'previous_average' => $analytics['summary']['previous_sunday_average'],
            'previous_label' => $data->period->previousLabel,
            'period_label' => $data->period->label,
            'months' => $analytics['sunday']['months'],
            'top_sundays' => $analytics['sunday']['top'],
            'membership' => $analytics['summary']['membership'],
            'latest' => ['children_male_count' => $analytics['children']['boys'], 'children_female_count' => $analytics['children']['girls']],
            'ministries' => $analytics['ministries']['items'],
            'events' => $analytics['events']['items'],
        ]);
    }

    /** "Sunday service by month": Sundays recorded, average, best. */
    protected function monthsSection(array $months, string $heading = 'Month by month'): ReportSection
    {
        return new ReportSection(
            $heading,
            [
                ReportColumn::text('Month', true),
                ReportColumn::number('Sundays', 'sum'),
                // No total: an average of monthly averages isn't the period's average Sunday.
                ReportColumn::number('Average Sunday', null, true),
                ReportColumn::number('Best'),
                ReportColumn::text('Best Sunday'),
                ReportColumn::text('Children'),
            ],
            array_map(fn ($m) => [
                $m['label'], $m['sundays'], $m['average'], $m['best'],
                CarbonImmutable::parse($m['best_date'])->format('j M'), "{$m['children_share']}%",
            ], $months),
            'Averages are per Sunday: the same congregation comes every week, so Sundays are never added up.',
            'Total',
        );
    }

    /** A ministry / event leaderboard: times met, average, most, last met, status. */
    protected function gatheringsSection(array $items, string $heading, string $noun): ReportSection
    {
        $status = ['active' => 'Active', 'quiet' => 'Quiet 60+ days', 'never' => 'Not held yet'];

        return new ReportSection(
            $heading,
            [
                ReportColumn::text(ucfirst($noun), true),
                ReportColumn::number('Times met', 'sum'),
                ReportColumn::number('Average'),
                ReportColumn::number('Most'),
                ReportColumn::text('Last met'),
                ReportColumn::text('Status'),
            ],
            array_map(fn ($g) => [
                $g['name'], $g['times'], $g['times'] ? $g['average'] : null, $g['times'] ? $g['peak'] : null,
                $g['last'] ? CarbonImmutable::parse($g['last'])->format('j M Y') : 'Never', $status[$g['status']] ?? '',
            ], $items),
            'Average and most are people per meeting. "Quiet" means no meeting in the last 60 days.',
        );
    }

    /** Every meeting of a ministry or event, with the four counts. */
    protected function meetingsSection($meetings, string $heading, string $noun): ReportSection
    {
        return new ReportSection(
            $heading,
            [
                ReportColumn::text('Date', true),
                ReportColumn::text(ucfirst($noun)),
                ReportColumn::number('Adults'),
                ReportColumn::number('Youth'),
                ReportColumn::number('Boys'),
                ReportColumn::number('Girls'),
                ReportColumn::number('Total', 'avg', true),
            ],
            $meetings->map(fn ($r) => [
                $r->service_date->format('D j M Y'),
                $r->gatheringType?->name ?? $r->event_name ?? '-',
                (int) $r->adults_count, (int) $r->youth_count, (int) $r->children_male_count, (int) $r->children_female_count,
                AttendanceData::total($r),
            ])->all(),
            null,
            'Average',
        );
    }

    /** The gathering type named by gathering_type_id, when it belongs to one of the report's churches. */
    protected function gatheringType(ReportContext $context): ?GatheringType
    {
        $id = $context->param('gathering_type_id');

        return $id ? GatheringType::whereKey($id)->whereIn('territory_id', $context->churchIds())->first() : null;
    }

    protected function int(mixed $v): string
    {
        return $v === null ? '-' : number_format((int) $v);
    }
}
