<?php

namespace App\Reports\Attendance;

use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\AttendanceTrendRule;
use App\Support\Reports\Insights\Rules\AttendanceVsMembershipRule;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\PeakSundayRule;
use App\Support\Reports\Insights\Rules\QuietGatheringRule;
use App\Support\Reports\Insights\Rules\SundayCoverageRule;
use App\Support\Reports\Insights\Rules\TopGatheringRule;

/** Everything at once: Sundays month by month, ministries and events - the Attendance overview's report. */
final class AttendanceSummaryReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.summary';
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function subject(): string
    {
        return 'Attendance report';
    }

    public function description(): string
    {
        return 'Sunday services month by month, every ministry and event, with insights and recommendations.';
    }

    public function icon(): string
    {
        return 'ri-calendar-check-line';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $a = $data->analytics();
        $s = $a['summary'];
        $c = $s['coverage'];
        $m = $s['membership'];

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Attendance',
            periodLabel: $this->periodLabel($data),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Average Sunday', 'value' => $this->int($s['sunday_average']), 'tone' => 'primary'],
                ['label' => 'Sundays recorded', 'value' => $c['elapsed'] ? "{$c['recorded']} of {$c['elapsed']}" : '-', 'tone' => $c['elapsed'] && $c['recorded'] < $c['elapsed'] ? 'danger' : 'success'],
                ['label' => 'Gatherings & events', 'value' => $this->int($s['gatherings_held']), 'tone' => 'purple'],
                ['label' => 'Members on a Sunday', 'value' => $m && $m['rate'] !== null ? "{$m['rate']}%" : '-', 'tone' => 'warning'],
            ],
            meta: $this->meta($context, $data, $m ? ['Members' => number_format($m['total_members']).($m['as_of'] ? " ({$m['as_of']})" : '')] : []),
            sections: [
                $this->monthsSection($a['sunday']['months'], 'Sunday service by month'),
                $this->gatheringsSection($a['ministries']['items'], 'Ministries', 'ministry'),
                $this->gatheringsSection($a['events']['items'], 'Special events', 'event'),
            ],
            insights: InsightEngine::run([
                new SundayCoverageRule, new AttendanceTrendRule, new AttendanceVsMembershipRule, new PeakSundayRule,
                QuietGatheringRule::ministries(), TopGatheringRule::ministries(), TopGatheringRule::events(), GenderBalanceRule::sundayChildren(),
            ], $this->facts($data)),
        );
    }
}
