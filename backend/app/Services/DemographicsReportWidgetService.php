<?php

namespace App\Services;

use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;

/**
 * Backs the Spiritual Activities and Monthly Statistics church-tier report
 * pages - the Church-level sibling of AttendanceReportWidgetService, not an
 * extension of DemographicsGrowthService (that class rolls up *multiple*
 * descendant churches for overseers; a pastor looking at their own church
 * needs a single church's month-by-month series instead, the same split
 * AttendanceReportController/AttendanceReportWidgetService already draws
 * from the plain CRUD AttendanceController).
 *
 * Only 'approved' ChurchDemographic rows count - submissions auto-approve on
 * submit() today (no live reviewer step, see DemographicsController::submit()),
 * so in practice a fiscal month is either 'draft' (in progress, not shown
 * here) or 'approved' (done), or has no row at all. A month with no approved
 * row reports as no data (null), never a fabricated 0 - the same principle
 * AttendanceReportWidgetService already applies to its own trend badges and
 * averages.
 */
class DemographicsReportWidgetService
{
    private const SPIRITUAL_METRICS = [
        'baptisms_count' => ['label' => 'Baptisms', 'icon' => 'ri-drop-line', 'color' => 'primary'],
        'communion_participants_count' => ['label' => 'Communion', 'icon' => 'ri-cup-line', 'color' => 'warning'],
        'conversions_count' => ['label' => 'New Converts', 'icon' => 'ri-user-add-line', 'color' => 'success'],
        'transferred_out_count' => ['label' => 'Departures', 'icon' => 'ri-user-unfollow-line', 'color' => 'danger'],
    ];

    private const MEMBERSHIP_COLUMNS = [
        'total_members', 'male_count', 'female_count', 'youth_count',
        'mens_fellowship_count', 'womens_fellowship_count',
        'sunday_school_male_count', 'sunday_school_female_count', 'seniors_count',
        'new_members_count', 'transferred_out_count',
        'baptisms_count', 'communion_participants_count', 'conversions_count',
    ];

    public function widgetsFor(int $territoryId, FiscalYear $year): array
    {
        $rowsByMonth = $this->rowsByMonth($territoryId, $year);

        return [
            'months' => $this->monthlyRows($rowsByMonth),
            'stats' => $this->stats($rowsByMonth),
            'spiritual' => $this->spiritualActivityWidgets($rowsByMonth),
        ];
    }

    /**
     * @return Collection<int, array{month: FiscalMonth, row: ChurchDemographic|null}>
     */
    private function rowsByMonth(int $territoryId, FiscalYear $year): Collection
    {
        $rows = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id)
            ->where('status', 'approved')
            ->get()
            ->keyBy('fiscal_month_id');

        return FiscalMonth::orderBy('number')->get()
            ->map(fn (FiscalMonth $month) => ['month' => $month, 'row' => $rows->get($month->id)]);
    }

    private function monthlyRows(Collection $rowsByMonth): array
    {
        return $rowsByMonth->map(function (array $entry) {
            $month = $entry['month'];
            $row = $entry['row'];

            $base = [
                'fiscal_month_id' => $month->id,
                'month' => $month->short_name,
                'status' => $row ? 'approved' : 'not_submitted',
            ];

            foreach (self::MEMBERSHIP_COLUMNS as $column) {
                $base[$column] = $row?->{$column};
            }

            return $base;
        })->values()->all();
    }

    private function stats(Collection $rowsByMonth): array
    {
        $approved = $rowsByMonth->pluck('row')->filter();

        $latest = $approved->sortByDesc(fn (ChurchDemographic $r) => $r->fiscal_month_id)->first();
        $avgMembers = $approved->isNotEmpty() ? round($approved->avg('total_members')) : null;

        return [
            ['label' => 'Months Reported', 'value' => $approved->count().' of 12', 'icon' => 'ri-calendar-check-line', 'color' => 'primary'],
            ['label' => 'Latest Total Members', 'value' => $latest?->total_members ?? '-', 'icon' => 'ri-team-line', 'color' => 'success'],
            ['label' => 'Average Members', 'value' => $avgMembers ?? '-', 'icon' => 'ri-bar-chart-line', 'color' => 'warning'],
        ];
    }

    private function spiritualActivityWidgets(Collection $rowsByMonth): array
    {
        $categories = $rowsByMonth->pluck('month.short_name')->values()->all();
        $approved = $rowsByMonth->pluck('row')->filter();

        return collect(self::SPIRITUAL_METRICS)->map(function (array $meta, string $column) use ($rowsByMonth, $categories, $approved) {
            $data = $rowsByMonth->map(fn (array $entry) => $entry['row']?->{$column} ?? 0)->values()->all();

            $total = $approved->sum($column);
            $reportedCount = $approved->filter(fn (ChurchDemographic $r) => $r->{$column} !== null)->count();
            $average = $reportedCount ? round($total / $reportedCount, 1) : null;

            $best = $rowsByMonth->filter(fn (array $entry) => $entry['row'] !== null)
                ->sortByDesc(fn (array $entry) => $entry['row']->{$column})
                ->first();
            $bestLabel = $best ? "{$best['month']->short_name} ({$best['row']->{$column}})" : '-';

            return [
                'metric' => $column,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'stats' => [
                    ['label' => 'Total This Year', 'value' => $total, 'icon' => $meta['icon'], 'color' => $meta['color']],
                    ['label' => 'Average per Month', 'value' => $average ?? '-', 'icon' => 'ri-bar-chart-line', 'color' => 'warning'],
                    ['label' => 'Best Month', 'value' => $bestLabel, 'icon' => 'ri-trophy-line', 'color' => 'success'],
                ],
                'chart' => [
                    'categories' => $categories,
                    'series' => [['name' => $meta['label'], 'data' => $data]],
                ],
            ];
        })->values()->all();
    }
}
