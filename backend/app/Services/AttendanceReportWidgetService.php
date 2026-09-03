<?php

namespace App\Services;

use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Server-side aggregation behind the Attendance Reports tabbed dashboard
 * (church/attendance/reports.php) - mirrors DemographicsGrowthService's
 * "one small shared service class" exception to this codebase's normally
 * fat-controller convention, for the same reason: the Sunday-coverage math
 * below needs authoritative fiscal-year calendar dates, not something a
 * controller action or the frontend should be re-deriving.
 *
 * Beyond raw numbers, this also produces short plain-English `insights`
 * sentences and a per-breakdown-row `status` - simple threshold-based
 * logic (not a statistics engine), so the dashboard actually tells a
 * pastor something rather than leaving them to interpret bare charts.
 */
class AttendanceReportWidgetService
{
    private const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** A gathering type with no record in this many days reads as "inactive," not just "not recently held." */
    private const INACTIVE_DAYS_THRESHOLD = 60;

    /** Smaller month-over-month swings than this aren't worth a sentence. */
    private const TREND_NOTICE_THRESHOLD_PERCENT = 5;

    /**
     * @param  GatheringCategory|null  $category  null = all 3 categories combined (the cross-tab summary strip)
     */
    public function widgetsFor(int $territoryId, ?GatheringCategory $category, FiscalYear $year, ?FiscalMonth $month): array
    {
        $records = $this->recordsFor($territoryId, $category, $year, $month);

        if ($category === null) {
            return ['stats' => $this->combinedStats($records)];
        }

        if ($category->is_weekly) {
            $coverage = $this->coverage($records, $year, $month);

            return [
                'stats' => $this->sundayStats($records, $coverage),
                'coverage' => $coverage,
                'chart' => $this->trendChart($records, $year, $month),
                'insights' => $this->sundayInsights($records, $month, $coverage),
                'stat_columns' => $this->sundayStatColumns($records),
            ];
        }

        $breakdown = $this->breakdown($territoryId, $category, $records);

        return [
            'stats' => $this->breakdownStats($records, $breakdown),
            'breakdown' => $breakdown,
            'insights' => $this->breakdownInsights($breakdown),
        ];
    }

    private function recordsFor(int $territoryId, ?GatheringCategory $category, FiscalYear $year, ?FiscalMonth $month): Collection
    {
        $query = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id)
            ->with('gatheringCategory');

        if ($category !== null) {
            $query->where('gathering_category_id', $category->id);
        }

        if ($month !== null) {
            $query->where('fiscal_month_id', $month->id);
        }

