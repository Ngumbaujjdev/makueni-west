<?php

namespace App\Reports\Attendance;

use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Reports\Demographics\DemographicsData;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\ReportFacts;
use App\Support\Reports\Insights\Rules\AttendanceTrendRule;
use App\Support\Reports\Insights\Rules\AttendanceVsMembershipRule;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\PeakSundayRule;
use App\Support\Reports\Insights\Rules\QuietGatheringRule;
use App\Support\Reports\Insights\Rules\SundayCoverageRule;
use App\Support\Reports\Insights\Rules\TopGatheringRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * The numbers every attendance page and report works from, for a set of
 * churches over an AttendancePeriod - one church today, a region or the
 * diocese later (Sunday figures add the churches up per Sunday).
 *
 * Sums across Sundays aren't headcounts (the same congregation comes back
 * every week), so Sunday figures are averages per Sunday; ministries and
 * events are averages per meeting. "Missing" Sundays only start from each
 * church's first record - a church that started recording in March hasn't
 * "missed" January.
 */
final class AttendanceData
{
    public const GROUPS = [
        'adults_count' => 'Adults',
        'youth_count' => 'Youth',
        'children_male_count' => 'Boys',
        'children_female_count' => 'Girls',
    ];

    public const SUNDAY = 'sunday_service';

    public const MINISTRY = 'ministry_gathering';

    public const EVENT = 'special_event';

    /** A gathering that hasn't met in this many days is "Quiet". */
    public const QUIET_DAYS = 60;

    private ?Collection $records = null;

    private ?Collection $previousRecords = null;

    private ?array $firstRecordByChurch = null;

    /** @param int[] $churchIds */
    public function __construct(public readonly array $churchIds, public readonly AttendancePeriod $period) {}

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    public function records(): Collection
    {
        return $this->records ??= $this->recordsBetween($this->period->start, $this->period->end);
    }

    public function previousRecords(): ?Collection
    {
        if (! $this->period->previousStart) {
            return null;
        }

        return $this->previousRecords ??= $this->recordsBetween($this->period->previousStart, $this->period->previousEnd);
    }

