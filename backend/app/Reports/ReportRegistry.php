<?php

namespace App\Reports;

use App\Enums\TerritoryType;
use App\Reports\Demographics\DemographicsSummaryReport;
use App\Reports\Demographics\GrowthAnalyticsReport;
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
        GrowthAnalyticsReport::class,
        SubmissionReport::class,
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
    public static function forScope(TerritoryType $type): array
    {
        return array_values(array_filter(self::all(), fn (Report $r) => $r->supports($type)));
    }
}
