<?php

namespace App\Services;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalSemiAnnual;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;

/**
 * Backs the Spiritual Activities and Monthly Statistics church-tier report
 * pages - the Church-level sibling of AttendanceReportWidgetService, not an
 * extension of DemographicsGrowthService (that class rolls up *multiple*
 * descendant churches for overseers; a pastor looking at their own church
 * needs a single church's period-by-period series instead, the same split
 * AttendanceReportController/AttendanceReportWidgetService already draws
 * from the plain CRUD AttendanceController).
 *
 * Cadence-aware (monthly/half_yearly/yearly) as of 2026-09-14 - a church's
 * period breakdown used to always assume 12 fiscal months regardless of its
 * actual configured demographics_mode, which showed 11 permanently
 * "Not Submitted" months for a half-yearly church that was never expected
 * to submit monthly at all. rowsByPeriod() is the one place that now
 * branches on cadence; every consumer below it (periodRows/stats/
 * spiritualActivityWidgets) works off its generalized {period_id, label,
 * row} shape instead of FiscalMonth directly, so this stays a single
 * change point. Response keys/field names (`months`, each row's `month`
 * key) are unchanged on purpose - only their *content* is now cadence-
 * correct - so existing consumers (Monthly Statistics, Spiritual
 * Activities) need no changes to inherit this fix.
 *
 * Only 'approved' ChurchDemographic rows count - submissions auto-approve on
 * submit() today (no live reviewer step, see DemographicsController::submit()),
 * so in practice a period is either 'draft' (in progress, not shown
 * here) or 'approved' (done), or has no row at all. A period with no approved
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

    /** How many periods a church is expected to submit per fiscal year at each cadence - mirrors the frontend's PERIODS_PER_YEAR (assets/js/pages/demographics/index.js). */
    private const EXPECTED_PERIODS = [
        'monthly' => 12,
        'half_yearly' => 2,
        'yearly' => 1,
    ];

    public function widgetsFor(int $territoryId, FiscalYear $year): array
    {
        $mode = Church::find($territoryId)?->getDemographicsMode() ?? 'monthly';
        $rowsByPeriod = $this->rowsByPeriod($territoryId, $year, $mode);

        return [
            'months' => $this->periodRows($rowsByPeriod),
            'stats' => $this->stats($rowsByPeriod, $mode),
            'spiritual' => $this->spiritualActivityWidgets($rowsByPeriod, $mode),
        ];
    }

    /**
     * @return Collection<int, array{period_id: int|null, label: string, row: ChurchDemographic|null}>
     */
    private function rowsByPeriod(int $territoryId, FiscalYear $year, string $mode): Collection
    {
        return match ($mode) {
            'half_yearly' => $this->rowsByHalf($territoryId, $year),
            'yearly' => $this->rowsByYear($territoryId, $year),
            default => $this->rowsByMonth($territoryId, $year),
        };
    }

    private function rowsByMonth(int $territoryId, FiscalYear $year): Collection
    {
        $rows = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id)
            ->where('status', 'approved')
            ->get()
            ->keyBy('fiscal_month_id');

        return FiscalMonth::orderBy('number')->get()
            ->map(fn (FiscalMonth $month) => [
                'period_id' => $month->id,
                'label' => $month->short_name,
                'row' => $rows->get($month->id),
            ])
            ->values();
    }

    private function rowsByHalf(int $territoryId, FiscalYear $year): Collection
    {
        $rows = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id)
            ->where('status', 'approved')
            ->get()
            ->keyBy('fiscal_semi_annual_id');

        return FiscalSemiAnnual::where('fiscal_year_id', $year->id)->orderBy('number')->get()
            ->map(fn (FiscalSemiAnnual $half) => [
                'period_id' => $half->id,
                'label' => $half->name,
                'row' => $rows->get($half->id),
            ])
            ->values();
    }

    /** One synthetic period for the whole fiscal year - matched to the one approved row (if any) that has neither a fiscal_month_id nor a fiscal_semi_annual_id, the shape a yearly-cadence submission is stored in. */
    private function rowsByYear(int $territoryId, FiscalYear $year): Collection
    {
        $row = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('fiscal_year_id', $year->id)
            ->where('status', 'approved')
            ->whereNull('fiscal_month_id')
            ->whereNull('fiscal_semi_annual_id')
            ->first();

        return collect([
            ['period_id' => null, 'label' => "Year {$year->year}", 'row' => $row],
        ]);
    }

    private function periodRows(Collection $rowsByPeriod): array
    {
        return $rowsByPeriod->map(function (array $entry) {
            $row = $entry['row'];

            $base = [
                'period_id' => $entry['period_id'],
                'month' => $entry['label'],
                'status' => $row ? 'approved' : 'not_submitted',
            ];

            foreach (self::MEMBERSHIP_COLUMNS as $column) {
                $base[$column] = $row?->{$column};
            }

            return $base;
        })->values()->all();
    }

    private function stats(Collection $rowsByPeriod, string $mode): array
    {
        $approved = $rowsByPeriod->pluck('row')->filter();
        $expected = self::EXPECTED_PERIODS[$mode] ?? 12;
        $reportedLabel = match ($mode) {
            'half_yearly' => 'Halves Reported',
            'yearly' => 'Years Reported',
            default => 'Months Reported',
        };

        // rowsByPeriod is already in chronological order (whichever builder
        // produced it), so the latest period with an approved row is simply
        // the last one found - no need to sort by a period id that doesn't
        // even exist for the yearly cadence.
        $latestEntry = $rowsByPeriod->filter(fn (array $entry) => $entry['row'] !== null)->last();
        $latest = $latestEntry['row'] ?? null;
        $avgMembers = $approved->isNotEmpty() ? round($approved->avg('total_members')) : null;

        return [
            ['label' => $reportedLabel, 'value' => $approved->count()." of {$expected}", 'icon' => 'ri-calendar-check-line', 'color' => 'primary'],
            ['label' => 'Latest Total Members', 'value' => $latest?->total_members ?? '-', 'icon' => 'ri-team-line', 'color' => 'success'],
            ['label' => 'Average Members', 'value' => $avgMembers ?? '-', 'icon' => 'ri-bar-chart-line', 'color' => 'warning'],
        ];
    }

    private function spiritualActivityWidgets(Collection $rowsByPeriod, string $mode): array
    {
        $categories = $rowsByPeriod->pluck('label')->values()->all();
        $approved = $rowsByPeriod->pluck('row')->filter();
        $periodNoun = match ($mode) {
            'half_yearly' => 'Half',
            'yearly' => 'Year',
            default => 'Month',
        };

        return collect(self::SPIRITUAL_METRICS)->map(function (array $meta, string $column) use ($rowsByPeriod, $categories, $approved, $periodNoun) {
            $data = $rowsByPeriod->map(fn (array $entry) => $entry['row']?->{$column} ?? 0)->values()->all();

            $total = $approved->sum($column);
            $reportedCount = $approved->filter(fn (ChurchDemographic $r) => $r->{$column} !== null)->count();
            $average = $reportedCount ? round($total / $reportedCount, 1) : null;

            $best = $rowsByPeriod->filter(fn (array $entry) => $entry['row'] !== null)
                ->sortByDesc(fn (array $entry) => $entry['row']->{$column})
                ->first();
            $bestLabel = $best ? "{$best['label']} ({$best['row']->{$column}})" : '-';

            return [
                'metric' => $column,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'stats' => [
                    ['label' => 'Total This Year', 'value' => $total, 'icon' => $meta['icon'], 'color' => $meta['color']],
                    ['label' => "Average per {$periodNoun}", 'value' => $average ?? '-', 'icon' => 'ri-bar-chart-line', 'color' => 'warning'],
                    ['label' => "Best {$periodNoun}", 'value' => $bestLabel, 'icon' => 'ri-trophy-line', 'color' => 'success'],
                ],
                'chart' => [
                    'categories' => $categories,
                    'series' => [['name' => $meta['label'], 'data' => $data]],
                ],
            ];
        })->values()->all();
    }
}