        return $query->get();
    }

    private function totalFor(ChurchAttendanceRecord $r): int
    {
        return (int) $r->adults_count + (int) $r->youth_count
            + (int) $r->children_male_count + (int) $r->children_female_count;
    }

    /**
     * Last 6 fiscal months' totals within the selected year, used as the
     * embedded sparkline on every stat card - one shared trend dataset per
     * tab rather than a bespoke series per metric, kept intentionally
     * simple.
     */
    private function sparkline(Collection $records): array
    {
        $monthly = array_fill(1, 12, 0);

        foreach ($records as $r) {
            $monthly[(int) Carbon::parse($r->service_date)->format('n')] += $this->totalFor($r);
        }

        return array_slice(array_values($monthly), -6);
    }

    private function combinedStats(Collection $records): array
    {
        $total = $records->sum(fn ($r) => $this->totalFor($r));
        $avg = $records->count() ? round($total / $records->count(), 1) : 0;
        $spark = $this->sparkline($records);

        $byCategory = $records->groupBy('gathering_category_id')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->gatheringCategory?->name ?? 'Unknown',
                'total' => $group->sum(fn ($r) => $this->totalFor($r)),
            ]);
        $mostActiveCategory = $byCategory->sortByDesc('total')->first();

        return [
            ['label' => 'Total Gatherings Recorded', 'value' => $records->count(), 'icon' => 'ri-calendar-check-line', 'color' => 'primary', 'sparkline' => $spark],
            ['label' => 'Total Attendance', 'value' => $total, 'icon' => 'ri-group-line', 'color' => 'success', 'sparkline' => $spark],
            ['label' => 'Overall Average', 'value' => $avg, 'icon' => 'ri-bar-chart-line', 'color' => 'warning', 'sparkline' => $spark],
            ['label' => 'Most Active Category', 'value' => $mostActiveCategory ? "{$mostActiveCategory['name']} ({$mostActiveCategory['total']})" : '-', 'icon' => 'ri-fire-line', 'color' => 'danger', 'sparkline' => $spark],
        ];
    }

    private function sundayStats(Collection $records, array $coverage): array
    {
        $byDate = $records->unique('service_date');
        $total = $byDate->sum(fn ($r) => $this->totalFor($r));
        $avg = $byDate->count() ? round($total / $byDate->count(), 1) : 0;
        $spark = $this->sparkline($records);

        $highest = $byDate->sortByDesc(fn ($r) => $this->totalFor($r))->first();
        $highestLabel = $highest
            ? $this->totalFor($highest).' on '.Carbon::parse($highest->service_date)->format('j M')
            : '-';

        return [
            ['label' => 'Sundays Recorded', 'value' => $byDate->count(), 'icon' => 'ri-calendar-check-line', 'color' => 'primary', 'sparkline' => $spark],
            ['label' => 'Coverage', 'value' => "{$coverage['recorded']} of {$coverage['elapsed']} · {$coverage['percentage']}%", 'icon' => 'ri-pie-chart-line', 'color' => 'secondary', 'sparkline' => $spark],
            ['label' => 'Average Attendance', 'value' => $avg, 'icon' => 'ri-bar-chart-line', 'color' => 'warning', 'sparkline' => $spark],
            ['label' => 'Highest Attended Sunday', 'value' => $highestLabel, 'icon' => 'ri-trophy-line', 'color' => 'success', 'sparkline' => $spark],
        ];
    }

    /**
     * Sundays recorded vs. Sundays elapsed so far in the selected period -
     * the direct answer to "attendance isn't recorded for every Sunday":
     * this is deliberately server-side, since it needs the fiscal year's
     * real start_date/end_date, not a client-guessed calendar bound.
     */
    private function coverage(Collection $records, FiscalYear $year, ?FiscalMonth $month): array
    {
        $today = Carbon::today();

        if ($month !== null) {
            $periodStart = Carbon::parse($month->getStartDateForYear($year->year));
            $periodEnd = Carbon::parse($month->getEndDateForYear($year->year));
        } else {
            $periodStart = Carbon::parse($year->start_date);
            $periodEnd = Carbon::parse($year->end_date);
        }

        $elapsedEnd = $today->lt($periodEnd) ? $today : $periodEnd;

        $elapsed = $elapsedEnd->lt($periodStart)
            ? 0
            : CarbonPeriod::create($periodStart, '1 day', $elapsedEnd)->filter(fn (Carbon $d) => $d->isSunday())->count();

        $recorded = $records->unique('service_date')->count();

        return [
            'recorded' => $recorded,
            'elapsed' => $elapsed,
            'percentage' => $elapsed > 0 ? round(($recorded / $elapsed) * 100, 1) : ($recorded > 0 ? 100.0 : 0.0),
        ];
    }

    /**
     * Plain-English coverage + trend sentences for the Sunday Service tab.
     * The trend sentence only applies when viewing the whole year (a single
     * selected month has nothing to compare itself against).
     */
    private function sundayInsights(Collection $records, ?FiscalMonth $month, array $coverage): array
    {
        $insights = [];
        $missed = max(0, $coverage['elapsed'] - $coverage['recorded']);

        if ($coverage['elapsed'] > 0) {
            if ($missed > 0) {
                $period = $month ? 'this month' : 'this year';
                $insights[] = $missed.' Sunday'.($missed === 1 ? '' : 's')." weren't recorded {$period} - worth a reminder to the pastor.";
            } else {
                $period = $month ? 'this month' : 'this year';
                $insights[] = "Attendance has been recorded every Sunday {$period}.";
            }
        }

        if ($month === null) {
            $byDate = $records->unique('service_date');

            if ($byDate->isNotEmpty()) {
                $overallAvg = $byDate->sum(fn ($r) => $this->totalFor($r)) / $byDate->count();
                $latest = $byDate->sortByDesc('service_date')->first();
                $latestMonthNumber = (int) Carbon::parse($latest->service_date)->format('n');
                $latestMonthRecords = $byDate->filter(fn ($r) => (int) Carbon::parse($r->service_date)->format('n') === $latestMonthNumber);
                $latestMonthAvg = $latestMonthRecords->sum(fn ($r) => $this->totalFor($r)) / $latestMonthRecords->count();

                if ($overallAvg > 0) {
                    $diffPct = round((($latestMonthAvg - $overallAvg) / $overallAvg) * 100);

                    if (abs($diffPct) >= self::TREND_NOTICE_THRESHOLD_PERCENT) {
                        $direction = $diffPct > 0 ? 'above' : 'below';
                        $insights[] = 'Attendance in '.self::MONTH_NAMES[$latestMonthNumber - 1].' is '.abs($diffPct)."% {$direction} your usual for this year.";
                    }
                }
            }
        }

        return $insights;
    }

    /**
     * A compact 3-column strip for the Sunday Service tab (it has no
     * "types" to rank, so it gets this instead of the breakdown table's
     * ranked columns) - mirrors a compact inline stat-column pattern
     * rather than another full card row.
     */
    private function sundayStatColumns(Collection $records): array
    {
        $byDate = $records->unique('service_date');

        if ($byDate->isEmpty()) {
            return [];
        }

        $byMonth = $byDate->groupBy(fn ($r) => (int) Carbon::parse($r->service_date)->format('n'))
            ->map(fn (Collection $group) => $group->sum(fn ($r) => $this->totalFor($r)));

        $bestMonthNum = $byMonth->sortDesc()->keys()->first();
        $weeklyAvg = round($byDate->sum(fn ($r) => $this->totalFor($r)) / $byDate->count(), 1);

        $months = $byMonth->keys()->sort()->values();
        $lastMonthNum = $months->last();
        $prevMonthNum = $months->count() > 1 ? $months->slice(-2, 1)->first() : null;

        $thisVsLast = null;
        if ($prevMonthNum !== null && $byMonth[$prevMonthNum] > 0) {
            $thisVsLast = round((($byMonth[$lastMonthNum] - $byMonth[$prevMonthNum]) / $byMonth[$prevMonthNum]) * 100);
        }

        return [
            ['label' => 'Best Month', 'value' => self::MONTH_NAMES[$bestMonthNum - 1]." ({$byMonth[$bestMonthNum]})"],
            ['label' => 'Weekly Average', 'value' => (string) $weeklyAvg],
            ['label' => 'This Month vs. Last', 'value' => $thisVsLast === null ? '-' : ($thisVsLast >= 0 ? '+' : '').$thisVsLast.'%'],
        ];
    }

    /**
     * One row per this church's configured gathering type in this
     * category, including types with zero records this period - the
     * direct answer to "Kesha attendance this year, and when we didn't
     * have any." Sorted by total attendance descending, same "ranked list"
     * shape as a Top Selling Products table.
     */
    private function breakdown(int $territoryId, GatheringCategory $category, Collection $records): array
    {
        $types = GatheringType::where('territory_id', $territoryId)
            ->where('gathering_category_id', $category->id)
            ->orderBy('display_order')
            ->get();

        return $types->map(function (GatheringType $type) use ($records) {
            $typeRecords = $records->where('gathering_type_id', $type->id);
            $total = $typeRecords->sum(fn ($r) => $this->totalFor($r));
            $last = $typeRecords->sortByDesc('service_date')->first();
            $lastDate = $last ? Carbon::parse($last->service_date) : null;

            return [
                'name' => $type->name,
                'icon' => $type->icon,
                'times_held' => $typeRecords->count(),
                'total_attendance' => $total,
                'average_attendance' => $typeRecords->count() ? round($total / $typeRecords->count(), 1) : 0,
                'last_held' => $lastDate?->format('j M Y'),
                'status' => $this->breakdownStatus($typeRecords->count(), $lastDate),
            ];
        })->sortByDesc('total_attendance')->values()->all();
    }

    private function breakdownStatus(int $timesHeld, ?Carbon $lastDate): string
    {
        if ($timesHeld === 0 || $lastDate === null) {
            return 'never_held';
        }

        return $lastDate->diffInDays(Carbon::today()) > self::INACTIVE_DAYS_THRESHOLD ? 'inactive' : 'on_track';
    }

    private function breakdownStats(Collection $records, array $breakdown): array
    {
        $total = $records->sum(fn ($r) => $this->totalFor($r));
        $held = collect($breakdown)->filter(fn ($b) => $b['times_held'] > 0);
        $mostActive = $held->sortByDesc('times_held')->first();
        $spark = $this->sparkline($records);

        return [
            ['label' => 'Gathering Types Held', 'value' => $held->count().' of '.count($breakdown), 'icon' => 'ri-list-check-2', 'color' => 'primary', 'sparkline' => $spark],
            ['label' => 'Total Gatherings Recorded', 'value' => $records->count(), 'icon' => 'ri-calendar-check-line', 'color' => 'secondary', 'sparkline' => $spark],
            ['label' => 'Total Attendance', 'value' => $total, 'icon' => 'ri-group-line', 'color' => 'success', 'sparkline' => $spark],
            ['label' => 'Most Active Type', 'value' => $mostActive ? $mostActive['name'].' ('.$mostActive['times_held'].'x)' : '-', 'icon' => 'ri-trophy-line', 'color' => 'warning', 'sparkline' => $spark],
        ];
    }

    /**
     * Plain-English summary of the breakdown table's `status` column - the
     * top-of-tab callout that makes the whole tab read as analysis, not
     * just a table of numbers.
     */
    private function breakdownInsights(array $breakdown): array
    {
        $insights = [];
        $inactive = collect($breakdown)->where('status', 'inactive');
        $neverHeld = collect($breakdown)->where('status', 'never_held');

        if ($inactive->isNotEmpty()) {
            $insights[] = $inactive->count().' gathering type'.($inactive->count() === 1 ? '' : 's')." haven't met in over ".self::INACTIVE_DAYS_THRESHOLD.' days: '.$inactive->pluck('name')->implode(', ').'.';
        }

        if ($neverHeld->isNotEmpty() && $neverHeld->count() < count($breakdown)) {
            $insights[] = $neverHeld->count().' gathering type'.($neverHeld->count() === 1 ? '' : 's').' not held this period: '.$neverHeld->pluck('name')->implode(', ').'.';
        }

        if (empty($insights) && count($breakdown) > 0) {
            $insights[] = 'All configured gathering types are active and on track.';
        }

        return $insights;
    }

    /**
     * Jan-Dec monthly totals normally; when a specific month is selected,
     * one bar per recorded date within that month instead - the concrete
     * "per Sunday / per week" drill-down.
     */
    private function trendChart(Collection $records, FiscalYear $year, ?FiscalMonth $month): array
    {
        if ($month !== null) {
            $byDate = $records->unique('service_date')->sortBy('service_date');

            return [
                'categories' => $byDate->map(fn ($r) => Carbon::parse($r->service_date)->format('j M'))->values()->all(),
                'series' => [['name' => 'Attendance', 'data' => $byDate->map(fn ($r) => $this->totalFor($r))->values()->all()]],
            ];
        }

        $monthly = array_fill(1, 12, 0);

        foreach ($records->unique('service_date') as $r) {
            $monthly[(int) Carbon::parse($r->service_date)->format('n')] += $this->totalFor($r);
        }

        return [
            'categories' => self::MONTH_NAMES,
            'series' => [['name' => 'Attendance', 'data' => array_values($monthly)]],
        ];
    }
}
