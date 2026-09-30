<?php

namespace App\Reports\Attendance;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\AttendanceTrendRule;
use App\Support\Reports\Insights\Rules\AttendanceVsMembershipRule;
use App\Support\Reports\Insights\Rules\PeakSundayRule;
use App\Support\Reports\Insights\Rules\SundayCoverageRule;
use Carbon\CarbonImmutable;

/**
 * Every Sunday of the period, the ones nobody recorded included - the
 * Sunday Services page's report. The totals row is the average Sunday:
 * the same congregation comes every week, so Sundays are never summed.
 */
final class SundayServiceReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.sunday';
    }

    public function title(): string
    {
        return 'Sunday service';
    }

    public function description(): string
    {
        return 'Every Sunday with its count - missed Sundays listed - and the month-by-month average.';
    }

    public function icon(): string
    {
        return 'ri-sun-line';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $a = $data->analytics();
        $weekly = $a['sunday']['weekly'];
        $coverage = $a['summary']['coverage'];
        $best = $a['sunday']['top'][0] ?? null;

        // Recorded Sundays plus the missed ones, in date order.
        $rows = collect($weekly)->map(fn ($w) => ['date' => $w['date'], 'row' => $w]);
        foreach ($coverage['missing'] as $date) {
            $rows->push(['date' => $date, 'row' => null]);
        }
        $previous = null;
        $table = $rows->sortBy('date')->values()->map(function ($r) use (&$previous) {
            $w = $r['row'];
            $date = CarbonImmutable::parse($r['date'])->format('D j M Y');
            if (! $w) {
                return [$date, null, null, null, null, null, 'Not recorded'];
            }
            $change = $previous === null ? '' : (($d = $w['total'] - $previous) === 0 ? 'Same' : ($d > 0 ? "+{$d}" : (string) $d));
            $previous = $w['total'];

            return [$date, $w['adults_count'], $w['youth_count'], $w['children_male_count'], $w['children_female_count'], $w['total'], $change];
        })->all();

        $children = $a['children'];

        return new ReportData(
            kicker: $context->kicker('Sunday service report'),
            title: 'Sunday service',
            periodLabel: $this->periodLabel($data),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Average Sunday', 'value' => $this->int($a['summary']['sunday_average']), 'tone' => 'primary'],
                ['label' => 'Best Sunday', 'value' => $best ? number_format($best['total']).' · '.CarbonImmutable::parse($best['date'])->format('j M') : '-', 'tone' => 'success'],
                ['label' => 'Sundays recorded', 'value' => $coverage['elapsed'] ? "{$coverage['recorded']} of {$coverage['elapsed']}" : '-', 'tone' => $coverage['missing'] ? 'danger' : 'success'],
                ['label' => 'Children a Sunday', 'value' => $this->int($children['boys'] + $children['girls']), 'tone' => 'purple'],
            ],
            meta: $this->meta($context, $data),
            sections: [
                new ReportSection(
                    'Every Sunday',
                    [
                        ReportColumn::text('Sunday', true),
                        ReportColumn::number('Adults', 'avg'),
                        ReportColumn::number('Youth', 'avg'),
                        ReportColumn::number('Boys', 'avg'),
                        ReportColumn::number('Girls', 'avg'),
                        ReportColumn::number('Total', 'avg', true),
                        ReportColumn::text('vs last Sunday'),
                    ],
                    $table,
                    $coverage['missing'] ? count($coverage['missing']).' Sunday'.(count($coverage['missing']) === 1 ? ' was' : 's were').' not recorded - shown as "Not recorded".' : null,
                    'Average Sunday',
                ),
                $this->monthsSection($a['sunday']['months']),
            ],
            insights: InsightEngine::run([new SundayCoverageRule, new AttendanceTrendRule, new AttendanceVsMembershipRule, new PeakSundayRule], $this->facts($data)),
        );
    }
}
