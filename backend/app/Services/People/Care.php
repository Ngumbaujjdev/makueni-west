<?php

namespace App\Services\People;

use App\Models\CareContact;
use App\Models\CareRecord;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Services\Activities\Activities;
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pastoral care's figures (docs/specs/people-and-care-spec.md, P3): the
 * log, who needs care, hospital and prayer, the monthly report's visits and
 * the counts the region and diocese see. Confidential notes are only ever
 * given to their author and the confidential-care holders.
 */
final class Care
{
    public function __construct(private People $people, private Settings $settings) {}

    public function today(): CarbonImmutable
    {
        return $this->people->today();
    }

    /** The kinds of care this church records (Settings > Pastoral care). */
    public function types(Territory $church): array
    {
        return array_keys(array_filter(CareRecord::TYPES, fn ($t, $k) => (bool) ($this->settings->get("pastoral.type.{$k}", $church) ?? true), ARRAY_FILTER_USE_BOTH));
    }

    /** The church's leaders who give pastoral care. */
    public function carers(Territory $church): Collection
    {
        $activities = app(Activities::class);

        return $activities->leadersWith([$church->id], PeopleAccess::permission('pastoral', 'manage'))
            ->merge($activities->leadersWith([$church->id], PeopleAccess::permission('pastoral', 'read')))
            ->unique('id')->sortBy('firstname')->values();
    }

    /** May this user read this record's notes? The author, and the confidential-care holders. */
    public function canSeeNote(CareRecord $r, ?User $user, Territory $church): bool
    {
        if (! $r->confidential) {
            return true;
        }

        return $user && ((int) $r->created_by === (int) $user->id || PeopleAccess::canNamed($user, $church, 'pastoral', 'confidential'));
    }

    public function query(Territory $church, array $f): Builder
    {
        $q = CareRecord::query()->where('territory_id', $church->id)->with(['person', 'carers', 'author']);
        if ($types = array_values(array_filter((array) ($f['type'] ?? [])))) {
            $q->whereIn('type', $types);
        }
        if ($statuses = array_values(array_filter((array) ($f['status'] ?? [])))) {
            $q->whereIn('status', $statuses);
        }
        if (! empty($f['leader'])) {
            $id = (int) $f['leader'];
            $q->where(fn ($w) => $w->where('created_by', $id)->orWhereHas('carers', fn ($c) => $c->where('users.id', $id)));
        }
        if (! empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $m = CarbonImmutable::parse("{$f['month']}-01");
            $q->whereBetween('on', [$m->toDateString(), $m->endOfMonth()->toDateString()]);
        }
        if (! empty($f['person_id'])) {
            $q->where('person_id', (int) $f['person_id']);
        }

        return $q;
    }

    /** One record as the lists show it - the note only for those who may read it. */
    public function row(CareRecord $r, ?User $user, Territory $church): array
    {
        $see = $this->canSeeNote($r, $user, $church);
        $today = $this->today()->toDateString();
        $lastContact = $r->relationLoaded('contacts') ? $r->contacts->max('on') : null;

        return [
            'id' => $r->id,
            'who' => $r->who,
            'initials' => $this->initials($r->who),
            'person' => $r->person ? ['id' => $r->person->id, 'kind' => $r->person->status === 'visitor' ? 'visitor' : 'member', 'area' => $r->person->area] : null,
            'type' => $r->type,
            'priority' => $r->priority,
            'status' => $r->status,
            'on' => $r->on->toDateString(),
            'hospital' => $r->hospital,
            'discharged_on' => $r->discharged_on?->toDateString(),
            'next_on' => $r->next_on?->toDateString(),
            'due' => $r->status === 'open' && $r->next_on && $r->next_on->toDateString() <= $today,
            'confidential' => (bool) $r->confidential,
            'note' => $see ? $r->note : null,
            'note_hidden' => ! $see && $r->note !== null,
            'testimony' => $r->testimony,
            'share_testimony' => (bool) $r->share_testimony,
            'author' => $r->author ? trim("{$r->author->firstname} {$r->author->lastname}") : null,
            'carers' => $r->carers->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")])->values()->all(),
            'last_contact_on' => $lastContact ? CarbonImmutable::parse($lastContact)->toDateString() : null,
        ];
    }

    /** Visits in a month: visit-like care recorded that month, and visits made on open cases. */
    public function visitsIn(Territory $church, CarbonImmutable $month): int
    {
        $range = [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];

        return CareRecord::where('territory_id', $church->id)->whereIn('type', CareRecord::VISITS)->whereBetween('on', $range)->count()
            + CareContact::where('territory_id', $church->id)->where('type', 'visit')->whereBetween('on', $range)->count();
    }

