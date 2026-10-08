<?php

namespace App\Http\Controllers\Api\People;

use App\Http\Controllers\Controller;
use App\Models\CareContact;
use App\Models\CareRecord;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Services\People\Care;
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Models\Audit;

/**
 * Pastoral care (docs/specs/people-and-care-spec.md, P3). Every route
 * answers only for the church the user acts for; a confidential record's
 * notes go only to its author and the confidential-care holders. The region
 * and diocese get /care/totals - counts, never a name or a note.
 */
class CareController extends Controller
{
    public function __construct(private Care $care) {}

    /** GET /care/options - kinds of care, carers, the register search is /people/search. */
    public function options(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok([
            'types' => collect(CareRecord::TYPES)->only($this->care->types($church))->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'icon' => $t[1], 'color' => $t[2]])->values(),
            'contact_types' => collect(CareContact::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'icon' => $t[1]])->values(),
            'carers' => $this->care->carers($church)->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")])->values(),
            'hospital_days' => (int) (app(Settings::class)->get('pastoral.hospital_days', $church) ?: 7),
            'can' => $this->can($request->user(), $church),
        ]);
    }

    /** GET /care/overview */
    public function overview(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->care->overview($church, $request->user()) + ['can' => $this->can($request->user(), $church)]);
    }

    /** GET /care?type[]=&status[]=&leader=&month=&person_id= - the log. */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $f = $request->validate([
            'type' => ['nullable', 'array'], 'type.*' => [Rule::in(array_keys(CareRecord::TYPES))],
            'status' => ['nullable', 'array'], 'status.*' => [Rule::in(array_keys(CareRecord::STATUSES))],
            'leader' => ['nullable', 'integer'], 'month' => ['nullable', 'date_format:Y-m'], 'person_id' => ['nullable', 'integer'],
        ]);
        $rows = $this->care->query($church, $f)->with('contacts')->orderByDesc('on')->orderByDesc('id')->limit(3000)->get();

        return $this->ok(['items' => $rows->map(fn ($r) => $this->care->row($r, $request->user(), $church))->values(), 'total' => $rows->count()]);
    }

    /** POST /care - care given or a need noted. */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $this->validated($request, $church);
        $record = DB::transaction(function () use ($d, $church, $request) {
            $open = in_array($d['type'], ['hospital', 'prayer', 'counselling', 'concern'], true) || ! empty($d['next_on']) || ($d['priority'] ?? 'normal') === 'high';
            $record = CareRecord::create(collect($d)->except('carers')->all() + [
                'territory_id' => $church->id, 'status' => $open ? 'open' : 'closed', 'closed_on' => $open ? null : $d['on'],
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $record->carers()->sync(array_unique([...($d['carers'] ?? []), $request->user()->id]));

            return $record;
        });

        return $this->ok($this->detail($request, $church, $record->fresh()), 'Care recorded.', 201);
    }

    /** GET /care/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($request, $church, $record));
    }

    /** PUT /care/{id} - a confidential record only by its author or the confidential-care holders. */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if (! $this->care->canSeeNote($record, $request->user(), $church)) {
            return $this->forbidden('This is confidential - only the pastor who recorded it can change it.');
        }
        $d = $this->validated($request, $church, $record);
        DB::transaction(function () use ($record, $d, $request) {
            $record->fill(collect($d)->except('carers')->all() + ['updated_by' => $request->user()->id])->save();
            if (array_key_exists('carers', $d)) {
                $record->carers()->sync(array_unique([...$d['carers'], (int) $record->created_by]));
            }
        });

        return $this->ok($this->detail($request, $church, $record->fresh()), 'Saved.');
    }

    /** POST /care/{id}/contacts {on, type, note?, next_on?} - another visit, call or prayer on an open case. */
    public function contact(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($record->confidential && ! $this->care->canSeeNote($record, $request->user(), $church)) {
            return $this->forbidden('This is confidential - only the pastor who recorded it can add to it.');
        }
        $today = $this->care->today()->toDateString();
        $d = $request->validate([
            'on' => ['required', 'date', "before_or_equal:{$today}"],
            'type' => ['required', Rule::in(array_keys(CareContact::TYPES))],
            'note' => ['nullable', 'string', 'max:2000'],
            'next_on' => ['nullable', 'date', 'after_or_equal:on'],
        ], ['next_on.after_or_equal' => 'The next step comes after this one.']);
        CareContact::create($d + ['care_record_id' => $record->id, 'territory_id' => $church->id, 'done_by' => $request->user()->id]);
        $record->forceFill(['next_on' => $d['next_on'] ?? null, 'status' => 'open', 'closed_on' => null, 'updated_by' => $request->user()->id])->save();
        $record->carers()->syncWithoutDetaching([$request->user()->id]);

        return $this->ok($this->detail($request, $church, $record->fresh()), 'Added.', 201);
    }

    /** POST /care/{id}/close {status: closed|answered, testimony?, share_testimony} */
    public function close(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate([
            'status' => ['required', Rule::in(['closed', 'answered'])],
            'testimony' => ['nullable', 'string', 'max:1000'],
            'share_testimony' => ['nullable', 'boolean'],
        ]);
        if ($d['status'] === 'answered' && $record->type !== 'prayer') {
            return $this->unprocessable('status', 'Only a prayer is answered - close the others.');
        }
        $share = $d['status'] === 'answered' && $request->boolean('share_testimony') && ! empty($d['testimony']);
        $record->forceFill([
            'status' => $d['status'], 'closed_on' => $this->care->today()->toDateString(), 'next_on' => null,
            'testimony' => $d['status'] === 'answered' ? ($d['testimony'] ?? null) : $record->testimony, 'share_testimony' => $share,
            'updated_by' => $request->user()->id,
        ])->save();

        return $this->ok($this->detail($request, $church, $record->fresh()), $d['status'] === 'answered' ? 'Marked answered - thank God.' : 'Closed.');
    }

    /** POST /care/{id}/discharge {on?} - home from hospital. */
    public function discharge(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($record->type !== 'hospital') {
            return $this->unprocessable('on', 'Only a hospital stay has a discharge.');
        }
        $d = $request->validate(['on' => ['nullable', 'date', 'before_or_equal:'.$this->care->today()->toDateString()]]);
        $on = $d['on'] ?? $this->care->today()->toDateString();
        $record->forceFill(['discharged_on' => $on, 'status' => 'closed', 'closed_on' => $on, 'next_on' => null, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $church, $record->fresh()), "{$record->who} is home.");
    }

    /** POST /care/bulk {ids[], action: close|assign, user_id?} */
    public function bulk(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['close', 'assign'])],
            'user_id' => ['required_if:action,assign', 'nullable', 'integer', Rule::in($this->care->carers($church)->pluck('id')->all())],
        ], ['user_id.in' => 'Pick a leader who gives pastoral care.']);
        $done = 0;
        foreach (CareRecord::where('territory_id', $church->id)->whereIn('id', $d['ids'])->get() as $record) {
            if ($d['action'] === 'close' && $record->status === 'open') {
                $record->forceFill(['status' => 'closed', 'closed_on' => $this->care->today()->toDateString(), 'next_on' => null, 'updated_by' => $request->user()->id])->save();
                $done++;
            } elseif ($d['action'] === 'assign') {
                $record->carers()->syncWithoutDetaching([$d['user_id']]);
                $done++;
            }
        }

        return $this->ok(['done' => $done, 'skipped' => count(array_unique($d['ids'])) - $done], $d['action'] === 'close' ? "{$done} closed." : "{$done} given to them.");
    }

    /** GET /care/hospital */
    public function hospital(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->care->hospital($church, $request->user()));
    }

    /** GET /care/prayer */
    public function prayer(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->care->prayer($church, $request->user()));
    }

    /** GET /people/{id}/care - the member's or visitor's Care tab. */
    public function person(Request $request, int $id): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        if (! Person::where('territory_id', $church->id)->whereKey($id)->exists()) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That person isn\'t in your records.'], 404);
        }
        $rows = $this->care->query($church, ['person_id' => $id])->with('contacts')->orderByDesc('on')->get();

        return $this->ok($rows->map(fn ($r) => $this->care->row($r, $request->user(), $church))->values());
    }

    /** GET /care/{id}/history - in sentences; notes only ever as "changed". */
    public function history(Request $request, int $id): JsonResponse
    {
        [$church, $record, $error] = $this->record($request, $id);
        if ($error) {
            return $error;
        }
        $contactIds = CareContact::where('care_record_id', $record->id)->pluck('id')->all() ?: [0];
        $audits = Audit::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', 'care_record')->where('auditable_id', $record->id))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'care_contact')->whereIn('auditable_id', $contactIds)))->latest('id')->limit(200)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');

        return $this->ok($audits->map(function (Audit $a) use ($users) {
            $who = ($u = $users->get($a->user_id)) ? trim("{$u->firstname} {$u->lastname}") : 'The system';
            $new = (array) $a->new_values;
            $sentence = match (true) {
                $a->auditable_type === 'care_contact' => "{$who} added a ".strtolower(CareContact::TYPES[$new['type'] ?? 'other'][0] ?? 'contact'),
                $a->event === 'created' => "{$who} recorded it",
                ($new['status'] ?? null) === 'answered' => "{$who} marked the prayer answered",
                ($new['status'] ?? null) === 'closed' => "{$who} closed it",
                array_key_exists('discharged_on', $new) && $new['discharged_on'] => "{$who} recorded they're home from hospital",
                default => "{$who} changed ".collect(array_keys($new))->reject(fn ($k) => in_array($k, ['updated_by', 'closed_on'], true))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', '),
            };

            return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
        })->values());
    }

    /** GET /care/totals - the region's and diocese's view. */
    public function totals(Request $request): JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! PeopleAccess::canTotals($request->user(), $place, 'pastoral')) {
            return $this->forbidden("Your role can't see the churches' pastoral care totals.");
        }

        return $this->ok($this->care->totals($place) + ['place' => ['id' => $place->id, 'name' => $place->name, 'type' => PlaceAccess::level($place)]]);
    }

    // ------------------------------------------------------------------ helpers

    private function detail(Request $request, Territory $church, CareRecord $r): array
    {
        $r->loadMissing(['person', 'carers', 'author', 'contacts.doer']);
        $see = $this->care->canSeeNote($r, $request->user(), $church);

        return $this->care->row($r, $request->user(), $church) + [
            'contacts' => $r->contacts->map(fn (CareContact $c) => [
                'id' => $c->id, 'on' => $c->on->toDateString(), 'type' => $c->type, 'note' => $see ? $c->note : null, 'note_hidden' => ! $see && $c->note !== null,
                'next_on' => $c->next_on?->toDateString(), 'by' => $c->doer ? trim("{$c->doer->firstname} {$c->doer->lastname}") : null,
            ])->values(),
            'can' => $this->can($request->user(), $church) + ['change' => $see && PeopleAccess::canNamed($request->user(), $church, 'pastoral', 'manage')],
        ];
    }

    private function can(?User $user, Territory $church): array
    {
        return [
            'manage' => PeopleAccess::canNamed($user, $church, 'pastoral', 'manage'),
            'confidential' => PeopleAccess::canNamed($user, $church, 'pastoral', 'confidential'),
            'members' => PeopleAccess::canNamed($user, $church, 'members'),
        ];
    }

    private function validated(Request $request, Territory $church, ?CareRecord $record = null): array
    {
        $today = $this->care->today()->toDateString();
        $s = $record ? 'sometimes' : 'required';
        $d = $request->validate([
            'person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->where('territory_id', $church->id)->whereNull('anonymised_at')],
            'person_name' => [$record ? 'sometimes' : 'required_without:person_id', 'nullable', 'string', 'max:120'],
            'type' => [$s, Rule::in($this->care->types($church))],
            'priority' => ['sometimes', Rule::in(['normal', 'high'])],
            'on' => [$s, 'date', "before_or_equal:{$today}"],
            'hospital' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confidential' => ['sometimes', 'boolean'],
            'next_on' => ['nullable', 'date', 'after_or_equal:on'],
            'carers' => ['sometimes', 'array'], 'carers.*' => ['integer', Rule::in($this->care->carers($church)->pluck('id')->all())],
        ], [
            'person_name.required_without' => 'Pick someone from the register, or type their name.',
            'type.in' => 'Pick the kind of care.',
            'on.before_or_equal' => "The date can't be in the future.",
            'next_on.after_or_equal' => 'The next step comes after the care itself.',
            'carers.*.in' => 'Pick leaders who give pastoral care.',
        ]);
        if ($record) {
            $d = array_filter($d, fn ($v, $k) => $request->exists($k), ARRAY_FILTER_USE_BOTH);
        }
        if (! empty($d['person_id'])) {
            $d['person_name'] = null;
        }
        $type = $d['type'] ?? $record?->type;
        // Counselling is always confidential; only a hospital stay has a hospital.
        if ($type === 'counselling') {
            $d['confidential'] = true;
        }
        if ($type !== 'hospital' && (! $record || array_key_exists('type', $d) || array_key_exists('hospital', $d))) {
            $d['hospital'] = null;
        }

        return $d;
    }

    private function church(Request $request, string $ability = 'read'): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return $this->forbidden('Pastoral care is kept by each church - only its own leaders see it.');
        }
        if (! PeopleAccess::canNamed($request->user(), $place, 'pastoral', $ability)) {
            return $this->forbidden($ability === 'manage' ? "Your role can't record pastoral care." : "Your role can't see pastoral care.");
        }

        return $place;
    }

    /** [church, record, error] */
    private function record(Request $request, int $id, string $ability = 'read'): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $record = CareRecord::where('territory_id', $church->id)->find($id);

        return $record ? [$church, $record, null] : [$church, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That care record isn\'t in your church.'], 404)];
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
