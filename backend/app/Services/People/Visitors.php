<?php

namespace App\Services\People;

use App\Models\GatheringType;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use App\Services\Activities\Activities;
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Visitors and their follow-up (docs/specs/people-and-care-spec.md, P2): the
 * list and board, who is due a follow-up, the Overview's cards, Insights and
 * the counts the region and diocese see. A visitor is a person with a stage;
 * they keep it ("member") once they join, so conversions stay countable.
 */
final class Visitors
{
    /** Those who became members stay on the board this long. */
    public const MEMBER_DAYS = 90;

    public function __construct(private People $people, private Settings $settings) {}

    public function today(): CarbonImmutable
    {
        return $this->people->today();
    }

    /** Follow up within this many days of a visit (Settings > Visitors). */
    public function followupDays(Territory $church): int
    {
        return max(1, (int) ($this->settings->get('visitors.followup_days', $church) ?: 3));
    }

    /** What the Sunday form and the visitor page pick from. */
    public function options(Territory $church): array
    {
        return [
            'areas' => $this->people->knownAreas($church),
            'followup_days' => $this->followupDays($church),
            'welcome_sms' => (bool) $this->settings->get('visitors.welcome_sms', $church),
            'welcome_template' => (string) $this->settings->get('visitors.welcome_template', $church),
            'gathering_types' => GatheringType::active()->forTerritory($church->id)->orderBy('display_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'leaders' => $this->leaders($church)->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")])->sortBy('name')->values()->all(),
            'stages' => Person::STAGES,
            'types' => VisitorFollowup::TYPES,
            'outcomes' => VisitorFollowup::OUTCOMES,
            'church_name' => $church->name,
        ];
    }

    /** The church's leaders who can follow visitors up. */
    public function leaders(Territory $church): Collection
    {
        return app(Activities::class)->leadersWith([$church->id], PeopleAccess::permission('visitors', 'manage'));
    }

    /** Everyone who came to us as a visitor and is still not removed. */
    public function base(Territory $church): Builder
    {
        return Person::query()->where('territory_id', $church->id)->whereNotNull('stage')->whereNull('anonymised_at');
    }

    /**
     * The list and board: stage[], area, assigned (user id, "me" or
     * "none"), month (of the first visit), q (name or phone), due, archived.
     * With no stage picked: everyone still visiting, and those who became
     * members in the last 90 days.
     */
    public function query(Territory $church, array $f, ?User $user): Builder
    {
        $q = $this->base($church);
        empty($f['archived']) ? $q->whereNull('archived_at') : $q->whereNotNull('archived_at');
        $stages = array_values(array_filter((array) ($f['stage'] ?? [])));
        if ($stages) {
            $q->whereIn('stage', $stages);
        } else {
            $since = $this->today()->subDays(self::MEMBER_DAYS)->toDateString();
            $q->where(fn ($w) => $w->where('status', 'visitor')->orWhere(fn ($m) => $m->where('stage', 'member')->where('became_member_on', '>=', $since)));
        }
        if (! empty($f['area'])) {
            $q->where('area', $f['area']);
        }
        $assigned = $f['assigned'] ?? null;
        if ($assigned === 'me') {
            $q->where('assigned_to', $user?->id ?? 0);
        } elseif ($assigned === 'none') {
            $q->whereNull('assigned_to');
        } elseif ($assigned !== null && $assigned !== '' && ctype_digit((string) $assigned)) {
            $q->where('assigned_to', (int) $assigned);
        }
        if (! empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $m = CarbonImmutable::parse("{$f['month']}-01");
            $q->whereBetween('first_visit_on', [$m->toDateString(), $m->endOfMonth()->toDateString()]);
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $phone = People::phoneLike($term);
            $q->where(function ($w) use ($term, $phone) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('area', 'like', $like)->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
                if ($phone) {
                    $w->orWhere('phone', 'like', $phone);
                }
            });
        }

        return $q;
    }

    /** Each person's latest follow-up, by person id. */
    public function latestFollowups(Collection $people): Collection
    {
        $ids = $people->pluck('id')->all();

        return VisitorFollowup::whereIn('person_id', $ids ?: [0])->orderBy('done_on')->orderBy('id')->get()->keyBy('person_id');
    }

