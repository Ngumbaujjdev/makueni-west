<?php

namespace App\Services\App;

use App\Enums\TerritoryType;
use App\Models\ChurchDemographic;
use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use App\Services\Reports\MonthlyReports;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The figures behind the phone app's overseer screens (docs/specs/mobile-app-spec.md,
 * M5, M8, M10): every church below a subregion, region or diocese, with its latest
 * approved demographics, its monthly report for a month, and its members over time.
 * Totals are sums of each church's latest approved counts - the same rows the web's
 * analytics read.
 */
final class OverseerFigures
{
    /** @var Collection<int, Territory> churches below, by id */
    private Collection $churches;

    /** @var Collection<int, Collection> approved demographics by church, newest first */
    private Collection $records;

    public function __construct(private MonthlyReports $reports) {}

    /** Load the churches below a place (once per request). */
    public function for(Territory $place): self
    {
        $ids = PlaceAccess::descendantIds($place);
        $this->churches = Territory::whereIn('id', $ids)->where('territory_type', TerritoryType::CHURCH->value)
            ->orderBy('name')->get()->keyBy('id');
        $this->records = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual'])
            ->whereIn('territory_id', $this->churches->keys())->where('status', 'approved')->get()
            ->groupBy('territory_id')
            ->map(fn ($rows) => $rows->sortByDesc(fn ($r) => $this->endsOn($r)->toDateString())->values());

        return $this;
    }

    public function churches(): Collection
    {
        return $this->churches;
    }

    /** A church's latest approved record, or the latest that had begun by a date. */
    public function latest(int $churchId, ?CarbonImmutable $asOf = null): ?ChurchDemographic
    {
        $rows = $this->records->get($churchId, collect());

        // A record counts from the start of its period: a half-year being recorded
        // describes the church from July, not only once December is over.
        return $asOf ? $rows->first(fn ($r) => $this->startsOn($r)->lte($asOf)) : $rows->first();
    }

    /** The record before the latest (for "vs last period"). */
    public function previous(int $churchId): ?ChurchDemographic
    {
        return $this->records->get($churchId, collect())->get(1);
    }

    /** Sum of the latest counts over some churches (null ids = all). */
    public function totals(?array $churchIds = null, ?CarbonImmutable $asOf = null): array
    {
        $out = ['members' => 0, 'youth' => 0, 'baptisms' => 0, 'conversions' => 0, 'women' => 0, 'men' => 0, 'sunday_school' => 0, 'reporting' => 0];
        foreach ($churchIds ?? $this->churches->keys()->all() as $id) {
            $r = $this->latest((int) $id, $asOf);
            if (! $r) {
                continue;
            }
            $out['reporting']++;
            $out['members'] += (int) $r->total_members;
            $out['youth'] += (int) $r->youth_count;
            $out['baptisms'] += (int) $r->baptisms_count;
            $out['conversions'] += (int) $r->conversions_count;
            $out['women'] += (int) $r->womens_fellowship_count;
            $out['men'] += (int) $r->mens_fellowship_count;
            $out['sunday_school'] += (int) $r->sunday_school_male_count + (int) $r->sunday_school_female_count;
        }

        return $out;
    }

    /** Members a number of months back, and the change since, in %. */
    public function growth(?array $churchIds, int $months): array
    {
        $now = $this->totals($churchIds)['members'];
        $then = $this->totals($churchIds, CarbonImmutable::today()->subMonthsNoOverflow($months)->endOfMonth())['members'];

        return ['now' => $now, 'then' => $then, 'percent' => $then > 0 ? round(($now - $then) / $then * 100, 1) : null];
    }

