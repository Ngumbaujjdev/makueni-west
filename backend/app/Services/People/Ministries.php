<?php

namespace App\Services\People;

use App\Models\Activity;
use App\Models\ChurchAttendanceRecord;
use App\Models\GatheringType;
use App\Models\Ministry;
use App\Models\MinistryLeader;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Attendance\AttendanceData;
use App\Services\Activities\Activities;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ministries' figures (docs/specs/people-and-care-spec.md, P4): the
 * standard six every church starts with, who serves and leads, the
 * attendance of each one's gathering, the events and initiatives meant for
 * it, and the counts the region and diocese see - never a name.
 */
final class Ministries
{
    /** People who count as serving: still with us - members and visitors in the register. */
    public const SERVING = ['member', 'visitor'];

    /** kind => the activity audiences and types meant for it. */
    private const ACTIVITY_MATCH = [
        'youth' => [['youth'], ['youth_kesha', 'youth_programme', 'youth_convention']],
        'women' => [['women'], ['womens']],
        'men' => [['men'], ['mens']],
        'children' => [['children'], ['children_programme']],
        'music' => [[], ['worship_night']],
        'prayer' => [[], ['prayer_meeting', 'prayer_group', 'prayer_conference']],
        'other' => [[], []],
    ];

    public function __construct(private People $people) {}

    public function today(): CarbonImmutable
    {
        return $this->people->today();
    }

    /**
     * The standard six, made the first time a church's ministries are read
     * - once: the unique (church, standard) key keeps two reads at once from
     * making them twice, and one removed later isn't made again.
     */
    public function ensure(Territory $church): void
    {
        if (Ministry::withTrashed()->where('territory_id', $church->id)->whereNotNull('standard')->exists()) {
            return;
        }
        $types = GatheringType::active()->forTerritory($church->id)->orderBy('display_order')->get(['id', 'name']);
        $now = now();
        $rows = [];
        $order = 0;
        foreach (Ministry::STANDARD as $kind => [$name, $pattern]) {
            $rows[] = [
                'territory_id' => $church->id, 'name' => $name, 'kind' => $kind, 'standard' => $kind, 'active' => true, 'order' => $order++,
                'gathering_type_id' => $types->first(fn ($t) => preg_match($pattern, $t->name))?->id,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('ministries')->insertOrIgnore($rows);
    }

    /** The church's leaders who may be named a ministry's leader: everyone there who reads ministries. */
    public function leaderUsers(Territory $church): Collection
    {
        $activities = app(Activities::class);

        return $activities->leadersWith([$church->id], PeopleAccess::permission('ministries', 'read'))
            ->merge($activities->leadersWith([$church->id], PeopleAccess::permission('ministries', 'manage')))
            ->unique('id')->sortBy('firstname')->values();
    }

    public function isLeader(Ministry $m, ?User $user): bool
    {
        return $user && MinistryLeader::where('ministry_id', $m->id)->where('user_id', $user->id)->exists();
    }

    /** May this user change who serves in it? Those who manage ministries, and its own leaders. */
    public function canRoster(Ministry $m, ?User $user, Territory $church): bool
    {
        return PeopleAccess::canNamed($user, $church, 'ministries', 'manage') || $this->isLeader($m, $user);
    }

    /** The church's gathering types to link to, ministry gatherings first. */
    public function gatheringTypes(Territory $church): Collection
    {
        return GatheringType::with('category')->active()->forTerritory($church->id)->get()
            ->sortBy(fn ($t) => [$t->category?->slug === 'ministry_gathering' ? 0 : 1, $t->display_order, $t->name])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'category' => $t->category?->name])->values();
    }

    /** People serving in each ministry (still with us), by ministry id. */
    public function memberCounts(array $ministryIds): Collection
    {
        return DB::table('ministry_members')->join('people', 'people.id', '=', 'ministry_members.person_id')
            ->whereIn('ministry_members.ministry_id', $ministryIds ?: [0])
            ->whereIn('people.status', self::SERVING)->whereNull('people.archived_at')->whereNull('people.anonymised_at')->whereNull('people.deleted_at')
            ->selectRaw('ministry_members.ministry_id, count(*) as n')->groupBy('ministry_members.ministry_id')->pluck('n', 'ministry_id');
    }

