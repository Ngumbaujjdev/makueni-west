<?php

namespace App\Services\People;

use App\Models\ChurchDemographic;
use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitorFollowup;
use App\Models\VisitorVisit;
use App\Support\Phone;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Models\Audit;

/**
 * The member register's figures (docs/specs/people-and-care-spec.md, P1):
 * the list and its filters, the Overview's cards, Insights, and the counts
 * the region and diocese see. All dates are Nairobi days.
 */
final class People
{
    public const TZ = 'Africa/Nairobi';

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** The list, filtered: status[], gender, congregation, area, baptised, joined_year, q (name, phone or area), archived. */
    public function query(Territory $church, array $f): Builder
    {
        $q = Person::query()->where('territory_id', $church->id)->whereNull('anonymised_at');
        empty($f['archived']) ? $q->whereNull('archived_at') : $q->whereNotNull('archived_at');
        $statuses = array_values(array_filter((array) ($f['status'] ?? [])));
        $statuses ? $q->whereIn('status', $statuses) : $q->where('status', '!=', 'visitor');
        if (! empty($f['gender'])) {
            $q->where('gender', $f['gender']);
        }
        if (! empty($f['congregation'])) {
            $q->where('congregation', $f['congregation']);
        }
        if (! empty($f['area'])) {
            $q->where('area', $f['area']);
        }
        if (isset($f['baptised']) && $f['baptised'] !== '') {
            filter_var($f['baptised'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('baptised_on') : $q->whereNull('baptised_on');
        }
        if (! empty($f['joined_year'])) {
            $q->whereYear('joined_on', (int) $f['joined_year']);
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $phone = self::phoneLike($term);
            $q->where(function ($w) use ($term, $phone) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('area', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
                if ($phone) {
                    $w->orWhere('phone', 'like', $phone);
                }
            });
        }

        return $q;
    }

    /**
     * A search that is a phone number, or part of one ("0712", "345 678"),
     * as a LIKE pattern on the stored +254... number - or null for a name.
     */
    public static function phoneLike(string $term): ?string
    {
        if (preg_match('/[^\d\s+()-]/', $term)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $term);
        if (strlen($digits) >= 9) {
            return '%'.Phone::key($digits);
        }
        $digits = preg_replace('/^(254|0)/', '', $digits);

        return strlen($digits) >= 3 ? '%'.$digits.'%' : null;
    }

    /** One row of the list. */
    public function row(Person $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'initials' => $p->initials,
            'phone' => $p->phone,
            'area' => $p->area,
            'gender' => $p->gender,
            'congregation' => $p->congregation,
            'status' => $p->status,
            'joined_on' => $p->joined_on?->toDateString(),
            'baptised' => (bool) $p->baptised_on,
            'archived' => (bool) $p->archived_at,
            'ministries' => [],
        ];
    }

    /** Possible duplicates: the same phone, or the same first and last name. */
    public function duplicates(Territory $church, ?string $phone, ?string $first, ?string $last, ?int $except = null): Collection
    {
        $key = Phone::key($phone);
        if (! $key && (! $first || ! $last)) {
            return collect();
        }

        return Person::where('territory_id', $church->id)->whereNull('anonymised_at')
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where(function ($w) use ($key, $first, $last) {
                if ($key) {
                    $w->orWhere('phone', 'like', '%'.$key);
                }
                if ($first && $last) {
                    $w->orWhere(fn ($n) => $n->where('first_name', $first)->where('last_name', $last));
                }
            })
            ->limit(5)->get()
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'phone' => $p->phone, 'status' => $p->status]);
    }

    /** The Overview cards and a year of joins and leavings. */
    public function overview(Territory $church): array
    {
        $today = $this->today();
        $members = Person::where('territory_id', $church->id)->listed()->members();
        $active = (clone $members)->count();
        $baptised = (clone $members)->whereNotNull('baptised_on')->count();
        $joinedIn = fn (CarbonImmutable $m) => Person::where('territory_id', $church->id)->whereNull('anonymised_at')->where('status', '!=', 'visitor')
            ->whereBetween('joined_on', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])->count();
        $leftIn = fn (CarbonImmutable $from, CarbonImmutable $to) => PersonTransfer::where('territory_id', $church->id)->where('direction', 'out')
            ->whereBetween('on', [$from->toDateString(), $to->toDateString()])->count()
            + Person::where('territory_id', $church->id)->whereIn('status', ['inactive', 'deceased'])
                ->whereBetween('updated_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])->count();

        $months = collect(range(11, 0))->map(fn ($i) => $today->startOfMonth()->subMonthsNoOverflow($i));

        return [
            'active' => $active,
            'new_this_month' => $joinedIn($today),
            'new_last_month' => $joinedIn($today->subMonthNoOverflow()),
            'baptised' => $baptised,
            'baptised_share' => $active ? round($baptised / $active * 100) : 0,
            'leaving_this_year' => $leftIn($today->startOfYear(), $today),
            'sunday_school' => (clone $members)->where('congregation', 'sunday_school')->count(),
            'archived' => Person::where('territory_id', $church->id)->whereNotNull('archived_at')->whereNull('anonymised_at')->count(),
            'months' => $months->map(fn ($m) => $m->format('M'))->all(),
            'joins' => $months->map(fn ($m) => $joinedIn($m))->all(),
            'leaves' => $months->map(fn ($m) => $leftIn($m->startOfMonth(), $m->endOfMonth()))->all(),
            'active_series' => $this->activeSeries($church, $months),
        ];
    }

    /** Members on the books at the end of each month (by joined date; those who left are not counted back). */
    private function activeSeries(Territory $church, Collection $months): array
    {
        $dates = Person::where('territory_id', $church->id)->listed()->members()->pluck('joined_on');

        return $months->map(fn (CarbonImmutable $m) => $dates->filter(fn ($d) => ! $d || $d->toDateString() <= $m->endOfMonth()->toDateString())->count())->all();
    }

    /** Insights: Sunday school and main church by gender, where members live, and the register beside the last Demographics count. */
    public function insights(Territory $church): array
    {
        $members = Person::where('territory_id', $church->id)->listed()->members()->get(['id', 'gender', 'congregation', 'area']);
        $groups = collect(Person::CONGREGATIONS + ['' => 'Not set'])->map(fn ($label, $key) => [
            'key' => $key ?: null, 'label' => $label,
            'male' => $members->filter(fn ($p) => (string) $p->congregation === (string) $key && $p->gender === 'male')->count(),
            'female' => $members->filter(fn ($p) => (string) $p->congregation === (string) $key && $p->gender === 'female')->count(),
            'unknown' => $members->filter(fn ($p) => (string) $p->congregation === (string) $key && ! $p->gender)->count(),
        ])->values()->filter(fn ($g) => $g['key'] || $g['male'] + $g['female'] + $g['unknown'])->values();
        $last = ChurchDemographic::where('territory_id', $church->id)->orderByDesc('id')->first();

        return [
            'groups' => $groups->all(),
            'gender' => ['male' => $members->where('gender', 'male')->count(), 'female' => $members->where('gender', 'female')->count(), 'unknown' => $members->whereNull('gender')->count()],
            'areas' => $this->areas($members),
            'register' => $this->registerCounts($church),
            'demographics' => $last ? [
                'total' => (int) $last->total_members, 'male' => (int) $last->male_count, 'female' => (int) $last->female_count,
                'sunday_school' => (int) $last->sunday_school_male_count + (int) $last->sunday_school_female_count,
                'recorded_at' => $last->created_at?->toDateString(),
            ] : null,
        ];
    }

    /** Where people live: the top areas and how many in each ("Not given" last). */
    public function areas(Collection $people, int $top = 8): array
    {
        $by = $people->groupBy(fn ($p) => $p->area ?: '')->map->count()->sortDesc();
        $named = $by->except([''])->take($top)->map(fn ($n, $area) => ['area' => $area, 'count' => $n])->values();
        $rest = $by->except([''])->slice($top)->sum();

        return array_values(array_filter([
            ...$named->all(),
            $rest ? ['area' => 'Other areas', 'count' => $rest] : null,
            ($by[''] ?? 0) ? ['area' => 'Not given', 'count' => $by['']] : null,
        ]));
    }

    /** The register's counts in Demographics' words - shown beside its form as a hint, never filled in. */
    public function registerCounts(Territory $church): array
    {
        $today = $this->today();
        $members = Person::where('territory_id', $church->id)->listed()->members()->get();
        $school = $members->where('congregation', 'sunday_school');

        return [
            'total' => $members->count(),
            'male' => $members->where('gender', 'male')->count(),
            'female' => $members->where('gender', 'female')->count(),
            'sunday_school_male' => $school->where('gender', 'male')->count(),
            'sunday_school_female' => $school->where('gender', 'female')->count(),
            'new_this_month' => $members->filter(fn ($p) => $p->joined_on && $p->joined_on->format('Y-m') === $today->format('Y-m'))->count(),
            'baptised_this_month' => $members->filter(fn ($p) => $p->baptised_on && $p->baptised_on->format('Y-m') === $today->format('Y-m'))->count(),
        ];
    }

    /** For the region and diocese: each church below, counts only - never a name. */
    public function totals(Territory $place): array
    {
        $today = $this->today();
        $churches = Territory::whereIn('id', PlaceAccess::descendantIds($place) ?: [0])->where('territory_type', 'church')->orderBy('name')->get(['id', 'name', 'parent_territory_id']);
        $ids = $churches->pluck('id')->all() ?: [0];
        $count = fn (Builder $q) => $q->selectRaw('territory_id, count(*) as n')->groupBy('territory_id')->pluck('n', 'territory_id');
        $base = fn () => Person::query()->whereIn('territory_id', $ids)->whereNull('anonymised_at');
        $active = $count($base()->whereNull('archived_at')->where('status', 'member'));
        $any = $count($base());
        $new = $count($base()->where('status', '!=', 'visitor')->whereBetween('joined_on', [$today->startOfMonth()->toDateString(), $today->toDateString()]));
        $baptised = $count($base()->whereNull('archived_at')->where('status', 'member')->whereNotNull('baptised_on'));
        $school = $count($base()->whereNull('archived_at')->where('status', 'member')->where('congregation', 'sunday_school'));
        $transfers = fn (string $dir) => $count(PersonTransfer::query()->whereIn('territory_id', $ids)->where('direction', $dir)->whereYear('on', $today->year));
        $in = $transfers('in');
        $out = $transfers('out');

        $rows = $churches->map(fn ($c) => [
            'church' => ['id' => $c->id, 'name' => $c->name],
            'keeps_register' => (int) ($any[$c->id] ?? 0) > 0,
            'active' => (int) ($active[$c->id] ?? 0),
            'new_this_month' => (int) ($new[$c->id] ?? 0),
            'baptised' => (int) ($baptised[$c->id] ?? 0),
            'sunday_school' => (int) ($school[$c->id] ?? 0),
            'transfers_in' => (int) ($in[$c->id] ?? 0),
            'transfers_out' => (int) ($out[$c->id] ?? 0),
        ])->values();

        return [
            'churches' => $rows->count(),
            'keeping_register' => $rows->where('keeps_register', true)->count(),
            'active' => $rows->sum('active'),
            'new_this_month' => $rows->sum('new_this_month'),
            'baptised' => $rows->sum('baptised'),
            'sunday_school' => $rows->sum('sunday_school'),
            'transfers_in' => $rows->sum('transfers_in'),
            'transfers_out' => $rows->sum('transfers_out'),
            'rows' => $rows->all(),
        ];
    }

    /**
     * A person's history in plain sentences - their record, transfers,
     * visits and follow-ups. Private fields only ever show as "changed".
     */
    public function history(Person $person): array
    {
        $ids = fn (string $model) => $model::where('person_id', $person->id)->pluck('id')->all() ?: [0];
        $audits = Audit::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auditable_type', 'person')->where('auditable_id', $person->id))
                ->orWhere(fn ($q) => $q->where('auditable_type', 'person_transfer')->whereIn('auditable_id', $ids(PersonTransfer::class)))
                ->orWhere(fn ($q) => $q->where('auditable_type', 'visitor_visit')->whereIn('auditable_id', $ids(VisitorVisit::class)))
                ->orWhere(fn ($q) => $q->where('auditable_type', 'visitor_followup')->whereIn('auditable_id', $ids(VisitorFollowup::class))))
            ->latest('id')->limit(200)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $labels = [
            'first_name' => 'first name', 'last_name' => 'last name', 'congregation' => 'Sunday school or main church',
            'joined_on' => 'date joined', 'how_joined' => 'how they joined', 'previous_church' => 'previous church',
            'saved_on' => 'salvation date', 'baptised_on' => 'baptism date',
            'consent_contact' => '"don\'t text them"', 'assigned_to' => 'who follows them up',
            'first_visit_on' => 'first visit', 'last_visit_on' => 'last visit', 'visit_count' => 'visits', 'became_member_on' => 'date they became a member',
        ];
        $quiet = ['updated_by', 'anonymised_at', 'archived_at', 'last_visit_on', 'visit_count'];

        return $audits->map(function (Audit $a) use ($users, $labels, $quiet) {
            $who = ($u = $users->get($a->user_id)) ? trim("{$u->firstname} {$u->lastname}") : 'The system';
            $new = (array) $a->new_values;
            $sentence = match ($a->auditable_type) {
                'person_transfer' => ($new['direction'] ?? '') === 'in' ? "{$who} recorded a transfer in" : "{$who} recorded a transfer out",
                'visitor_visit' => $a->event === 'created' ? "{$who} recorded a visit".(! empty($new['on']) ? ' on '.CarbonImmutable::parse($new['on'])->format('j M Y') : '') : "{$who} changed a visit",
                'visitor_followup' => $a->event === 'created' ? "{$who} logged a follow-up (".strtolower(VisitorFollowup::TYPES[$new['type'] ?? ''] ?? 'other').')' : "{$who} changed a follow-up",
                default => match (true) {
                    $a->event === 'created' => ($new['status'] ?? '') === 'visitor' ? "{$who} recorded their first visit" : "{$who} added them to the register",
                    array_key_exists('anonymised_at', $new) && $new['anonymised_at'] => "{$who} removed their personal details",
                    array_key_exists('archived_at', $new) => $new['archived_at'] ? "{$who} archived them" : "{$who} brought them back from the archive",
                    ($new['stage'] ?? null) === 'member' => "{$who} marked that they became a member",
                    isset($new['stage']) && count(array_diff(array_keys($new), ['stage', 'updated_by'])) === 0 => "{$who} moved them to ".strtolower(Person::STAGES[$new['stage']] ?? $new['stage']),
                    isset($new['status']) && count($new) <= 2 => "{$who} marked them ".strtolower(Person::STATUSES[$new['status']] ?? $new['status']),
                    default => "{$who} changed ".(collect(array_keys($new))->reject(fn ($k) => in_array($k, $quiet, true))
                        ->map(fn ($k) => $labels[$k] ?? str_replace('_', ' ', $k))->implode(', ') ?: 'their visits'),
                },
            };

            return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
        })->values()->all();
    }
}
