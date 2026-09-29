<?php

namespace App\Reports\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalSemiAnnual;
use App\Models\FiscalYear;
use App\Reports\ReportContext;
use App\Services\DemographicsReportWidgetService;
use App\Support\Reports\Insights\ReportFacts;
use Illuminate\Support\Collection;

/**
 * The numbers every Demographics report works from, loaded once per report.
 * Only approved submissions count - the same rule the on-screen pages use.
 */
final class DemographicsData
{
    public const HEADCOUNTS = [
        'total_members', 'male_count', 'female_count', 'youth_count', 'seniors_count',
        'womens_fellowship_count', 'mens_fellowship_count',
        'sunday_school_male_count', 'sunday_school_female_count', 'sunday_school_teachers_count',
    ];

    public const FLOWS = [
        'new_members_count', 'transferred_out_count', 'baptisms_count', 'communion_participants_count', 'conversions_count',
    ];

    public const LABELS = [
        'total_members' => 'Total members',
        'male_count' => 'Male',
        'female_count' => 'Female',
        'youth_count' => 'Youth (13-35)',
        'seniors_count' => 'Seniors (60+)',
        'womens_fellowship_count' => "Women's fellowship",
        'mens_fellowship_count' => "Men's fellowship",
        'sunday_school_male_count' => 'Sunday school boys',
        'sunday_school_female_count' => 'Sunday school girls',
        'sunday_school_teachers_count' => 'Sunday school teachers',
        'new_members_count' => 'New members',
        'transferred_out_count' => 'Transferred out',
        'baptisms_count' => 'Baptisms',
        'communion_participants_count' => 'Holy Communion',
        'conversions_count' => 'Conversions',
    ];

    public function __construct(private DemographicsReportWidgetService $widgets) {}

    public function mode(ReportContext $context): string
    {
        return Church::find($context->territory->id)?->getDemographicsMode() ?? 'monthly';
    }

    public static function periodNoun(string $mode): string
    {
        return match ($mode) {
            'half_yearly' => 'half-year',
            'yearly' => 'year',
            default => 'month',
        };
    }

    public static function cadenceLabel(string $mode): string
    {
        return match ($mode) {
            'half_yearly' => 'Half-yearly',
            'yearly' => 'Yearly',
            default => 'Monthly',
        };
    }

    /**
     * Every period of a fiscal year that is already due (future periods are
     * left out), as ['label', 'status', ...figures] with null for a period
     * that has no approved submission.
     */
    public function yearPeriods(ReportContext $context, FiscalYear $year): array
    {
        $mode = $this->mode($context);
        $rows = $this->widgets->widgetsFor($context->territory->id, $year)['months'];
        $numbers = match ($mode) {
            'monthly' => FiscalMonth::pluck('number', 'id'),
            'half_yearly' => FiscalSemiAnnual::where('fiscal_year_id', $year->id)->pluck('number', 'id'),
            default => collect(),
        };

        $now = now();
        $yearNumber = (int) $year->year;
        $periods = [];
        foreach ($rows as $row) {
            $endMonth = match ($mode) {
                'monthly' => (int) ($numbers[$row['period_id']] ?? 12),
                'half_yearly' => (int) ($numbers[$row['period_id']] ?? 2) * 6,
                default => 12,
            };
            $due = $yearNumber < $now->year || ($yearNumber === $now->year && $endMonth <= $now->month) || $row['status'] === 'approved';
            if (! $due) {
                continue;
            }
            $label = $mode === 'monthly' ? "{$row['month']} {$yearNumber}" : $row['month'];
            $periods[] = ['label' => $label] + $row;
        }

        return $periods;
    }

    /**
     * Every approved submission, oldest first, in the same shape as
     * yearPeriods() - what the fiscal-year reports use for "All time".
     */
    public function allTimePeriods(ReportContext $context): array
    {
        return $this->approvedRows($context)->map(function (ChurchDemographic $r) {
            $row = ['label' => self::label($r), 'month' => self::label($r), 'status' => 'approved', 'year' => (int) $r->fiscalYear?->year];
            foreach ([...self::HEADCOUNTS, ...self::FLOWS] as $field) {
                $row[$field] = $r->{$field};
            }

            return $row;
        })->values()->all();
    }

    /**
     * Approved submissions for the report's churches, oldest first, ordered by
     * the month each period ends in so mixed cadences still line up.
     *
     * @return Collection<int, ChurchDemographic>
     */
    public function approvedRows(ReportContext $context, ?int $fromYear = null): Collection
    {
        return ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])
            ->where('territory_type', 'church')
            ->whereIn('territory_id', $context->churchIds())
            ->where('status', 'approved')
            ->get()
            ->filter(fn (ChurchDemographic $r) => $fromYear === null || ($r->fiscalYear?->year ?? 0) >= $fromYear)
            ->sortBy(fn (ChurchDemographic $r) => ($r->fiscalYear?->year ?? 0) * 100 + self::endMonth($r))
            ->values();
    }

    public static function endMonth(ChurchDemographic $r): int
    {
        if ($r->fiscalMonth) {
            return (int) $r->fiscalMonth->number;
        }
        if ($r->fiscalSemiAnnual) {
            return (int) $r->fiscalSemiAnnual->number * 6;
        }

        return 12;
    }

    public static function label(ChurchDemographic $r): string
    {
        if ($r->fiscalMonth) {
            return trim(($r->fiscalMonth->short_name ?: $r->fiscalMonth->name).' '.($r->fiscalYear?->year ?? ''));
        }
        if ($r->fiscalSemiAnnual) {
            return $r->fiscalSemiAnnual->name;
        }

        return 'Year '.($r->fiscalYear?->year ?? '');
    }

    /** A submission as a plain figures array for insight rules. */
    public static function toFigures(ChurchDemographic $r): array
    {
        $out = ['label' => self::label($r)];
        foreach ([...self::HEADCOUNTS, ...self::FLOWS] as $field) {
            $out[$field] = $r->{$field};
        }

        return $out;
    }

    /** Facts for the insight rules from period rows (oldest first) and missing labels. */
    public static function facts(array $figureRows, array $missing = [], string $mode = 'monthly'): ReportFacts
    {
        $reported = array_values(array_filter($figureRows, fn ($r) => ($r['total_members'] ?? null) !== null));

        return new ReportFacts([
            'rows' => $reported,
            'latest' => $reported === [] ? null : end($reported),
            'missing' => $missing,
            'period_noun' => self::periodNoun($mode),
        ]);
    }

    public static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