    /** Distinct people serving in any of these ministries. */
    public function servingCount(array $ministryIds): int
    {
        return (int) DB::table('ministry_members')->join('people', 'people.id', '=', 'ministry_members.person_id')
            ->whereIn('ministry_members.ministry_id', $ministryIds ?: [0])
            ->whereIn('people.status', self::SERVING)->whereNull('people.archived_at')->whereNull('people.anonymised_at')->whereNull('people.deleted_at')
            ->distinct()->count('ministry_members.person_id');
    }

    /** Attendance records of these gathering types at the church, from a date. */
    private function gatherings(Territory $church, array $typeIds, CarbonImmutable $from): Collection
    {
        if (! $typeIds) {
            return collect();
        }

        return ChurchAttendanceRecord::where('territory_type', 'church')->where('territory_id', $church->id)
            ->whereIn('gathering_type_id', $typeIds)->where('service_date', '>=', $from->toDateString())
            ->get(['id', 'gathering_type_id', 'service_date', 'adults_count', 'youth_count', 'children_male_count', 'children_female_count']);
    }

    /** The next day it meets (today counts), or null. */
    public function nextMeeting(Ministry $m): ?string
    {
        if ($m->meets_day === null || ! $m->active) {
            return null;
        }
        $today = $this->today();

        return $today->addDays(($m->meets_day - $today->dayOfWeek + 7) % 7)->toDateString();
    }

    /** One ministry as the cards and lists show it. */
    public function row(Ministry $m, int $members, ?Collection $records = null, ?array $months = null): array
    {
        $records ??= collect();
        $mine = $m->gathering_type_id ? $records->where('gathering_type_id', $m->gathering_type_id) : collect();
        $series = $months ? array_map(function ($month) use ($mine) {
            $in = $mine->filter(fn ($r) => $r->service_date->format('Y-m') === $month->format('Y-m'));

            return $in->isEmpty() ? 0 : (int) round($in->avg(fn ($r) => AttendanceData::total($r)));
        }, $months) : [];

        return [
            'id' => $m->id,
            'name' => $m->name,
            'kind' => $m->kind,
            'kind_label' => Ministry::KINDS[$m->kind][0] ?? 'Other',
            'standard' => (bool) $m->standard,
            'icon' => $m->icon_name,
            'colour' => $m->colour_name,
            'meets_day' => $m->meets_day,
            'meets_time' => $m->meets_time ? substr($m->meets_time, 0, 5) : null,
            'meets' => $m->meets,
            'next_meeting' => $this->nextMeeting($m),
            'gathering_type' => $m->gatheringType ? ['id' => $m->gatheringType->id, 'name' => $m->gatheringType->name] : null,
            'active' => (bool) $m->active,
            'order' => (int) $m->order,
            'members' => $members,
            'leaders' => $m->leaders->map(fn (MinistryLeader $l) => [
                'id' => $l->id, 'user_id' => $l->user_id, 'person_id' => $l->person_id, 'name' => $l->name, 'role' => $l->role,
                'role_label' => Ministry::ROLES[$l->role] ?? 'Leader', 'initials' => $this->initials($l->name),
            ])->values()->all(),
            'attendance_series' => $series,
            'last_gathering' => $mine->max(fn ($r) => $r->service_date->toDateString()),
            'gatherings_this_month' => $mine->filter(fn ($r) => $r->service_date->format('Y-m') === $this->today()->format('Y-m'))->count(),
        ];
    }

    /** The Ministries page: cards, then a card per ministry. */
    public function overview(Territory $church, ?User $user, bool $withInactive = false): array
    {
        $this->ensure($church);
        $today = $this->today();
        $months = collect(range(5, 0))->map(fn ($i) => $today->startOfMonth()->subMonthsNoOverflow($i))->all();
        $all = Ministry::where('territory_id', $church->id)->with(['leaders.user', 'leaders.person', 'gatheringType'])->orderBy('order')->orderBy('name')->get();
        $active = $all->where('active', true);
        $counts = $this->memberCounts($all->pluck('id')->all());
        $records = $this->gatherings($church, $active->pluck('gathering_type_id')->filter()->unique()->values()->all(), $months[0]);
        $perMonth = fn (CarbonImmutable $m) => $records->filter(fn ($r) => $r->service_date->format('Y-m') === $m->format('Y-m'))->count();
        $mine = $user ? MinistryLeader::whereIn('ministry_id', $all->pluck('id'))->where('user_id', $user->id)->pluck('ministry_id')->all() : [];

        return [
            'ministries' => $active->count(),
            'with_leader' => $active->filter(fn ($m) => $m->leaders->isNotEmpty())->count(),
            'serving' => $this->servingCount($active->pluck('id')->all()),
            'members_total' => Person::where('territory_id', $church->id)->listed()->members()->count(),
            'leaders' => $active->flatMap(fn ($m) => $m->leaders->map(fn ($l) => $l->user_id ? "u{$l->user_id}" : "p{$l->person_id}"))->unique()->count(),
            'gatherings_this_month' => $perMonth($today),
            'gatherings_last_month' => $perMonth($today->subMonthNoOverflow()),
            'gatherings_series' => array_map($perMonth, $months),
            'months' => array_map(fn ($m) => $m->format('M'), $months),
            'items' => ($withInactive ? $all : $active)->map(fn ($m) => $this->row($m, (int) ($counts[$m->id] ?? 0), $records, $months) + ['mine' => in_array($m->id, $mine, true)])->values()->all(),
        ];
    }

