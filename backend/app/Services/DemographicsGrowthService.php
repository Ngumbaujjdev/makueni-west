<?php

namespace App\Services;

use App\Enums\TerritoryType;
use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Territory;

/**
 * The one deliberate exception to this codebase's "no service layer, fat
 * controllers like BudgetController" convention (see DemographicsController
 * docblock): the two-source growth computation below (monthly total_members
 * delta + weekly attendance average) is reused verbatim by Subregion,
 * Region, and Diocese summary endpoints - worth one small shared class
 * rather than copy-pasting it three times.
 */
class DemographicsGrowthService
{
    /**
     * Every descendant church of a territory, regardless of depth - handles
     * both Region -> Subregion -> Church and Region -> Church directly
     * (real in this data set, per the audit: not every church has a
     * subregion parent).
     */
    public function descendantChurchIds(Territory $territory): array
    {
        if ($territory->territory_type === TerritoryType::CHURCH) {
            return [$territory->id];
        }

        return $territory->getAllChildren()
            ->filter(fn ($t) => $t->territory_type === TerritoryType::CHURCH)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Rolled-up membership/spiritual-activity totals, attendance summary,
     * and growth-vs-previous-month, for every descendant church of the
     * given territory. Only 'approved' demographics submissions count
     * toward the rollup - draft/submitted/flagged/changes_requested rows
     * are excluded so the summary reflects reviewed data.
     */
    public function summaryFor(Territory $territory, int $fiscalYearId, int $fiscalMonthId): array
    {
        $churchIds = $this->descendantChurchIds($territory);

        $demographics = ChurchDemographic::whereIn('territory_id', $churchIds)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('fiscal_month_id', $fiscalMonthId)
            ->where('status', 'approved')
            ->get();

        $membership = [
            'total_members' => (int) $demographics->sum('total_members'),
            'male_count' => (int) $demographics->sum('male_count'),
            'female_count' => (int) $demographics->sum('female_count'),
            'youth_count' => (int) $demographics->sum('youth_count'),
            'womens_fellowship_count' => (int) $demographics->sum('womens_fellowship_count'),
            'mens_fellowship_count' => (int) $demographics->sum('mens_fellowship_count'),
            'sunday_school_male_count' => (int) $demographics->sum('sunday_school_male_count'),
            'sunday_school_female_count' => (int) $demographics->sum('sunday_school_female_count'),
            'seniors_count' => (int) $demographics->sum('seniors_count'),
            'baptisms_count' => (int) $demographics->sum('baptisms_count'),
            'communion_participants_count' => (int) $demographics->sum('communion_participants_count'),
            'conversions_count' => (int) $demographics->sum('conversions_count'),
            'churches_reporting' => $demographics->count(),
            'churches_total' => count($churchIds),
        ];

        $attendanceRecords = ChurchAttendanceRecord::whereIn('territory_id', $churchIds)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('fiscal_month_id', $fiscalMonthId)
            ->sundayServices()
            ->get();

        $attendance = [
            'services_logged' => $attendanceRecords->count(),
            // null (not 0) when nothing was logged - a real "no data" state,
            // distinct from a genuinely empty service. Matters for churches
            // in monthly_only mode: no special-casing needed here, they
            // simply contribute no attendance rows and this stays null.
            'average_attendance' => $attendanceRecords->isEmpty()
                ? null
                : round($attendanceRecords->sum(fn ($r) => $r->total_count) / $attendanceRecords->count(), 1),
        ];

        return [
            'membership' => $membership,
            'attendance' => $attendance,
            'growth' => $this->growthVsPreviousMonth($churchIds, $fiscalYearId, $fiscalMonthId, $membership['total_members']),
        ];
    }

    private function growthVsPreviousMonth(array $churchIds, int $fiscalYearId, int $fiscalMonthId, int $currentTotal): array
    {
        $fiscalMonth = FiscalMonth::find($fiscalMonthId);
        $fiscalYear = FiscalYear::find($fiscalYearId);

        if (!$fiscalMonth || !$fiscalYear) {
            return ['previous_month_total_members' => 0, 'delta' => $currentTotal, 'percentage' => null];
        }

        $previousMonthNumber = $fiscalMonth->number === 1 ? 12 : $fiscalMonth->number - 1;
        $previousYear = $fiscalMonth->number === 1
            ? FiscalYear::where('year', $fiscalYear->year - 1)->first()
            : $fiscalYear;
        $previousMonth = FiscalMonth::where('number', $previousMonthNumber)->first();

        $previousTotal = 0;

        if ($previousYear && $previousMonth) {
            $previousTotal = (int) ChurchDemographic::whereIn('territory_id', $churchIds)
                ->where('fiscal_year_id', $previousYear->id)
                ->where('fiscal_month_id', $previousMonth->id)
                ->where('status', 'approved')
                ->sum('total_members');
        }

        $delta = $currentTotal - $previousTotal;

        return [
            'previous_month_total_members' => $previousTotal,
            'delta' => $delta,
            'percentage' => $previousTotal > 0 ? round(($delta / $previousTotal) * 100, 1) : null,
        ];
    }
}