    /**
     * When their next follow-up is due: the next step a follow-up set, or -
     * when nobody has followed up since their last visit - N days after it.
     * Null once they're members, or followed up with no next step.
     */
    public function dueOn(Person $p, ?VisitorFollowup $last, int $days): ?CarbonImmutable
    {
        if ($p->status !== 'visitor' || $p->archived_at) {
            return null;
        }
        if ($last && (! $p->last_visit_on || $last->done_on->gte($p->last_visit_on))) {
            return $last->next_on ? CarbonImmutable::parse($last->next_on->toDateString()) : null;
        }

        return $p->last_visit_on ? CarbonImmutable::parse($p->last_visit_on->toDateString())->addDays($days) : null;
    }

    /** One row of the list and one card of the board. */
    public function row(Person $p, ?VisitorFollowup $last, int $days): array
    {
        $due = $this->dueOn($p, $last, $days);
        $today = $this->today();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'initials' => $p->initials,
            'phone' => $p->phone,
            'gender' => $p->gender,
            'status' => $p->status,
            'stage' => $p->stage,
            'visits' => (int) $p->visit_count,
            'first_visit_on' => $p->first_visit_on?->toDateString(),
            'last_visit_on' => $p->last_visit_on?->toDateString(),
            'became_member_on' => $p->became_member_on?->toDateString(),
            'area' => $p->area,
            'consent' => (bool) $p->consent_contact,
            'assigned' => $p->assignee ? ['id' => $p->assignee->id, 'name' => trim("{$p->assignee->firstname} {$p->assignee->lastname}")] : null,
            'due_on' => $due?->toDateString(),
            'overdue' => $due ? $due->lt($today) : false,
            'last_followup' => $last ? ['type' => $last->type, 'outcome' => $last->outcome, 'done_on' => $last->done_on->toDateString()] : null,
            'archived' => (bool) $p->archived_at,
        ];
    }

    /** Rows for a set of people, with their follow-up state. */
    public function rows(Territory $church, Collection $people): array
    {
        $people->loadMissing('assignee');
        $latest = $this->latestFollowups($people);
        $days = $this->followupDays($church);

        return $people->map(fn (Person $p) => $this->row($p, $latest->get($p->id), $days))->values()->all();
    }

    /** Move them along when they come back: returning on their 2nd visit, regular from the 4th. Never backwards. */
    public function advance(Person $p): void
    {
        $order = array_keys(Person::STAGES);
        $at = array_search($p->stage, $order, true);
        $to = $p->visit_count >= Person::REGULAR_AFTER ? 'regular' : ($p->visit_count >= 2 ? 'returning' : null);
        if ($to && $at !== false && $p->stage !== 'member' && array_search($to, $order, true) > $at) {
            $p->stage = $to;
        }
    }

    /** The Visitors page's cards, the stage counts and "my follow-ups". */
    public function overview(Territory $church, ?User $user): array
    {
        $today = $this->today();
        $days = $this->followupDays($church);
        $months = collect(range(11, 0))->map(fn ($i) => $today->startOfMonth()->subMonthsNoOverflow($i));
        $visitorsIn = fn (CarbonImmutable $m) => VisitorVisit::where('territory_id', $church->id)
            ->whereBetween('on', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])->distinct()->count('person_id');
        $firstIn = fn (CarbonImmutable $m) => VisitorVisit::where('territory_id', $church->id)->where('first_time', true)
            ->whereBetween('on', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])->count();

        // Followed up in time: of those whose first visit was in the last 90 days (and whose N days are up).
        $cohort = $this->base($church)->whereBetween('first_visit_on', [$today->subDays(90)->toDateString(), $today->subDays($days)->toDateString()])->get(['id', 'first_visit_on']);
        $firstFollowups = VisitorFollowup::whereIn('person_id', $cohort->pluck('id')->all() ?: [0])->selectRaw('person_id, min(done_on) as first_on')->groupBy('person_id')->pluck('first_on', 'person_id');
        $inTime = $cohort->filter(fn ($p) => ($f = $firstFollowups[$p->id] ?? null) && CarbonImmutable::parse($f)->lte(CarbonImmutable::parse($p->first_visit_on->toDateString())->addDays($days)))->count();

        $became = $this->base($church)->where('stage', 'member')->whereYear('became_member_on', $today->year)->count();
        $firstThisYear = VisitorVisit::where('territory_id', $church->id)->where('first_time', true)->whereYear('on', $today->year)->count();

        // My follow-ups: mine and nobody's, due within the week or late.
        $open = $this->base($church)->where('status', 'visitor')->whereNull('archived_at')
            ->where(fn ($w) => $w->whereNull('assigned_to')->orWhere('assigned_to', $user?->id ?? 0))->with('assignee')->get();
        $latest = $this->latestFollowups($open);
        $due = $open->map(fn (Person $p) => $this->row($p, $latest->get($p->id), $days))
            ->filter(fn ($r) => $r['due_on'] && $r['due_on'] <= $today->addDays(7)->toDateString())
            ->sortBy('due_on')->values();

        $stages = $this->base($church)->whereNull('archived_at')
            ->where(fn ($w) => $w->where('status', 'visitor')->orWhere(fn ($m) => $m->where('stage', 'member')->where('became_member_on', '>=', $today->subDays(self::MEMBER_DAYS)->toDateString())))
            ->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');

        return [
            'this_month' => $visitorsIn($today),
            'last_month' => $visitorsIn($today->subMonthNoOverflow()),
            'first_timers' => $firstIn($today),
            'first_timers_last_month' => $firstIn($today->subMonthNoOverflow()),
            'followed_up_in_time' => $cohort->count() ? (int) round($inTime / $cohort->count() * 100) : null,
            'followup_cohort' => $cohort->count(),
            'followup_days' => $days,
            'became_members' => $became,
            'first_timers_this_year' => $firstThisYear,
            'conversion' => $firstThisYear ? (int) round($became / $firstThisYear * 100) : null,
            'months' => $months->map(fn ($m) => $m->format('M'))->all(),
            'visitors_series' => $months->map(fn ($m) => $visitorsIn($m))->all(),
            'first_series' => $months->map(fn ($m) => $firstIn($m))->all(),
            'due' => $due->take(12)->all(),
            'due_count' => $due->count(),
            'overdue' => $due->where('overdue', true)->count(),
            'stages' => collect(array_keys(Person::STAGES))->mapWithKeys(fn ($s) => [$s => (int) ($stages[$s] ?? 0)])->all(),
        ];
    }

    /** Insights for a year: how they heard, the funnel and each month. */
    public function insights(Territory $church, int $year): array
    {
        $firstTimers = $this->base($church)->whereYear('first_visit_on', $year)->get();
        $order = array_keys(Person::STAGES);
        $reached = fn (string $stage) => $firstTimers->filter(fn ($p) => array_search($p->stage, $order, true) >= array_search($stage, $order, true))->count();
        $months = collect(range(1, 12))->map(fn ($m) => CarbonImmutable::create($year, $m, 1, 0, 0, 0, People::TZ));
        $count = fn ($q, string $col) => $q->whereYear($col, $year)->selectRaw("month(`{$col}`) as m, count(*) as n")->groupBy('m')->pluck('n', 'm');
        $first = $count(VisitorVisit::where('territory_id', $church->id)->where('first_time', true), 'on');
        $back = $count(VisitorVisit::where('territory_id', $church->id)->where('first_time', false), 'on');
        $joined = $count($this->base($church)->where('stage', 'member'), 'became_member_on');
        $followups = VisitorFollowup::where('territory_id', $church->id)->whereYear('done_on', $year)->get(['type', 'outcome', 'person_id', 'done_on']);
        $firstFollow = VisitorFollowup::whereIn('person_id', $firstTimers->pluck('id')->all() ?: [0])->selectRaw('person_id, min(done_on) as first_on')->groupBy('person_id')->pluck('first_on', 'person_id');
        $waits = $firstTimers->filter(fn ($p) => isset($firstFollow[$p->id]))
            ->map(fn ($p) => max(0, $p->first_visit_on->diffInDays(CarbonImmutable::parse($firstFollow[$p->id]))));

        return [
            'year' => $year,
            'first_timers' => $firstTimers->count(),
            'areas' => $this->people->areas($firstTimers),
            'funnel' => [
                ['key' => 'new', 'label' => 'First visit', 'count' => $firstTimers->count()],
                ['key' => 'contacted', 'label' => 'Followed up', 'count' => $reached('contacted')],
                ['key' => 'returning', 'label' => 'Came back', 'count' => $reached('returning')],
                ['key' => 'regular', 'label' => 'Regular', 'count' => $reached('regular')],
                ['key' => 'member', 'label' => 'Became members', 'count' => $reached('member')],
            ],
            'months' => $months->map(fn ($m) => $m->format('M'))->all(),
            'first_series' => $months->map(fn ($m) => (int) ($first[$m->month] ?? 0))->all(),
            'returning_series' => $months->map(fn ($m) => (int) ($back[$m->month] ?? 0))->all(),
            'joined_series' => $months->map(fn ($m) => (int) ($joined[$m->month] ?? 0))->all(),
            'followup_types' => collect(VisitorFollowup::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $followups->where('type', $key)->count()])->values()->all(),
            'followup_outcomes' => collect(VisitorFollowup::OUTCOMES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $followups->where('outcome', $key)->count()])->values()->all(),
            'average_wait' => $waits->count() ? round($waits->avg(), 1) : null,
            'never_followed' => $firstTimers->filter(fn ($p) => ! isset($firstFollow[$p->id]) && $p->status === 'visitor')->count(),
        ];
    }

    /** For the region and diocese: each church below, counts only - never a name. */
    public function totals(Territory $place): array
    {
        $today = $this->today();
        $churches = Territory::whereIn('id', PlaceAccess::descendantIds($place) ?: [0])->where('territory_type', 'church')->orderBy('name')->get(['id', 'name']);
        $ids = $churches->pluck('id')->all() ?: [0];
        $by = fn (Builder $q, string $expr = 'count(*)') => $q->selectRaw("territory_id, {$expr} as n")->groupBy('territory_id')->pluck('n', 'territory_id');
        $visits = fn () => VisitorVisit::query()->whereIn('territory_id', $ids);
        $month = [$today->startOfMonth()->toDateString(), $today->toDateString()];
        $visitors = $by($visits()->whereBetween('on', $month), 'count(distinct person_id)');
        $first = $by($visits()->where('first_time', true)->whereBetween('on', $month));
        $firstYear = $by($visits()->where('first_time', true)->whereYear('on', $today->year));
        $became = $by(Person::query()->whereIn('territory_id', $ids)->where('stage', 'member')->whereYear('became_member_on', $today->year));
        $any = $by(Person::query()->whereIn('territory_id', $ids)->whereNotNull('stage'));

        $rows = $churches->map(function ($c) use ($visitors, $first, $firstYear, $became, $any) {
            $fy = (int) ($firstYear[$c->id] ?? 0);
            $b = (int) ($became[$c->id] ?? 0);

            return [
                'church' => ['id' => $c->id, 'name' => $c->name],
                'records_visitors' => (int) ($any[$c->id] ?? 0) > 0,
                'visitors_this_month' => (int) ($visitors[$c->id] ?? 0),
                'first_timers_this_month' => (int) ($first[$c->id] ?? 0),
                'first_timers_this_year' => $fy,
                'became_members_this_year' => $b,
                'conversion' => $fy ? (int) round($b / $fy * 100) : null,
            ];
        })->values();
        $fy = $rows->sum('first_timers_this_year');

        return [
            'churches' => $rows->count(),
            'recording' => $rows->where('records_visitors', true)->count(),
            'visitors_this_month' => $rows->sum('visitors_this_month'),
            'first_timers_this_month' => $rows->sum('first_timers_this_month'),
            'first_timers_this_year' => $fy,
            'became_members_this_year' => $rows->sum('became_members_this_year'),
            'conversion' => $fy ? (int) round($rows->sum('became_members_this_year') / $fy * 100) : null,
            'rows' => $rows->all(),
        ];
    }
}