    /** Who serves in it - the register's slim details only. */
    public function members(Ministry $m): array
    {
        return $m->memberships()->with('person')->get()
            ->filter(fn ($mm) => $mm->person && in_array($mm->person->status, self::SERVING, true) && ! $mm->person->archived_at && ! $mm->person->anonymised_at)
            ->map(fn ($mm) => [
                'id' => $mm->person->id, 'name' => $mm->person->name, 'initials' => $mm->person->initials, 'phone' => $mm->person->phone,
                'area' => $mm->person->area, 'gender' => $mm->person->gender, 'congregation' => $mm->person->congregation,
                'kind' => $mm->person->status === 'visitor' ? 'visitor' : 'member', 'joined_on' => $mm->joined_on?->toDateString(),
            ])->sortBy('name')->values()->all();
    }

    /** Its gatherings over the last twelve months - the Attendance module's own detail for the linked type. */
    public function gatheringDetail(Territory $church, Ministry $m): ?array
    {
        if (! $m->gathering_type_id || ! $m->gatheringType) {
            return null;
        }
        $today = $this->today();
        $period = \App\Reports\Attendance\AttendancePeriod::range($today->subMonthsNoOverflow(11)->format('Y-m'), $today->format('Y-m'));

        return (new AttendanceData([$church->id], $period))->gatheringDetail($m->gatheringType, null);
    }

