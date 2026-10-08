<?php

namespace App\Http\Controllers\Api\People;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\PersonTransfer;
use App\Models\Territory;
use App\Models\User;
use App\Notifications\PlaceNotification;
use App\Services\Activities\Activities;
use App\Services\People\People;
use App\Support\PeopleAccess;
use App\Support\Phone;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The church's private member register (docs/specs/people-and-care-spec.md,
 * P1). Every named route answers only for the church the user acts for -
 * the region and diocese get /people/totals: counts, never names.
 */
class PeopleController extends Controller
{
    public function __construct(private People $people) {}

    /** GET /people/overview - the Members page's cards and a year of joins and leavings. */
    public function overview(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->people->overview($church) + ['can' => $this->can($request->user(), $church)]);
    }

    /** GET /people?status[]=&gender=&congregation=&area=&baptised=&joined_year=&ministry=&q=&archived= */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $f = $request->validate([
            'status' => ['nullable', 'array'], 'status.*' => [Rule::in(array_keys(Person::STATUSES))],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'congregation' => ['nullable', Rule::in(array_keys(Person::CONGREGATIONS))],
            'area' => ['nullable', 'string', 'max:80'],
            'baptised' => ['nullable', 'in:0,1,true,false'],
            'joined_year' => ['nullable', 'integer', 'between:1900,2100'],
            'ministry' => ['nullable', 'regex:/^(none|\d+)$/'],
            'q' => ['nullable', 'string', 'max:80'],
            'archived' => ['nullable', 'boolean'],
        ]);
        $rows = $this->people->query($church, $f)->with('ministries')->orderBy('first_name')->orderBy('last_name')->limit(5000)->get();

        return $this->ok(['items' => $rows->map(fn ($p) => $this->people->row($p))->values(), 'total' => $rows->count()]);
    }

    /** GET /people/check?phone=&first_name=&last_name=&except= - possible duplicates before saving. */
    public function check(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['phone' => ['nullable', 'string', 'max:30'], 'first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'], 'except' => ['nullable', 'integer']]);

        return $this->ok($this->people->duplicates($church, $d['phone'] ?? null, $d['first_name'] ?? null, $d['last_name'] ?? null, $d['except'] ?? null)->values());
    }

    /** POST /people */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $data = $this->validated($request, $church);
        if ($dup = $this->duplicateError($church, $data, $request->boolean('confirm_duplicate'))) {
            return $dup;
        }
        $person = Person::create($data + ['territory_id' => $church->id, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->ok($this->detail($request, $person), "{$person->name} is in the register.", 201);
    }

    /** GET /people/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($request, $person));
    }

    /** PUT /people/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($person->anonymised_at) {
            return $this->unprocessable('first_name', "This person's details were removed, so they can't be changed.");
        }
        $data = $this->validated($request, $church, $person);
        if ($dup = $this->duplicateError($church, $data, $request->boolean('confirm_duplicate'), $person->id)) {
            return $dup;
        }
        $person->fill($data + ['updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $person->fresh()), 'Saved.');
    }

    /** POST /people/{id}/archive and /restore - hidden from the lists, or back again. */
    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, true);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->setArchived($request, $id, false);
    }

    /** POST /people/{id}/anonymise {confirm: "REMOVE"} - clears every personal detail; the row and its counts stay. Can't be undone. */
    public function anonymise(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($request->input('confirm') !== 'REMOVE') {
            return $this->unprocessable('confirm', 'Type REMOVE to confirm. This cannot be undone.');
        }
        $person->anonymise($request->user()->id);

        return $this->ok($this->detail($request, $person->fresh()), 'Their personal details are removed.');
    }

    /** GET /people/{id}/history - what changed, in plain sentences; private fields only as "changed". */
    public function history(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->people->history($person));
    }

    /** GET /people/transfers?direction=&year= */
    public function transfers(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['direction' => ['nullable', Rule::in(['in', 'out'])], 'year' => ['nullable', 'integer', 'between:1900,2100']]);
        $year = (int) ($d['year'] ?? $this->people->today()->year);
        $rows = PersonTransfer::with(['person', 'otherChurch', 'createdBy'])->where('territory_id', $church->id)
            ->when($d['direction'] ?? null, fn ($q, $dir) => $q->where('direction', $dir))
            ->whereYear('on', $year)->orderByDesc('on')->orderByDesc('id')->get();

        return $this->ok([
            'year' => $year,
            'in' => PersonTransfer::where('territory_id', $church->id)->where('direction', 'in')->whereYear('on', $year)->count(),
            'out' => PersonTransfer::where('territory_id', $church->id)->where('direction', 'out')->whereYear('on', $year)->count(),
            'items' => $rows->map(fn (PersonTransfer $t) => [
                'id' => $t->id, 'direction' => $t->direction, 'on' => $t->on->toDateString(), 'reason' => $t->reason, 'notified' => $t->notified,
                'other' => ['id' => $t->other_church_id, 'name' => $t->other_name, 'in_system' => (bool) $t->other_church_id],
                // The person's details and who recorded it, for the transfer's details window.
                'person' => $t->person ? [
                    'id' => $t->person->id, 'name' => $t->person->name, 'initials' => $t->person->initials, 'status' => $t->person->status,
                    'phone' => $t->person->phone, 'area' => $t->person->area, 'gender' => $t->person->gender, 'congregation' => $t->person->congregation,
                ] : null,
                'recorded_by' => $t->createdBy ? trim("{$t->createdBy->firstname} {$t->createdBy->lastname}") : null,
                'recorded_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'churches' => $this->otherChurches($church),
        ]);
    }

    /** POST /people/{id}/transfer-out {other_church_id?|other_church_name?, on, reason?, notify} */
    public function transferOut(Request $request, int $id, Activities $activities): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $this->transferData($request, $church);
        $notify = $request->boolean('notify') && ! empty($d['other_church_id']);
        $transfer = PersonTransfer::create($d + ['person_id' => $person->id, 'territory_id' => $church->id, 'direction' => 'out', 'notified' => $notify, 'created_by' => $request->user()->id]);
        $person->forceFill(['status' => 'transferred_out', 'updated_by' => $request->user()->id])->save();

        $told = 0;
        if ($notify && ($other = Territory::find($d['other_church_id']))) {
            // The person is moving to them, so the receiving church may know who to expect.
            foreach ($activities->leadersWith([$other->id], PeopleAccess::permission('members', 'manage')) as $user) {
                $user->notify(new PlaceNotification('care', "{$person->name} is joining you", "{$person->name}".($person->phone ? " ({$person->phone})" : '')." is moving to {$other->name} from {$church->name}.", '/church/members/transfers.php', $church, 'ri-user-shared-line'));
                $told++;
            }
        }

        return $this->ok(['transfer_id' => $transfer->id, 'told' => $told, 'person' => $this->detail($request, $person->fresh())], $told ? "Transferred - {$told} leader(s) at the other church were told." : 'Transferred.');
    }

    /** POST /people/transfer-in - a new member, joined by transfer. */
    public function transferIn(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $t = $this->transferData($request, $church);
        $data = $this->validated($request, $church);
        if ($dup = $this->duplicateError($church, $data, $request->boolean('confirm_duplicate'))) {
            return $dup;
        }
        $person = Person::create($data + [
            'territory_id' => $church->id, 'status' => 'member', 'how_joined' => 'transfer', 'joined_on' => $data['joined_on'] ?? $t['on'],
            'previous_church' => $data['previous_church'] ?? ($t['other_church_name'] ?? Territory::find($t['other_church_id'] ?? 0)?->name),
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        PersonTransfer::create($t + ['person_id' => $person->id, 'territory_id' => $church->id, 'direction' => 'in', 'created_by' => $request->user()->id]);

        return $this->ok($this->detail($request, $person->fresh()), "{$person->name} joined by transfer.", 201);
    }

    /** GET /people/insights */
    public function insights(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->people->insights($church));
    }

    /** GET /people/register-counts - the hint beside the Demographics form ("From your register: N"). */
    public function registerCounts(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }

        return $this->ok($this->people->registerCounts($church));
    }

    /**
     * POST /people/bulk {ids[], action: inactive|archive} - many members at
     * once from the list. Only this church's members; each change is audited.
     */
    public function bulk(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'action' => ['required', Rule::in(['inactive', 'archive'])]]);
        $people = Person::where('territory_id', $church->id)->whereIn('id', $d['ids'])->whereNull('anonymised_at')->where('status', '!=', 'visitor')->get();
        $done = 0;
        foreach ($people as $person) {
            if ($d['action'] === 'inactive' && $person->status === 'member') {
                $person->forceFill(['status' => 'inactive', 'updated_by' => $request->user()->id])->save();
                $done++;
            } elseif ($d['action'] === 'archive' && ! $person->archived_at) {
                $person->forceFill(['archived_at' => now(), 'updated_by' => $request->user()->id])->save();
                $done++;
            }
        }
        $verb = $d['action'] === 'inactive' ? 'marked inactive' : 'archived';

        return $this->ok(['done' => $done, 'skipped' => count(array_unique($d['ids'])) - $done], "{$done} ".($done === 1 ? 'member' : 'members')." {$verb}.");
    }

    /**
     * GET /people/search?q= - one person by name, phone or area, to message
     * them: this church's members and visitors (whichever the role may read),
     * never a demo number or someone whose details were removed.
     */
    public function search(Request $request): JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        $members = $place && PeopleAccess::canNamed($request->user(), $place, 'members');
        $visitors = $place && PeopleAccess::canNamed($request->user(), $place, 'visitors');
        if (! $members && ! $visitors) {
            return $this->forbidden("Your role can't see the church's members or visitors.");
        }
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80'], 'limit' => ['nullable', 'integer', 'between:1,50']]);
        // People still with us: members and visitors - not those who left.
        $rows = $this->people->query($place, ['q' => $d['q'], 'status' => array_values(array_filter([$members ? 'member' : null, $visitors ? 'visitor' : null]))])
            ->whereNotNull('phone')
            ->orderBy('first_name')->limit($d['limit'] ?? 25)->get();

        return $this->ok($rows->map(fn (Person $p) => [
            'id' => $p->id, 'name' => $p->name, 'initials' => $p->initials, 'phone' => $p->phone, 'area' => $p->area,
            'kind' => $p->status === 'visitor' ? 'visitor' : 'member', 'demo' => Phone::isDemo($p->phone),
            'can_text' => ! Phone::isDemo($p->phone) && ($p->status !== 'visitor' || $p->consent_contact),
        ])->values());
    }

    /** GET /people/totals - the region's and diocese's view: counts per church, never a name. */
    public function totals(Request $request): JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! PeopleAccess::canTotals($request->user(), $place, 'members')) {
            return $this->forbidden("Your role can't see the churches' member totals.");
        }

        return $this->ok($this->people->totals($place) + ['place' => ['id' => $place->id, 'name' => $place->name, 'type' => PlaceAccess::level($place)]]);
    }

    // ------------------------------------------------------------------ helpers

    /** The person as the profile shows them (decrypted - only ever sent to their own church). */
    private function detail(Request $request, Person $p): array
    {
        $p->loadMissing('transfers.otherChurch');
        $journey = collect([
            $p->first_visit_on ? ['on' => $p->first_visit_on->toDateString(), 'kind' => 'visited', 'label' => 'First visited'] : null,
            $p->saved_on ? ['on' => $p->saved_on->toDateString(), 'kind' => 'saved', 'label' => 'Saved'] : null,
            $p->baptised_on ? ['on' => $p->baptised_on->toDateString(), 'kind' => 'baptised', 'label' => 'Baptised'] : null,
            $p->joined_on ? ['on' => $p->joined_on->toDateString(), 'kind' => 'joined', 'label' => 'Joined '.($p->church?->name ?? 'the church').($p->how_joined ? ' ('.strtolower(Person::HOW_JOINED[$p->how_joined]).')' : '')] : null,
            ...$p->transfers->map(fn ($t) => ['on' => $t->on->toDateString(), 'kind' => "transfer_{$t->direction}", 'label' => $t->direction === 'in' ? "Moved here from {$t->other_name}" : "Moved to {$t->other_name}"])->all(),
        ])->filter()->sortBy('on')->values();

        return [
            'id' => $p->id, 'name' => $p->name, 'initials' => $p->initials,
            'first_name' => $p->first_name, 'last_name' => $p->last_name, 'gender' => $p->gender,
            'phone' => $p->phone, 'area' => $p->area, 'congregation' => $p->congregation,
            'status' => $p->status, 'joined_on' => $p->joined_on?->toDateString(), 'how_joined' => $p->how_joined, 'previous_church' => $p->previous_church,
            'saved_on' => $p->saved_on?->toDateString(), 'baptised_on' => $p->baptised_on?->toDateString(),
            'came_as_visitor' => (bool) $p->stage,
            'archived' => (bool) $p->archived_at, 'anonymised' => (bool) $p->anonymised_at,
            'created_at' => $p->created_at?->toIso8601String(),
            'journey' => $journey, 'ministries' => People::ministryChips($p), 'care' => null,
            'can' => $this->can($request->user(), $p->church ?? Territory::find($p->territory_id)),
        ];
    }

    private function can(?User $user, Territory $church): array
    {
        return [
            'manage' => PeopleAccess::canNamed($user, $church, 'members', 'manage'),
            'export' => PeopleAccess::canNamed($user, $church, 'members', 'export'),
        ];
    }

    /** The field rules: name, phone, area, gender and Sunday school or main church - and the church-life dates, added later. */
    private function validated(Request $request, Territory $church, ?Person $person = null): array
    {
        $today = $this->people->today()->toDateString();
        $data = $request->validate([
            'first_name' => [...($person ? ['sometimes'] : []), 'required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'area' => ['nullable', 'string', 'max:80'],
            'congregation' => ['nullable', Rule::in(array_keys(Person::CONGREGATIONS))],
            'status' => ['nullable', Rule::in(array_keys(Person::STATUSES))],
            'joined_on' => ['nullable', 'date', "before_or_equal:{$today}"],
            'how_joined' => ['nullable', Rule::in(array_keys(Person::HOW_JOINED))],
            'previous_church' => ['nullable', 'string', 'max:160'],
            'saved_on' => ['nullable', 'date', "before_or_equal:{$today}"],
            'baptised_on' => ['nullable', 'date', "before_or_equal:{$today}"],
        ], ['first_name.required' => 'Enter their name.']);
        if (! empty($data['phone'])) {
            $normal = Phone::kenyaMobile($data['phone']);
            if (! $normal) {
                throw ValidationException::withMessages(['phone' => 'That phone number doesn\'t look right - use a Kenyan mobile, e.g. 0712 345 678.']);
            }
            $data['phone'] = $normal;
        }
        if (isset($data['first_name'])) {
            $data['first_name'] = trim($data['first_name']);
        }
        if (array_key_exists('last_name', $data)) {
            $data['last_name'] = trim((string) $data['last_name']);
        }
        if (array_key_exists('area', $data)) {
            $data['area'] = ($a = trim((string) $data['area'])) === '' ? null : mb_convert_case($a, MB_CASE_TITLE);
        }
        if (! $person) {
            $data['status'] ??= 'member';
            $data['last_name'] ??= '';
            $data['consent_contact'] = ! empty($data['phone']);
        } else {
            $data = array_filter($data, fn ($v, $k) => $request->exists($k), ARRAY_FILTER_USE_BOTH);
        }

        return $data;
    }

    private function duplicateError(Territory $church, array $data, bool $confirmed, ?int $except = null): ?JsonResponse
    {
        if ($confirmed || empty($data['phone'])) {
            return null;
        }
        $same = $this->people->duplicates($church, $data['phone'], null, null, $except)->first();

        return $same ? response()->json([
            'success' => false, 'status' => 422,
            'message' => "{$same['name']} already has this phone number.",
            'errors' => ['phone' => ["{$same['name']} already has this phone number."]],
            'duplicate' => $same,
        ], 422) : null;
    }

    private function transferData(Request $request, Territory $church): array
    {
        $d = $request->validate([
            'other_church_id' => ['nullable', 'integer', 'exists:territories,id', 'required_without:other_church_name'],
            'other_church_name' => ['nullable', 'string', 'max:160', 'required_without:other_church_id'],
            'on' => ['required', 'date', 'before_or_equal:'.$this->people->today()->toDateString()],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], ['other_church_id.required_without' => 'Pick the church, or type its name.', 'other_church_name.required_without' => 'Pick the church, or type its name.']);
        if (! empty($d['other_church_id'])) {
            $other = Territory::find($d['other_church_id']);
            if (! $other || PlaceAccess::level($other) !== 'church' || (int) $other->id === (int) $church->id) {
                throw ValidationException::withMessages(['other_church_id' => 'Pick another church.']);
            }
            $d['other_church_name'] = null;
        }

        return $d;
    }

    /** The diocese's other churches, to pick from. */
    private function otherChurches(Territory $church): array
    {
        $diocese = collect(PlaceAccess::ancestors($church))->first(fn ($t) => PlaceAccess::level($t) === 'diocese');
        $ids = $diocese ? PlaceAccess::descendantIds($diocese) : [];

        return Territory::whereIn('id', $ids ?: [0])->where('territory_type', 'church')->where('id', '!=', $church->id)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all();
    }

    private function setArchived(Request $request, int $id, bool $archive): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $person->forceFill(['archived_at' => $archive ? now() : null, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $person->fresh()), $archive ? 'Archived - hidden from the lists.' : 'Back in the register.');
    }

    /** The church the user acts for, when their role may read (or manage) the register there. */
    private function church(Request $request, string $ability = 'read'): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return $this->forbidden('The member register is kept by each church - only its own leaders see it.');
        }
        if (! PeopleAccess::canNamed($request->user(), $place, 'members', $ability)) {
            return $this->forbidden($ability === 'manage' ? "Your role can't change the member register." : "Your role can't see the member register.");
        }

        return $place;
    }

    /** [church, person, error] - the person must be in the user's own church. */
    private function person(Request $request, int $id, string $ability = 'read'): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $person = Person::with('church')->where('territory_id', $church->id)->find($id);

        return $person ? [$church, $person, null] : [$church, null, response()->json(['success' => false, 'status' => 404, 'message' => 'That person isn\'t in your register.'], 404)];
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