    /** Members at the end of each of the last 12 months, oldest first. */
    public function trend(?array $churchIds = null, int $months = 12): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $end = CarbonImmutable::today()->subMonthsNoOverflow($i)->endOfMonth();
            $out[] = ['month' => $end->format('Y-m'), 'label' => $end->format('M'), 'members' => $this->totals($churchIds, $i === 0 ? null : $end)['members']];
        }

        return $out;
    }

    /**
     * The next three months by a straight line through the trend (least
     * squares) - an estimate, and said to be one.
     */
    public function forecast(array $trend, int $ahead = 3): array
    {
        $n = count($trend);
        if ($n < 3) {
            return [];
        }
        $xs = range(0, $n - 1);
        $ys = array_column($trend, 'members');
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $den = array_sum(array_map(fn ($x) => ($x - $mx) ** 2, $xs));
        $slope = $den ? array_sum(array_map(fn ($x, $y) => ($x - $mx) * ($y - $my), $xs, $ys)) / $den : 0;
        $out = [];
        for ($k = 1; $k <= $ahead; $k++) {
            $month = CarbonImmutable::today()->addMonthsNoOverflow($k);
            $out[] = ['month' => $month->format('Y-m'), 'label' => $month->format('M'), 'members' => max(0, (int) round($my + $slope * ($n - 1 + $k - $mx)))];
        }

        return $out;
    }

    /**
     * Each church's monthly report for a month (M8): sent or seen, late,
     * not started - from MonthlyReports::state, as the web shows it.
     */
    public function reports(int $year, int $month): Collection
    {
        $rows = MonthlyReport::whereIn('territory_id', $this->churches->keys())->where('year', $year)->where('month', $month)->get()->keyBy('territory_id');

        return $this->churches->map(fn (Territory $c) => $this->reports->state($c, $year, $month, $rows->get($c->id)));
    }

    /** {sent, due, late, percent} over some reports (not_tracked months don't count). */
    public function compliance(Collection $states): array
    {
        $due = $states->filter(fn ($s) => $s['status'] !== 'not_tracked');
        $sent = $due->filter(fn ($s) => in_array($s['status'], ['sent', 'seen'], true))->count();

        return ['sent' => $sent, 'due' => $due->count(), 'late' => $due->where('late', true)->count(), 'percent' => $due->count() ? (int) round($sent / $due->count() * 100) : null];
    }

    /** The month whose reports are being gathered: last month. */
    public function reportMonth(): CarbonImmutable
    {
        return CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * The groups one level down, for comparing: the subregions or regions that
     * hold churches, plus the churches directly under the place - or, when
     * there are no such groups, the churches themselves.
     *
     * @return array<int, array{id:int,name:string,type:string,church_ids:int[]}>
     */
    public function groups(Territory $place): array
    {
        $children = Territory::where('parent_territory_id', $place->id)->whereNull('deleted_at')->orderBy('name')->get();
        $groups = [];
        $direct = [];
        foreach ($children as $child) {
            if ($child->territory_type === TerritoryType::CHURCH) {
                if ($this->churches->has($child->id)) {
                    $direct[] = (int) $child->id;
                }

                continue;
            }
            $ids = array_values(array_filter(PlaceAccess::descendantIds($child), fn ($id) => $this->churches->has($id)));
            if ($ids) {
                $groups[] = ['id' => (int) $child->id, 'name' => $child->name, 'type' => $child->territory_type->value, 'church_ids' => $ids];
            }
        }
        if (! $groups) {
            return array_map(fn ($id) => ['id' => $id, 'name' => $this->churches[$id]->name, 'type' => 'church', 'church_ids' => [$id]], $direct);
        }
        if ($direct) {
            $groups[] = ['id' => (int) $place->id, 'name' => 'Churches directly under '.$place->name, 'type' => 'direct', 'church_ids' => $direct];
        }

        return $groups;
    }

    /** The pastor of each church: its senior pastor, else any pastor. @return array<int, string> */
    public function pastors(): array
    {
        $rows = UserTerritoryAssignment::with(['user', 'role'])->whereIn('territory_id', $this->churches->keys())->where('is_active', true)->get();
        $out = [];
        foreach ($rows->groupBy('territory_id') as $churchId => $list) {
            $pick = $list->first(fn ($a) => str_contains(strtolower((string) $a->role?->name), 'senior pastor'))
                ?? $list->first(fn ($a) => str_contains(strtolower((string) $a->role?->name), 'pastor'));
            if ($pick && $pick->user) {
                $out[(int) $churchId] = trim($pick->user->firstname.' '.$pick->user->lastname);
            }
        }

        return $out;
    }

    /** The first day of a record's period. */
    public function startsOn(ChurchDemographic $r): CarbonImmutable
    {
        return $this->endsOn($r)->startOfMonth()->subMonthsNoOverflow($r->fiscalMonth ? 0 : ($r->fiscalSemiAnnual ? 5 : 11));
    }

    /** The day a record's period ends: its month, its half, or its year. */
    public function endsOn(ChurchDemographic $r): CarbonImmutable
    {
        $year = (int) ($r->fiscalYear?->year ?? 2000);
        if ($r->fiscalMonth) {
            return CarbonImmutable::create($year, (int) $r->fiscalMonth->number, 1)->endOfMonth();
        }
        if ($r->fiscalSemiAnnual) {
            return CarbonImmutable::create($year, (int) $r->fiscalSemiAnnual->number === 1 ? 6 : 12, 1)->endOfMonth();
        }

        return CarbonImmutable::create($year, 12, 31);
    }

    public function label(ChurchDemographic $r): string
    {
        $year = $r->fiscalYear?->year;
        if ($r->fiscalMonth) {
            return "{$r->fiscalMonth->name} {$year}";
        }
        if ($r->fiscalSemiAnnual) {
            return ((int) $r->fiscalSemiAnnual->number === 1 ? 'January–June ' : 'July–December ').$year;
        }

        return (string) $year;
    }
}
