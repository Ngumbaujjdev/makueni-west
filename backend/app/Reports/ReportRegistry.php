<?php

namespace App\Reports;

use App\Enums\TerritoryType;
use App\Reports\Attendance\AttendanceSummaryReport;
use App\Reports\Attendance\ChildrenReport;
use App\Reports\Attendance\EventsReport;
use App\Reports\Attendance\MinistriesReport;
use App\Reports\Attendance\SundayServiceReport;
use App\Reports\Budget\BudgetCompareReport;
use App\Reports\Budget\BudgetContributionsReport;
use App\Reports\Budget\BudgetExceptionsReport;
use App\Reports\Budget\BudgetLineReport;
use App\Reports\Budget\BudgetLinesReport;
use App\Reports\Budget\BudgetRollupReport;
use App\Reports\Budget\BudgetSpendingReport;
use App\Reports\Budget\BudgetStatementReport;
use App\Reports\Budget\BudgetSummaryReport;
use App\Reports\Budget\BudgetYearReport;
use App\Reports\Demographics\BaptismsReport;
use App\Reports\Demographics\ConversionsReport;
use App\Reports\Demographics\DemographicsSummaryReport;
use App\Reports\Demographics\DeparturesReport;
use App\Reports\Demographics\GrowthAnalyticsReport;
use App\Reports\Demographics\HolyCommunionReport;
use App\Reports\Demographics\MetricReport;
use App\Reports\Demographics\MonthlyStatisticsReport;
use App\Reports\Demographics\SpiritualActivitiesReport;
use App\Reports\Demographics\SubmissionReport;

/** Every report the system can generate. Add a class here to publish a report. */
final class ReportRegistry
{
    private const REPORTS = [
        DemographicsSummaryReport::class,
        MonthlyStatisticsReport::class,
        SpiritualActivitiesReport::class,
        BaptismsReport::class,
        HolyCommunionReport::class,
        ConversionsReport::class,
        DeparturesReport::class,
        GrowthAnalyticsReport::class,
        SubmissionReport::class,
        MetricReport::class,
        AttendanceSummaryReport::class,
        SundayServiceReport::class,
        MinistriesReport::class,
        EventsReport::class,
        ChildrenReport::class,
        BudgetSummaryReport::class,
        BudgetSpendingReport::class,
        BudgetStatementReport::class,
        BudgetLinesReport::class,
        BudgetYearReport::class,
        BudgetCompareReport::class,
        BudgetExceptionsReport::class,
        BudgetLineReport::class,
        BudgetRollupReport::class,
        BudgetContributionsReport::class,
        \App\Reports\Activities\ActivitySummaryReport::class,
        \App\Reports\Activities\ActivityYearReport::class,
        \App\Reports\Activities\InitiativeYearReport::class,
        \App\Reports\Monthly\MonthlyReportExport::class,
        \App\Reports\Monthly\MonthlyStatusReport::class,
        \App\Reports\People\MembersDirectoryReport::class,
    ];

    /** @return Report[] */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), self::REPORTS);
    }

    public static function find(string $key): ?Report
    {
        foreach (self::all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @return Report[] */
    public static function forScope(TerritoryType $type, ?string $module = null): array
    {
        return array_values(array_filter(self::all(), fn (Report $r) => $r->supports($type) && ($module === null || $r->module() === $module)));
    }
}