    private function recordsBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return ChurchAttendanceRecord::with(['gatheringCategory', 'gatheringType'])
            ->where('territory_type', 'church')
            ->whereIn('territory_id', $this->churchIds)
            ->whereDate('service_date', '>=', $start->toDateString())
            ->whereDate('service_date', '<=', $end->toDateString())
            ->orderBy('service_date')
            ->get();
    }

    public static function total(ChurchAttendanceRecord|array $r): int
    {
        $total = 0;
        foreach (array_keys(self::GROUPS) as $field) {
            $total += (int) (is_array($r) ? ($r[$field] ?? 0) : $r->{$field});
        }

        return $total;
    }

    public function ofCategory(string $slug, ?Collection $records = null): Collection
    {
        return ($records ?? $this->records())->filter(fn ($r) => $r->gatheringCategory?->slug === $slug)->values();
    }

    // ------------------------------------------------------------------
    // Sundays
    // ------------------------------------------------------------------

    /**
     * One row per Sunday in the period (all churches added up), oldest first:
     * ['date', 'adults_count', ..., 'total', 'churches'].
     */
    public function sundays(?Collection $records = null): array
    {
        return $this->ofCategory(self::SUNDAY, $records)
            ->groupBy(fn ($r) => $r->service_date->toDateString())
            ->map(function (Collection $group, string $date) {
                $row = ['date' => $date];
                foreach (array_keys(self::GROUPS) as $field) {
                    $row[$field] = (int) $group->sum($field);
                }
                $row['total'] = self::total($row);
                $row['churches'] = $group->count();

                return $row;
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    public static function averageSunday(array $sundays): ?int
    {
        return $sundays === [] ? null : (int) round(array_sum(array_column($sundays, 'total')) / count($sundays));
    }

    /** First Sunday-service date per church (all time). */
    private function firstRecordByChurch(): array
    {
        return $this->firstRecordByChurch ??= ChurchAttendanceRecord::where('territory_type', 'church')
            ->whereIn('territory_id', $this->churchIds)
            ->whereHas('gatheringCategory', fn ($q) => $q->where('slug', self::SUNDAY))
            ->selectRaw('territory_id, MIN(service_date) as first_date')
            ->groupBy('territory_id')
            ->pluck('first_date', 'territory_id')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
    }

    /**
     * Sundays recorded vs Sundays that have happened so far in the period
     * (from each church's first record), and which ones are missing.
     */
    public function coverage(): array
    {
        $recordedPairs = $this->ofCategory(self::SUNDAY)
            ->map(fn ($r) => $r->territory_id.'|'.$r->service_date->toDateString())
            ->unique()
            ->flip();
        $elapsedEnd = $this->period->elapsedEnd();
        $elapsed = 0;
        $missing = [];

        foreach ($this->firstRecordByChurch() as $churchId => $first) {
            $from = CarbonImmutable::parse($first)->max($this->period->start);
            if ($from->gt($elapsedEnd)) {
                continue;
            }
            foreach (CarbonPeriod::create($from, $elapsedEnd) as $day) {
                if (! $day->isSunday()) {
                    continue;
                }
                $elapsed++;
                if (! isset($recordedPairs[$churchId.'|'.$day->toDateString()])) {
                    $missing[] = $day->toDateString();
                }
            }
        }

        $recorded = $elapsed - count($missing);

        return [
            'recorded' => $recorded,
            'elapsed' => $elapsed,
            'percentage' => $elapsed > 0 ? (int) round($recorded / $elapsed * 100) : null,
            'missing' => array_values(array_reverse(array_unique($missing))),
        ];
    }

    /** Month-by-month Sunday figures: Sundays recorded, average, best. */
    public function sundayMonths(array $sundays): array
    {
        $withYear = $this->period->spansYears();

        return collect($sundays)
            ->groupBy(fn ($s) => substr($s['date'], 0, 7))
            ->map(function (Collection $group, string $ym) use ($withYear) {
                $best = $group->sortByDesc('total')->first();
                $boys = (int) round($group->avg('children_male_count'));
                $girls = (int) round($group->avg('children_female_count'));
                $avg = (int) round($group->avg('total'));

                return [
                    'month' => $ym,
                    'label' => AttendancePeriod::monthLabel(CarbonImmutable::parse("{$ym}-01"), $withYear),
                    'sundays' => $group->count(),
                    'average' => $avg,
                    'best' => $best['total'],
                    'best_date' => $best['date'],
                    'boys' => $boys,
                    'girls' => $girls,
                    'children_share' => $avg > 0 ? (int) round(($boys + $girls) / $avg * 100) : 0,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Every Sunday of the last 12 months of the period (up to this month) on a month x
     * week-of-month grid: its total, 0 when it wasn't recorded, and nothing
     * for a 5th Sunday a month doesn't have or one still to come.
     */
    public function heatmap(array $sundays): array
    {
        $byDate = collect($sundays)->keyBy('date');
        // Months still to come have nothing to show - stop at this month.
        $end = $this->period->end->min(CarbonImmutable::today())->endOfMonth();
        $start = $end->subMonthsNoOverflow(11)->startOfMonth()->max($this->period->start->startOfMonth());
        $first = $this->firstRecordByChurch() ? min($this->firstRecordByChurch()) : null;
        $today = CarbonImmutable::today()->toDateString();
        $withYear = $start->year !== $end->year;
        $rows = [];

        for ($m = $start; $m->lte($end); $m = $m->addMonthNoOverflow()) {
            $cells = [];
            $day = $m->startOfMonth();
            while (! $day->isSunday()) {
                $day = $day->addDay();
            }
            for ($week = 1; $week <= 5; $week++, $day = $day->addWeek()) {
                $date = $day->toDateString();
                if ($day->month !== $m->month || $date > $today || ($first && $date < $first)) {
                    $cells[] = ['week' => $week, 'date' => null, 'total' => null];

                    continue;
                }
                $cells[] = ['week' => $week, 'date' => $date, 'total' => $byDate[$date]['total'] ?? 0];
            }
            $rows[] = ['label' => AttendancePeriod::monthLabel($m, $withYear), 'cells' => $cells];
        }

        return $rows;
    }

    /** Average Sunday by group (Adults / Youth / Boys / Girls). */
    public static function composition(array $sundays): array
    {
        $out = [];
        foreach (self::GROUPS as $field => $label) {
            $out[] = [
                'key' => $field,
                'label' => $label,
                'average' => $sundays === [] ? 0 : (int) round(array_sum(array_column($sundays, $field)) / count($sundays)),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Ministries and events
    // ------------------------------------------------------------------

    /**
     * One row per ministry / event: every active configured type (even if it
     * didn't meet) plus any one-off named gathering, most attended first.
     * The status comes from the last time it ever met, not just this period.
     */
    public function gatherings(string $slug): array
    {
        $category = GatheringCategory::where('slug', $slug)->first();
        if (! $category) {
            return [];
        }
        $records = $this->ofCategory($slug);
        $lastEver = ChurchAttendanceRecord::where('territory_type', 'church')
            ->whereIn('territory_id', $this->churchIds)
            ->where('gathering_category_id', $category->id)
            ->whereDate('service_date', '<=', $this->period->end->toDateString())
            ->get(['gathering_type_id', 'event_name', 'service_date'])
            ->groupBy(fn ($r) => self::gatheringKey($r))
            ->map(fn (Collection $g) => $g->max('service_date')->toDateString());

        $rows = [];
        GatheringType::whereIn('territory_id', $this->churchIds)
            ->where('gathering_category_id', $category->id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->each(function (GatheringType $t) use (&$rows) {
                $rows["t{$t->id}"] = ['name' => $t->name, 'icon' => $t->icon, 'records' => collect()];
            });
        foreach ($records as $r) {
            $key = self::gatheringKey($r);
            $rows[$key] ??= ['name' => $r->gatheringType?->name ?? $r->event_name ?? 'Gathering', 'icon' => $r->gatheringType?->icon, 'records' => collect()];
            $rows[$key]['records']->push($r);
        }

        return collect($rows)
            ->map(function (array $g, string $key) use ($lastEver) {
                $totals = $g['records']->map(fn ($r) => self::total($r));
                $last = $lastEver[$key] ?? null;

                return [
                    'name' => $g['name'],
                    'icon' => $g['icon'],
                    'times' => $totals->count(),
                    'total' => (int) $totals->sum(),
                    'average' => $totals->count() ? (int) round($totals->avg()) : 0,
                    'peak' => (int) ($totals->max() ?? 0),
                    'last' => $last,
                    'status' => self::gatheringStatus($last),
                ];
            })
            ->sortBy([['total', 'desc'], ['times', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    public static function gatheringKey($r): string
    {
        return $r->gathering_type_id ? "t{$r->gathering_type_id}" : 'n'.mb_strtolower(trim((string) $r->event_name));
    }

    public static function gatheringStatus(?string $last): string
    {
        if (! $last) {
            return 'never';
        }

        return CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::today()) > self::QUIET_DAYS ? 'quiet' : 'active';
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    /**
     * Total members from each church's latest approved demographics
     * submission up to the end of the period, and the share of them at an
     * average Sunday.
     */
    public function membership(?int $averageSunday): ?array
    {
        $cutoff = $this->period->end->year * 100 + $this->period->end->month;
        $latest = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])
            ->where('territory_type', 'church')
            ->whereIn('territory_id', $this->churchIds)
            ->where('status', 'approved')
            ->whereNotNull('total_members')
            ->get()
            ->filter(fn ($r) => ($r->fiscalYear?->year ?? 0) * 100 + DemographicsData::endMonth($r) <= $cutoff)
            ->groupBy('territory_id')
            ->map(fn (Collection $g) => $g->sortBy(fn ($r) => ($r->fiscalYear?->year ?? 0) * 100 + DemographicsData::endMonth($r))->last());

        if ($latest->isEmpty()) {
            return null;
        }
        $members = (int) $latest->sum('total_members');

        return [
            'total_members' => $members,
            'as_of' => $latest->count() === 1 ? DemographicsData::label($latest->first()) : null,
            'rate' => $members > 0 && $averageSunday !== null ? (int) round($averageSunday / $members * 100) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Everything, for the Attendance Analytics page
    // ------------------------------------------------------------------

    public function analytics(): array
    {
        $sundays = $this->sundays();
        $average = self::averageSunday($sundays);
        // A period before any records at all has nothing to compare with.
        $previous = $this->previousRecords();
        $previous = $previous && $previous->isNotEmpty() ? $previous : null;
        $previousAverage = $previous ? self::averageSunday($this->sundays($previous)) : null;
        $coverage = $this->coverage();
        $months = $this->sundayMonths($sundays);
        $ministries = $this->gatherings(self::MINISTRY);
        $events = $this->gatherings(self::EVENT);
        $membership = $this->membership($average);
        $top = collect($sundays)->sortByDesc('total')->take(3)->values()->all();
        $lowest = collect($sundays)->sortBy('total')->first();
        $held = $this->ofCategory(self::MINISTRY)->count() + $this->ofCategory(self::EVENT)->count();
        $heldBefore = $previous ? $this->ofCategory(self::MINISTRY, $previous)->count() + $this->ofCategory(self::EVENT, $previous)->count() : null;
        $boys = $sundays === [] ? 0 : (int) round(array_sum(array_column($sundays, 'children_male_count')) / count($sundays));
        $girls = $sundays === [] ? 0 : (int) round(array_sum(array_column($sundays, 'children_female_count')) / count($sundays));

        $facts = new ReportFacts([
            'coverage' => $coverage,
            'sunday_average' => $average,
            'previous_average' => $previousAverage,
            'previous_label' => $this->period->previousLabel,
            'period_label' => $this->period->label,
            'months' => $months,
            'top_sundays' => $top,
            'membership' => $membership,
            'latest' => ['children_male_count' => $boys, 'children_female_count' => $girls],
            'ministries' => $ministries,
            'events' => $events,
        ]);
        $toArray = fn (array $insights) => array_map(fn ($i) => $i->toArray(), $insights);

        return [
            'period' => [
                'mode' => $this->period->mode,
                'label' => $this->period->label,
                'start' => $this->period->start->toDateString(),
                'end' => $this->period->end->toDateString(),
                'previous_label' => $this->period->previousLabel,
            ],
            'summary' => [
                'sunday_average' => $average,
                'previous_sunday_average' => $previousAverage,
                'coverage' => $coverage,
                'gatherings_held' => $held,
                'previous_gatherings_held' => $heldBefore,
                'peak' => $top[0] ?? null,
                'membership' => $membership,
            ],
            'sunday' => [
                'weekly' => $sundays,
                'months' => $months,
                'heatmap' => $this->heatmap($sundays),
                'composition' => self::composition($sundays),
                'top' => $top,
                'lowest' => $lowest,
                'insights' => $toArray(InsightEngine::run([new SundayCoverageRule, new AttendanceTrendRule, new AttendanceVsMembershipRule, new PeakSundayRule], $facts)),
            ],
            'ministries' => [
                'items' => $ministries,
                'insights' => $toArray(InsightEngine::run([QuietGatheringRule::ministries(), TopGatheringRule::ministries()], $facts)),
            ],
            'events' => [
                'items' => $events,
                'insights' => $toArray(InsightEngine::run([TopGatheringRule::events()], $facts)),
            ],
            'children' => [
                'boys' => $boys,
                'girls' => $girls,
                'girls_share' => $boys + $girls > 0 ? (int) round($girls / ($boys + $girls) * 100) : null,
                'children_share' => $average ? (int) round(($boys + $girls) / $average * 100) : null,
                'months' => $months,
                'insights' => $toArray(InsightEngine::run([GenderBalanceRule::sundayChildren()], $facts)),
            ],
        ];
    }
}
