<?php

namespace App\Reports\Attendance;

use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The stretch of time an attendance page or report covers: a fiscal year,
 * one month of it, or all time (from the first record to today), plus the
 * period before it to compare with (none for all time).
 */
final class AttendancePeriod
{
    public const YEAR = 'year';

    public const MONTH = 'month';

    public const ALL = 'all';

    private function __construct(
        public readonly string $mode,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        public readonly ?CarbonImmutable $previousStart = null,
        public readonly ?CarbonImmutable $previousEnd = null,
        public readonly ?string $previousLabel = null,
    ) {}

    /**
     * @param  int|string|null  $fiscalYearId  a fiscal year id, or 'all'
     * @param  int[]  $churchIds  whose first record starts "all time"
     */
    public static function resolve(int|string|null $fiscalYearId, ?int $fiscalMonthId, array $churchIds): self
    {
        if ($fiscalYearId === 'all' || $fiscalYearId === null) {
            $first = ChurchAttendanceRecord::where('territory_type', 'church')
                ->whereIn('territory_id', $churchIds)
                ->min('service_date');
            $today = CarbonImmutable::today();

            return new self(self::ALL, $first ? CarbonImmutable::parse($first)->startOfDay() : $today, $today, 'All time');
        }

        $year = FiscalYear::findOrFail($fiscalYearId);
        $month = $fiscalMonthId ? FiscalMonth::findOrFail($fiscalMonthId) : null;

        if ($month) {
            $start = CarbonImmutable::parse($month->getStartDateForYear($year->year));
            $end = CarbonImmutable::parse($month->getEndDateForYear($year->year));
            $previous = $start->subMonthNoOverflow();

            return new self(
                self::MONTH,
                $start,
                $end,
                $start->format('F Y'),
                $previous->startOfMonth(),
                $previous->endOfMonth(),
                $previous->format('M Y'),
            );
        }

        $start = CarbonImmutable::parse($year->start_date);
        $end = CarbonImmutable::parse($year->end_date);

        return new self(
            self::YEAR,
            $start,
            $end,
            (string) $year->year,
            $start->subYear(),
            $end->subYear(),
            (string) ($year->year - 1),
        );
    }

    /** The last day that has already happened - Sundays after it aren't "missing" yet. */
    public function elapsedEnd(): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        return $this->end->lt($today) ? $this->end : $today;
    }

    /** "Jan 2026" style label for a month in this period's charts. */
    public static function monthLabel(Carbon|CarbonImmutable $date, bool $withYear): string
    {
        return $withYear ? $date->format('M Y') : $date->format('M');
    }

    public function spansYears(): bool
    {
        return $this->start->year !== $this->end->year;
    }
}