    /** This church's events and initiatives meant for it (by audience or type), from three months ago on. */
    public function activities(Territory $church, Ministry $m): array
    {
        [$audiences, $types] = self::ACTIVITY_MATCH[$m->kind] ?? [[], []];
        if (! $audiences && ! $types) {
            return [];
        }
        $from = $this->today()->subMonthsNoOverflow(3)->startOfDay();

        return Activity::where('territory_id', $church->id)->whereIn('status', ['published', 'completed'])
            ->where(fn ($w) => $w->whereIn('audience', $audiences ?: ['-'])->orWhereIn('type', $types ?: ['-']))
            ->where(fn ($w) => $w->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from)->orWhere('kind', 'initiative'))
            ->orderBy('starts_at')->limit(50)->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'title' => $a->title, 'type' => $a->type,
                'type_label' => ($a->kind === 'initiative' ? Activity::INITIATIVE_TYPES : Activity::TYPES)['church'][$a->type] ?? ucfirst(str_replace('_', ' ', (string) $a->type)),
                'audience' => $a->audience, 'starts_at' => $a->starts_at?->toIso8601String(), 'ends_at' => $a->ends_at?->toIso8601String(),
                'status' => $a->status, 'venue' => $a->venue, 'past' => $a->starts_at && $a->starts_at->lt(now()) && (! $a->ends_at || $a->ends_at->lt(now())),
            ])->values()->all();
    }

    /** Insights: who serves where, in more than one, or in none yet - and each ministry's mix and attendance. */
    public function insights(Territory $church): array
    {
        $this->ensure($church);
        $today = $this->today();
        $months = collect(range(5, 0))->map(fn ($i) => $today->startOfMonth()->subMonthsNoOverflow($i))->all();
        $ministries = Ministry::where('territory_id', $church->id)->where('active', true)->with('gatheringType')->orderBy('order')->orderBy('name')->get();
        $links = DB::table('ministry_members')->join('people', 'people.id', '=', 'ministry_members.person_id')
            ->whereIn('ministry_members.ministry_id', $ministries->pluck('id')->all() ?: [0])
            ->whereIn('people.status', self::SERVING)->whereNull('people.archived_at')->whereNull('people.anonymised_at')->whereNull('people.deleted_at')
            ->get(['ministry_members.ministry_id', 'people.id as person_id', 'people.gender', 'people.congregation']);
        $byPerson = $links->groupBy('person_id');
        $records = $this->gatherings($church, $ministries->pluck('gathering_type_id')->filter()->unique()->values()->all(), $months[0]);
        $members = Person::where('territory_id', $church->id)->listed()->members()->orderBy('first_name')->get();
        $none = $members->filter(fn ($p) => ! $byPerson->has($p->id))->values();

        return [
            'members_total' => $members->count(),
            'serving' => $byPerson->count(),
            'in_two_or_more' => $byPerson->filter(fn ($g) => $g->count() > 1)->count(),
            'not_serving' => $none->count(),
            'not_serving_people' => $none->take(60)->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'initials' => $p->initials, 'area' => $p->area, 'congregation' => $p->congregation])->all(),
            'months' => array_map(fn ($m) => $m->format('M'), $months),
            'ministries' => $ministries->map(function (Ministry $m) use ($links, $records) {
                $mine = $links->where('ministry_id', $m->id);
                $att = $m->gathering_type_id ? $records->where('gathering_type_id', $m->gathering_type_id) : collect();

                return [
                    'id' => $m->id, 'name' => $m->name, 'icon' => $m->icon_name, 'colour' => $m->colour_name,
                    'members' => $mine->count(),
                    'male' => $mine->where('gender', 'male')->count(), 'female' => $mine->where('gender', 'female')->count(),
                    'sunday_school' => $mine->where('congregation', 'sunday_school')->count(),
                    'linked' => (bool) $m->gathering_type_id,
                    'gatherings' => $att->count(),
                    'average' => $att->isEmpty() ? null : (int) round($att->avg(fn ($r) => AttendanceData::total($r))),
                ];
            })->values()->all(),
        ];
    }

    /** For the region and diocese: each church below, counts only - never a name. */
    public function totals(Territory $place): array
    {
        $today = $this->today();
        $churches = Territory::whereIn('id', PlaceAccess::descendantIds($place) ?: [0])->where('territory_type', 'church')->orderBy('name')->get(['id', 'name']);
        $ids = $churches->pluck('id')->all() ?: [0];
        $ministries = Ministry::whereIn('territory_id', $ids)->where('active', true)->get(['id', 'territory_id', 'gathering_type_id']);
        $serving = DB::table('ministry_members')->join('ministries', 'ministries.id', '=', 'ministry_members.ministry_id')
            ->join('people', 'people.id', '=', 'ministry_members.person_id')
            ->whereIn('ministries.territory_id', $ids)->where('ministries.active', true)->whereNull('ministries.deleted_at')
            ->whereIn('people.status', self::SERVING)->whereNull('people.archived_at')->whereNull('people.anonymised_at')->whereNull('people.deleted_at')
            ->selectRaw('ministries.territory_id, count(distinct ministry_members.person_id) as n')->groupBy('ministries.territory_id')->pluck('n', 'territory_id');
        $records = ChurchAttendanceRecord::where('territory_type', 'church')->whereIn('territory_id', $ids)
            ->whereIn('gathering_type_id', $ministries->pluck('gathering_type_id')->filter()->unique()->values()->all() ?: [0])
            ->whereBetween('service_date', [$today->startOfMonth()->toDateString(), $today->toDateString()])->get();
        $rows = $churches->map(function ($c) use ($ministries, $serving, $records) {
            $mine = $records->where('territory_id', $c->id);

            return [
                'church' => ['id' => $c->id, 'name' => $c->name],
                'ministries' => $ministries->where('territory_id', $c->id)->count(),
                'serving' => (int) ($serving[$c->id] ?? 0),
                'gatherings_this_month' => $mine->count(),
                'average_this_month' => $mine->isEmpty() ? null : (int) round($mine->avg(fn ($r) => AttendanceData::total($r))),
            ];
        })->values();

        return [
            'churches' => $rows->count(),
            'set_up' => $rows->where('ministries', '>', 0)->count(),
            'ministries' => $rows->sum('ministries'),
            'serving' => $rows->sum('serving'),
            'gatherings_this_month' => $rows->sum('gatherings_this_month'),
            'rows' => $rows->all(),
        ];
    }

    private function initials(string $name): string
    {
        return mb_strtoupper(collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    }
}