    /** The Pastoral care page: cards, who needs care, care by type and by leader. */
    public function overview(Territory $church, ?User $user): array
    {
        $today = $this->today();
        $months = collect(range(11, 0))->map(fn ($i) => $today->startOfMonth()->subMonthsNoOverflow($i));
        $records = fn () => CareRecord::where('territory_id', $church->id);
        $byType = $records()->where('on', '>=', $months->first()->toDateString())->get(['type', 'on'])
            ->groupBy(fn ($r) => $r->type)->map(fn ($g) => $months->map(fn ($m) => $g->filter(fn ($r) => $r->on->format('Y-m') === $m->format('Y-m'))->count())->all());
        $leaders = DB::table('care_record_users')->join('care_records', 'care_records.id', '=', 'care_record_users.care_record_id')
            ->join('users', 'users.id', '=', 'care_record_users.user_id')
            ->where('care_records.territory_id', $church->id)->whereNull('care_records.deleted_at')->whereYear('care_records.on', $today->year)
            ->selectRaw("users.id, concat(users.firstname, ' ', users.lastname) as name, count(*) as n")->groupBy('users.id', 'users.firstname', 'users.lastname')
            ->orderByDesc('n')->limit(6)->get();

        return [
            'visits_this_month' => $this->visitsIn($church, $today),
            'visits_last_month' => $this->visitsIn($church, $today->subMonthNoOverflow()),
            'visits_series' => $months->map(fn ($m) => $this->visitsIn($church, $m))->all(),
            'open' => $records()->where('status', 'open')->count(),
            'open_high' => $records()->where('status', 'open')->where('priority', 'high')->count(),
            'prayer_open' => $records()->where('type', 'prayer')->where('status', 'open')->count(),
            'prayer_answered' => $records()->where('type', 'prayer')->where('status', 'answered')->whereYear('closed_on', $today->year)->count(),
            'in_hospital' => $records()->where('type', 'hospital')->where('status', 'open')->whereNull('discharged_on')->count(),
            'months' => $months->map(fn ($m) => $m->format('M'))->all(),
            'by_type' => collect(CareRecord::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'data' => $byType[$k] ?? array_fill(0, 12, 0)])->values()->all(),
            'by_leader' => $leaders->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'count' => (int) $l->n])->all(),
            'needs' => $this->needs($church, $user),
            'not_contacted_days' => (int) ($this->settings->get('pastoral.not_contacted_days', $church) ?: 60),
        ];
    }

    /**
     * Who needs care now: open cases marked high, next steps due within the
     * week (late first), then members nobody has cared for in N days.
     */
    public function needs(Territory $church, ?User $user, int $limit = 12): array
    {
        $today = $this->today();
        $open = CareRecord::where('territory_id', $church->id)->where('status', 'open')->with(['person', 'carers', 'author'])
            ->where(fn ($w) => $w->where('priority', 'high')->orWhere('next_on', '<=', $today->addDays(7)->toDateString()))
            ->orderByRaw('next_on is null')->orderBy('next_on')->get()
            ->map(fn ($r) => ['why' => $r->next_on ? 'next_step' : 'high'] + $this->row($r, $user, $church));
        $days = (int) ($this->settings->get('pastoral.not_contacted_days', $church) ?: 60);
        // Cared for lately, or with a case still open - not "nobody has visited".
        $cared = CareRecord::where('territory_id', $church->id)->whereNotNull('person_id')
            ->where(fn ($w) => $w->where('on', '>=', $today->subDays($days)->toDateString())->orWhere('status', 'open'))
            ->pluck('person_id')->unique()->all();
        $lonely = Person::where('territory_id', $church->id)->listed()->members()->where(fn ($w) => $w->whereNull('congregation')->orWhere('congregation', 'main_church'))
            ->whereNotIn('id', $cared ?: [0])->orderBy('joined_on')->limit(max(0, $limit - $open->count()))->get()
            ->map(fn (Person $p) => ['why' => 'not_contacted', 'id' => null, 'who' => $p->name, 'initials' => $p->initials, 'person' => ['id' => $p->id, 'kind' => 'member', 'area' => $p->area], 'type' => null, 'next_on' => null, 'due' => false]);

        return [...$open->take($limit)->all(), ...$lonely->all()];
    }

    /** Who is in hospital now, and when they were last visited. */
    public function hospital(Territory $church, ?User $user): array
    {
        $days = (int) ($this->settings->get('pastoral.hospital_days', $church) ?: 7);
        $today = $this->today();

        return CareRecord::where('territory_id', $church->id)->where('type', 'hospital')->where('status', 'open')->whereNull('discharged_on')
            ->with(['person', 'carers', 'author', 'contacts'])->orderBy('on')->get()
            ->map(function (CareRecord $r) use ($user, $church, $today, $days) {
                // Whole Nairobi days between dates - not hours, which the stored dates' timezone would skew.
                $day = fn ($d) => CarbonImmutable::parse($d->toDateString(), People::TZ);
                $last = collect([$r->on, ...$r->contacts->where('type', 'visit')->pluck('on')->all()])->map(fn ($d) => $d->toDateString())->max();
                $since = (int) round(CarbonImmutable::parse($last, People::TZ)->diffInDays($today));

                return $this->row($r, $user, $church) + ['last_visit_on' => $last, 'days_since_visit' => $since, 'overdue' => $since > $days, 'in_days' => (int) round($day($r->on)->diffInDays($today))];
            })->values()->all();
    }

    /** Prayer: open requests, and those answered this year. */
    public function prayer(Territory $church, ?User $user): array
    {
        $today = $this->today();
        $rows = CareRecord::where('territory_id', $church->id)->where('type', 'prayer')
            ->where(fn ($w) => $w->where('status', 'open')->orWhere(fn ($a) => $a->where('status', 'answered')->whereYear('closed_on', $today->year)))
            ->with(['person', 'carers', 'author'])->orderByDesc('on')->get();

        return $rows->map(fn ($r) => $this->row($r, $user, $church) + ['closed_on' => $r->closed_on?->toDateString()])->values()->all();
    }

    /** The monthly report's figures: visits and care by type for the month, and the testimonies people agreed to share. */
    public function monthly(Territory $church, int $year, int $month): array
    {
        $m = CarbonImmutable::create($year, $month, 1, 0, 0, 0, People::TZ);
        $range = [$m->toDateString(), $m->endOfMonth()->toDateString()];
        $byType = CareRecord::where('territory_id', $church->id)->whereBetween('on', $range)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        return [
            'visits' => $this->visitsIn($church, $m),
            'by_type' => collect(CareRecord::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'count' => (int) ($byType[$k] ?? 0)])->filter(fn ($t) => $t['count'])->values()->all(),
            'testimonies' => CareRecord::where('territory_id', $church->id)->where('type', 'prayer')->where('status', 'answered')->where('share_testimony', true)
                ->whereBetween('closed_on', $range)->whereNotNull('testimony')->pluck('testimony')->all(),
        ];
    }

    /** For the region and diocese: each church below, counts only - never a name or a note. */
    public function totals(Territory $place): array
    {
        $today = $this->today();
        $churches = Territory::whereIn('id', PlaceAccess::descendantIds($place) ?: [0])->where('territory_type', 'church')->orderBy('name')->get(['id', 'name']);
        $ids = $churches->pluck('id')->all() ?: [0];
        $by = fn (Builder $q) => $q->selectRaw('territory_id, count(*) as n')->groupBy('territory_id')->pluck('n', 'territory_id');
        $base = fn () => CareRecord::query()->whereIn('territory_id', $ids);
        $month = [$today->startOfMonth()->toDateString(), $today->toDateString()];
        $visitsMonth = $by($base()->whereIn('type', CareRecord::VISITS)->whereBetween('on', $month));
        $visitsYear = $by($base()->whereIn('type', CareRecord::VISITS)->whereYear('on', $today->year));
        $open = $by($base()->where('status', 'open'));
        $hospital = $by($base()->where('type', 'hospital')->where('status', 'open')->whereNull('discharged_on'));
        $any = $by($base());
        $rows = $churches->map(fn ($c) => [
            'church' => ['id' => $c->id, 'name' => $c->name],
            'recording' => (int) ($any[$c->id] ?? 0) > 0,
            'visits_this_month' => (int) ($visitsMonth[$c->id] ?? 0),
            'visits_this_year' => (int) ($visitsYear[$c->id] ?? 0),
            'open' => (int) ($open[$c->id] ?? 0),
            'in_hospital' => (int) ($hospital[$c->id] ?? 0),
        ])->values();

        return [
            'churches' => $rows->count(),
            'recording' => $rows->where('recording', true)->count(),
            'visits_this_month' => $rows->sum('visits_this_month'),
            'visits_this_year' => $rows->sum('visits_this_year'),
            'open' => $rows->sum('open'),
            'in_hospital' => $rows->sum('in_hospital'),
            'rows' => $rows->all(),
        ];
    }

    private function initials(string $name): string
    {
        return mb_strtoupper(collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    }
}
