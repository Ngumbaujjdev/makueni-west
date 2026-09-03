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
 */
class AttendanceReportWidgetService
{
    private const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
            return [
                'stats' => $this->sundayStats($records, $year, $month),
                'coverage' => $this->coverage($records, $year, $month),
                'chart' => $this->trendChart($records, $year, $month),
            ];
        }

        $breakdown = $this->breakdown($territoryId, $category, $records);

        return [
            'stats' => $this->breakdownStats($records, $breakdown),
            'breakdown' => $breakdown,
        ];
    }

    private function recordsFor(int $territoryId, ?GatheringCategory $category, FiscalYear $year, ?FiscalMonth $month): Collection
    {
        $query = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id);

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
     * simple (see the round-3 plan note this mirrors).
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

        return [
            ['label' => 'Total Gatherings Recorded', 'value' => $records->count(), 'icon' => 'ri-calendar-check-line', 'color' => 'primary', 'sparkline' => $spark],
            ['label' => 'Total Attendance', 'value' => $total, 'icon' => 'ri-group-line', 'color' => 'success', 'sparkline' => $spark],
            ['label' => 'Overall Average', 'value' => $avg, 'icon' => 'ri-bar-chart-line', 'color' => 'warning', 'sparkline' => $spark],
        ];
    }

    private function sundayStats(Collection $records, FiscalYear $year, ?FiscalMonth $month): array
    {
        $byDate = $records->unique('service_date');
        $total = $byDate->sum(fn ($r) => $this->totalFor($r));
        $avg = $byDate->count() ? round($total / $byDate->count(), 1) : 0;
        $spark = $this->sparkline($records);

        $highest = $byDate->sortByDesc(fn ($r) => $this->totalFor($r))->first();
        $highestLabel = $highest
            ? $this->totalFor($highest).' on '.Carbon::parse($highest->service_date)->format('j M')
            : '-';

        $coverage = $this->coverage($records, $year, $month);

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

    /**
     * One row per this church's configured gathering type in this
     * category, including types with zero records this period - the
     * direct answer to "Kesha attendance this year, and when we didn't
     * have any."
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

            return [
                'name' => $type->name,
                'icon' => $type->icon,
                'times_held' => $typeRecords->count(),
                'total_attendance' => $total,
                'average_attendance' => $typeRecords->count() ? round($total / $typeRecords->count(), 1) : 0,
                'last_held' => $last ? Carbon::parse($last->service_date)->format('j M Y') : null,
            ];
        })->values()->all();
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
}
