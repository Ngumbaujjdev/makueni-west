<?php

namespace App\Http\Controllers\Api\People;

use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Models\MinistryLeader;
use App\Models\MinistryMember;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Services\People\Ministries;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Models\Audit;

/**
 * Ministries (docs/specs/people-and-care-spec.md, P4). Every route answers
 * only for the church the user acts for. Those who manage ministries add
 * and change them; a ministry's own leader looks after its members and when
 * it meets, and nothing of another ministry's. The region and diocese get
 * /ministries/totals - counts, never a name.
 */
class MinistryController extends Controller
{
    public function __construct(private Ministries $ministries) {}

    /** GET /ministries/options - kinds, icons, colours, leaders to pick and gathering types to link. */
    public function options(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok([
            'kinds' => collect(Ministry::KINDS)->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0], 'icon' => $k[1], 'color' => $k[2]])->values(),
            'icons' => Ministry::ICONS,
            'colours' => Ministry::COLOURS,
            'roles' => collect(Ministry::ROLES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'days' => Ministry::DAYS,
            'leaders' => $this->ministries->leaderUsers($church)->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")])->values(),
            'gathering_types' => $this->ministries->gatheringTypes($church),
            'can' => $this->can($request->user(), $church),
        ]);
    }

    /** GET /ministries/overview - the cards and a card per ministry (?all=1 adds the ones switched off, for those who manage). */
    public function overview(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $can = $this->can($request->user(), $church);

        return $this->ok($this->ministries->overview($church, $request->user(), $request->boolean('all') && $can['manage']) + ['can' => $can]);
    }

    /** POST /ministries - a ministry of our own. */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $this->validated($request, $church);
        $m = DB::transaction(function () use ($d, $church, $request) {
            $m = Ministry::create(collect($d)->except('leaders')->all() + [
                'territory_id' => $church->id, 'active' => $d['active'] ?? true,
                'order' => (int) Ministry::where('territory_id', $church->id)->max('order') + 1,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            if (array_key_exists('leaders', $d)) {
                $this->syncLeaders($m, $d['leaders']);
            }

            return $m;
        });

        return $this->ok($this->detail($request, $church, $m->fresh()), "{$m->name} added.", 201);
    }

    /** GET /ministries/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($request, $church, $m));
    }

    /**
     * PUT /ministries/{id} - those who manage ministries change anything;
     * its own leader only when it meets.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }
        $manage = PeopleAccess::canNamed($request->user(), $church, 'ministries', 'manage');
        if (! $manage && ! $this->ministries->isLeader($m, $request->user())) {
            return $this->forbidden("Your role can't change this ministry.");
        }
        $d = $this->validated($request, $church, $m);
        if (! $manage) {
            $extra = array_diff(array_keys($d), ['meets_day', 'meets_time']);
            if ($extra) {
                return $this->forbidden('As its leader you can change when it meets - ask a pastor to change the rest.');
            }
        }
        DB::transaction(function () use ($m, $d, $request) {
            $m->fill(collect($d)->except('leaders')->all() + ['updated_by' => $request->user()->id])->save();
            if (array_key_exists('leaders', $d)) {
                $this->syncLeaders($m, $d['leaders']);
            }
        });

        return $this->ok($this->detail($request, $church, $m->fresh()), 'Saved.');
    }

    /** DELETE /ministries/{id} - one of our own; the standard six are switched off instead, so they come back as they were. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($m->standard) {
            return $this->unprocessable('id', "{$m->name} is one every church has - switch it off instead.");
        }
        $m->delete();

        return $this->ok(['id' => $m->id], "{$m->name} removed.");
    }

    /** PUT /ministries/{id}/leaders {leaders: [{user_id|person_id, role}]} */
    public function leaders(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate($this->leaderRules($church));
        $this->syncLeaders($m, $d['leaders'] ?? []);

        return $this->ok($this->detail($request, $church, $m->fresh()), 'Leaders saved.');
    }

    /** GET /ministries/{id}/members */
    public function members(Request $request, int $id): JsonResponse
    {
        [, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->ministries->members($m));
    }

    /** GET /ministries/{id}/candidates - our members and visitors not in it yet, to tick (the register's slim details only). */
    public function candidates(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->roster($request, $id);
        if ($error) {
            return $error;
        }
        $in = MinistryMember::where('ministry_id', $m->id)->pluck('person_id')->all();
        $rows = Person::where('territory_id', $church->id)->listed()->whereIn('status', Ministries::SERVING)->whereNotIn('id', $in ?: [0])
            ->orderBy('first_name')->orderBy('last_name')->limit(2000)->get();

        return $this->ok($rows->map(fn (Person $p) => [
            'id' => $p->id, 'name' => $p->name, 'initials' => $p->initials, 'area' => $p->area, 'gender' => $p->gender,
            'congregation' => $p->congregation, 'kind' => $p->status === 'visitor' ? 'visitor' : 'member',
        ])->values());
    }

    /** POST /ministries/{id}/members {person_ids[]} - from our register only. */
    public function addMembers(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->roster($request, $id);
        if ($error) {
            return $error;
        }
        $d = $request->validate(['person_ids' => ['required', 'array', 'min:1', 'max:500'], 'person_ids.*' => ['integer']]);
        $people = Person::where('territory_id', $church->id)->whereIn('id', $d['person_ids'])->whereIn('status', Ministries::SERVING)
            ->whereNull('anonymised_at')->whereNull('archived_at')->pluck('id');
        if ($people->isEmpty()) {
            return $this->unprocessable('person_ids', 'Pick people from our register.');
        }
        $already = MinistryMember::where('ministry_id', $m->id)->whereIn('person_id', $people)->pluck('person_id');
        $today = $this->ministries->today()->toDateString();
        foreach ($people->diff($already) as $pid) {
            MinistryMember::create(['ministry_id' => $m->id, 'person_id' => $pid, 'joined_on' => $today, 'added_by' => $request->user()->id]);
        }
        $added = $people->diff($already)->count();

        return $this->ok(['added' => $added, 'already' => $already->count(), 'skipped' => count(array_unique($d['person_ids'])) - $people->count()], $added ? "{$added} added to {$m->name}." : "They're already in {$m->name}.");
    }

    /** POST /ministries/{id}/members/remove {person_ids[]} */
    public function removeMembers(Request $request, int $id): JsonResponse
    {
        [, $m, $error] = $this->roster($request, $id);
        if ($error) {
            return $error;
        }
        $d = $request->validate(['person_ids' => ['required', 'array', 'min:1', 'max:500'], 'person_ids.*' => ['integer']]);
        $rows = MinistryMember::where('ministry_id', $m->id)->whereIn('person_id', $d['person_ids'])->get();
        $rows->each(fn (MinistryMember $mm) => $mm->delete());

        return $this->ok(['removed' => $rows->count()], "{$rows->count()} taken out of {$m->name}.");
    }

    /** GET /ministries/{id}/gatherings - its linked gathering's attendance over twelve months. */
    public function gatherings(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok(['linked' => (bool) $m->gathering_type_id, 'detail' => $this->ministries->gatheringDetail($church, $m)]);
    }

    /** GET /ministries/{id}/activities - our events and initiatives meant for it. */
    public function activities(Request $request, int $id): JsonResponse
    {
        [$church, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->ministries->activities($church, $m));
    }

    /** GET /ministries/{id}/history - in sentences. */
    public function history(Request $request, int $id): JsonResponse
    {
        [, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return $error;
        }
        $memberIds = DB::table('audits')->where('auditable_type', 'ministry_member')->where(fn ($q) => $q->where('new_values->ministry_id', $m->id)->orWhere('old_values->ministry_id', $m->id))->pluck('auditable_id')->all();
        $leaderIds = DB::table('audits')->where('auditable_type', 'ministry_leader')->where(fn ($q) => $q->where('new_values->ministry_id', $m->id)->orWhere('old_values->ministry_id', $m->id))->pluck('auditable_id')->all();
        $audits = Audit::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', 'ministry')->where('auditable_id', $m->id))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'ministry_member')->whereIn('auditable_id', $memberIds ?: [0]))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'ministry_leader')->whereIn('auditable_id', $leaderIds ?: [0])))
            ->latest('id')->limit(200)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->merge($audits->map(fn ($a) => ($a->new_values ?: $a->old_values)['user_id'] ?? null)->filter())->unique())->get()->keyBy('id');
        $people = Person::whereIn('id', $audits->map(fn ($a) => ($a->new_values ?: $a->old_values)['person_id'] ?? null)->filter()->unique())->get()->keyBy('id');
        $userName = fn ($id) => ($u = $users->get($id)) ? trim("{$u->firstname} {$u->lastname}") : 'someone';

        return $this->ok($audits->map(function (Audit $a) use ($userName, $people) {
            $who = $a->user_id ? $userName($a->user_id) : 'The system';
            $v = (array) ($a->new_values ?: $a->old_values);
            $person = isset($v['person_id']) ? ($people->get($v['person_id'])?->name ?? 'someone') : null;
            $sentence = match (true) {
                $a->auditable_type === 'ministry_member' && $a->event === 'created' => "{$who} added {$person}",
                $a->auditable_type === 'ministry_member' => "{$who} took {$person} out",
                $a->auditable_type === 'ministry_leader' && $a->event === 'created' => "{$who} made ".($v['user_id'] ?? null ? $userName($v['user_id']) : $person).' '.strtolower(Ministry::ROLES[$v['role'] ?? 'leader'] ?? 'leader'),
                $a->auditable_type === 'ministry_leader' && $a->event === 'deleted' => "{$who} stood ".($v['user_id'] ?? null ? $userName($v['user_id']) : $person).' down as '.strtolower(Ministry::ROLES[$v['role'] ?? 'leader'] ?? 'leader'),
                $a->auditable_type === 'ministry_leader' => "{$who} changed a leader's role",
                $a->event === 'created' => "{$who} added the ministry",
                array_key_exists('active', $v) && count($v) === 1 => $who.($v['active'] ? ' switched it back on' : ' switched it off'),
                default => "{$who} changed ".collect(array_keys($v))->map(fn ($k) => ['meets_day' => 'the day', 'meets_time' => 'the time', 'gathering_type_id' => 'its gathering'][$k] ?? str_replace('_', ' ', $k))->implode(', '),
            };

            return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
        })->values());
    }

    /** GET /ministries/insights */
    public function insights(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->ministries->insights($church));
    }

    /** GET /ministries/totals - the region's and diocese's view. */
    public function totals(Request $request): JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! PeopleAccess::canTotals($request->user(), $place, 'ministries')) {
            return $this->forbidden("Your role can't see the churches' ministry totals.");
        }

        return $this->ok($this->ministries->totals($place) + ['place' => ['id' => $place->id, 'name' => $place->name, 'type' => PlaceAccess::level($place)]]);
    }

    // ------------------------------------------------------------------ helpers

    private function detail(Request $request, Territory $church, Ministry $m): array
    {
        $m->loadMissing(['leaders.user', 'leaders.person', 'gatheringType']);
        $count = (int) ($this->ministries->memberCounts([$m->id])[$m->id] ?? 0);

        return $this->ministries->row($m, $count) + [
            'can' => $this->can($request->user(), $church) + ['roster' => $this->ministries->canRoster($m, $request->user(), $church), 'mine' => $this->ministries->isLeader($m, $request->user())],
        ];
    }

    private function can(?User $user, Territory $church): array
    {
        return [
            'manage' => PeopleAccess::canNamed($user, $church, 'ministries', 'manage'),
            'members' => PeopleAccess::canNamed($user, $church, 'members'),
        ];
    }

    private function leaderRules(Territory $church): array
    {
        return [
            'leaders' => ['present', 'array', 'max:12'],
            'leaders.*.user_id' => ['nullable', 'integer', 'required_without:leaders.*.person_id', Rule::in($this->ministries->leaderUsers($church)->pluck('id')->all())],
            'leaders.*.person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->where('territory_id', $church->id)->whereNull('anonymised_at')],
            'leaders.*.role' => ['required', Rule::in(array_keys(Ministry::ROLES))],
        ];
    }

    private function validated(Request $request, Territory $church, ?Ministry $m = null): array
    {
        $s = $m ? 'sometimes' : 'required';
        $rules = [
            'name' => [$s, 'string', 'max:80', Rule::unique('ministries', 'name')->where('territory_id', $church->id)->whereNull('deleted_at')->ignore($m?->id)],
            'kind' => [$s, Rule::in(array_keys(Ministry::KINDS))],
            'icon' => ['sometimes', 'nullable', Rule::in(Ministry::ICONS)],
            'colour' => ['sometimes', 'nullable', Rule::in(Ministry::COLOURS)],
            'meets_day' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'meets_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'gathering_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('gathering_types', 'id')->where('territory_id', $church->id)],
            'active' => ['sometimes', 'boolean'],
        ];
        if ($request->has('leaders')) {
            $rules += $this->leaderRules($church);
        }
        $d = $request->validate($rules, [
            'name.unique' => 'There is already a ministry with that name.',
            'gathering_type_id.exists' => 'Pick one of our own gathering types.',
            'leaders.*.user_id.in' => 'Pick leaders from our church.',
        ]);
        if ($m) {
            $d = array_filter($d, fn ($v, $k) => $request->exists($k), ARRAY_FILTER_USE_BOTH);
            // A standard ministry keeps its kind - Youth stays youth.
            if ($m->standard) {
                unset($d['kind']);
            }
        }

        return $d;
    }

    /** Leaders replaced by the list given - removals and additions each audited. */
    private function syncLeaders(Ministry $m, array $list): void
    {
        $key = fn ($l) => (($l['user_id'] ?? null) ? "u{$l['user_id']}" : 'p'.($l['person_id'] ?? 0));
        $wanted = collect($list)->filter(fn ($l) => ! empty($l['user_id']) || ! empty($l['person_id']))->keyBy($key);
        $current = MinistryLeader::where('ministry_id', $m->id)->get()->keyBy(fn ($l) => $l->user_id ? "u{$l->user_id}" : "p{$l->person_id}");
        foreach ($current as $k => $l) {
            if (! $wanted->has($k)) {
                $l->delete();
            } elseif ($l->role !== $wanted[$k]['role']) {
                $l->update(['role' => $wanted[$k]['role']]);
            }
        }
        foreach ($wanted as $k => $l) {
            if (! $current->has($k)) {
                MinistryLeader::create(['ministry_id' => $m->id, 'user_id' => $l['user_id'] ?? null, 'person_id' => ($l['user_id'] ?? null) ? null : ($l['person_id'] ?? null), 'role' => $l['role']]);
            }
        }
    }

    private function church(Request $request, string $ability = 'read'): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return $this->forbidden('Ministries are kept by each church - only its own leaders see who serves in them.');
        }
        if (! PeopleAccess::canNamed($request->user(), $place, 'ministries', $ability)) {
            return $this->forbidden($ability === 'manage' ? "Your role can't change ministries." : "Your role can't see ministries.");
        }

        return $place;
    }

    /** [church, ministry, error] */
    private function ministry(Request $request, int $id, string $ability = 'read'): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $m = Ministry::where('territory_id', $church->id)->find($id);

        return $m ? [$church, $m, null] : [$church, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That ministry isn\'t in your church.'], 404)];
    }

    /** [church, ministry, error] - those who manage ministries, or its own leader. */
    private function roster(Request $request, int $id): array
    {
        [$church, $m, $error] = $this->ministry($request, $id);
        if ($error) {
            return [$church, $m, $error];
        }
        if (! $this->ministries->canRoster($m, $request->user(), $church)) {
            return [$church, $m, $this->forbidden("Only those who manage ministries, and {$m->name}'s own leaders, can change who serves in it.")];
        }

        return [$church, $m, null];
    }

    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
