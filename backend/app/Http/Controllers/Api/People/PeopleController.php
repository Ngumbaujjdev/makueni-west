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
use App\Services\Settings\Settings;
use App\Support\PeopleAccess;
use App\Support\Phone;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpFoundation\Response;

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

    /** GET /people?status[]=&gender=&age_band=&baptised=&joined_year=&q=&archived= */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $f = $request->validate([
            'status' => ['nullable', 'array'], 'status.*' => [Rule::in(array_keys(Person::STATUSES))],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'age_band' => ['nullable', Rule::in(array_keys(Person::AGE_BANDS))],
            'baptised' => ['nullable', 'in:0,1,true,false'],
            'joined_year' => ['nullable', 'integer', 'between:1900,2100'],
            'q' => ['nullable', 'string', 'max:80'],
            'archived' => ['nullable', 'boolean'],
        ]);
        $rows = $this->people->query($church, $f)->orderBy('first_name')->orderBy('last_name')->limit(5000)->get();

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

    /** POST /people/{id}/photo - png, jpg or webp up to 2 MB, kept private and re-encoded as webp. */
    public function uploadPhoto(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']], ['photo.max' => 'The photo must be 2 MB or smaller.']);
        $image = @imagecreatefromstring((string) file_get_contents($request->file('photo')->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['photo' => "That file couldn't be read as a picture."]);
        }
        $image = $this->square($image, 400);
        ob_start();
        imagewebp($image, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = "people/{$church->id}/{$person->id}.webp";
        Storage::disk('local')->put($path, $bytes);
        $person->forceFill(['photo_path' => $path, 'updated_by' => $request->user()->id])->save();

        return $this->ok($this->detail($request, $person->fresh()), 'Photo saved.');
    }

    /** GET /people/{id}/photo - only the church's own leaders. */
    public function photo(Request $request, int $id): JsonResponse|Response
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }
        if (! $person->photo_path || ! Storage::disk('local')->exists($person->photo_path)) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'No photo.'], 404);
        }

        return response(Storage::disk('local')->get($person->photo_path), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=300']);
    }

    /** DELETE /people/{id}/photo */
    public function removePhoto(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if ($person->photo_path) {
            Storage::disk('local')->delete($person->photo_path);
            $person->forceFill(['photo_path' => null, 'updated_by' => $request->user()->id])->save();
        }

        return $this->ok($this->detail($request, $person->fresh()), 'Photo removed.');
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
        if ($person->photo_path) {
            Storage::disk('local')->delete($person->photo_path);
        }
        $person->forceFill([
            'first_name' => 'Removed', 'last_name' => 'person', 'other_names' => null, 'date_of_birth' => null, 'phone' => null, 'email' => null,
            'address' => null, 'national_id' => null, 'occupation' => null, 'photo_path' => null, 'previous_church' => null,
            'next_of_kin_name' => null, 'next_of_kin_phone' => null, 'notes' => null, 'anonymised_at' => now(), 'updated_by' => $request->user()->id,
        ])->save();

        return $this->ok($this->detail($request, $person->fresh()), 'Their personal details are removed.');
    }

    /** GET /people/{id}/history - what changed, in plain sentences; private fields only as "changed". */
    public function history(Request $request, int $id): JsonResponse
    {
        [$church, $person, $error] = $this->person($request, $id);
        if ($error) {
            return $error;
        }
        $transferIds = PersonTransfer::where('person_id', $person->id)->pluck('id')->all();
        $audits = Audit::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auditable_type', 'person')->where('auditable_id', $person->id))
                ->orWhere(fn ($q) => $q->where('auditable_type', 'person_transfer')->whereIn('auditable_id', $transferIds ?: [0])))
            ->latest('id')->limit(200)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $labels = [
            'first_name' => 'first name', 'last_name' => 'last name', 'other_names' => 'other names', 'date_of_birth' => 'date of birth',
            'national_id' => 'national ID', 'marital_status' => 'marital status', 'joined_on' => 'date joined', 'how_joined' => 'how they joined',
            'previous_church' => 'previous church', 'saved_on' => 'salvation date', 'baptised_on' => 'baptism date',
            'next_of_kin_name' => "next of kin's name", 'next_of_kin_phone' => "next of kin's phone",
        ];

        return $this->ok($audits->map(function (Audit $a) use ($users, $labels) {
            $who = ($u = $users->get($a->user_id)) ? trim("{$u->firstname} {$u->lastname}") : 'The system';
            $new = (array) $a->new_values;
            if ($a->auditable_type === 'person_transfer') {
                $sentence = ($new['direction'] ?? '') === 'in' ? "{$who} recorded a transfer in" : "{$who} recorded a transfer out";
            } else {
                $sentence = match (true) {
                    $a->event === 'created' => "{$who} added them to the register",
                    array_key_exists('anonymised_at', $new) && $new['anonymised_at'] => "{$who} removed their personal details",
                    array_key_exists('archived_at', $new) => $new['archived_at'] ? "{$who} archived them" : "{$who} brought them back from the archive",
                    isset($new['status']) && count($new) <= 2 => "{$who} marked them ".strtolower(Person::STATUSES[$new['status']] ?? $new['status']),
                    default => "{$who} changed ".collect(array_keys($new))->reject(fn ($k) => in_array($k, ['updated_by', 'anonymised_at', 'archived_at'], true))
                        ->map(fn ($k) => $labels[$k] ?? str_replace('_', ' ', $k))->implode(', '),
                };
            }

            return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
        })->values());
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
        $rows = PersonTransfer::with(['person', 'otherChurch'])->where('territory_id', $church->id)
            ->when($d['direction'] ?? null, fn ($q, $dir) => $q->where('direction', $dir))
            ->whereYear('on', $year)->orderByDesc('on')->orderByDesc('id')->get();

        return $this->ok([
            'year' => $year,
            'in' => PersonTransfer::where('territory_id', $church->id)->where('direction', 'in')->whereYear('on', $year)->count(),
            'out' => PersonTransfer::where('territory_id', $church->id)->where('direction', 'out')->whereYear('on', $year)->count(),
            'items' => $rows->map(fn (PersonTransfer $t) => [
                'id' => $t->id, 'direction' => $t->direction, 'on' => $t->on->toDateString(), 'reason' => $t->reason, 'notified' => $t->notified,
                'other' => ['id' => $t->other_church_id, 'name' => $t->other_name, 'in_system' => (bool) $t->other_church_id],
                'person' => $t->person ? ['id' => $t->person->id, 'name' => $t->person->name, 'initials' => $t->person->initials, 'status' => $t->person->status] : null,
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

        return $this->ok($this->people->insights($church) + [
            'birthday_template' => app(Settings::class)->get('members.birthday_template', $church),
            'church_name' => $church->name,
        ]);
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
        $today = $this->people->today();
        $age = $p->ageOn($today);
        $p->loadMissing('transfers.otherChurch');
        $journey = collect([
            $p->saved_on ? ['on' => $p->saved_on->toDateString(), 'kind' => 'saved', 'label' => 'Saved'] : null,
            $p->baptised_on ? ['on' => $p->baptised_on->toDateString(), 'kind' => 'baptised', 'label' => 'Baptised'] : null,
            $p->joined_on ? ['on' => $p->joined_on->toDateString(), 'kind' => 'joined', 'label' => 'Joined '.($p->church?->name ?? 'the church').($p->how_joined ? ' ('.strtolower(Person::HOW_JOINED[$p->how_joined]).')' : '')] : null,
            ...$p->transfers->map(fn ($t) => ['on' => $t->on->toDateString(), 'kind' => "transfer_{$t->direction}", 'label' => $t->direction === 'in' ? "Moved here from {$t->other_name}" : "Moved to {$t->other_name}"])->all(),
        ])->filter()->sortBy('on')->values();

        return [
            'id' => $p->id, 'name' => $p->name, 'initials' => $p->initials,
            'first_name' => $p->first_name, 'last_name' => $p->last_name, 'other_names' => $p->other_names,
            'gender' => $p->gender, 'date_of_birth' => $p->date_of_birth?->toDateString(), 'age' => $age, 'age_band' => Person::bandFor($age),
            'phone' => $p->phone, 'email' => $p->email, 'address' => $p->address, 'national_id' => $p->national_id,
            'marital_status' => $p->marital_status, 'occupation' => $p->occupation, 'has_photo' => (bool) $p->photo_path,
            'status' => $p->status, 'joined_on' => $p->joined_on?->toDateString(), 'how_joined' => $p->how_joined, 'previous_church' => $p->previous_church,
            'saved_on' => $p->saved_on?->toDateString(), 'baptised_on' => $p->baptised_on?->toDateString(),
            'next_of_kin_name' => $p->next_of_kin_name, 'next_of_kin_phone' => $p->next_of_kin_phone, 'notes' => $p->notes,
            'archived' => (bool) $p->archived_at, 'anonymised' => (bool) $p->anonymised_at,
            'created_at' => $p->created_at?->toIso8601String(),
            'journey' => $journey, 'ministries' => [], 'care' => null,
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

    /** The field rules; date of birth and national ID become required when the church (or diocese, locked) says so. */
    private function validated(Request $request, Territory $church, ?Person $person = null): array
    {
        $settings = app(Settings::class);
        $dobRequired = (bool) $settings->get('members.require_dob', $church);
        $idRequired = (bool) $settings->get('members.require_national_id', $church);
        $today = $this->people->today()->toDateString();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => [$dobRequired ? 'required' : 'nullable', 'date', "before_or_equal:{$today}", 'after:1900-01-01'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'national_id' => [$idRequired ? 'required' : 'nullable', 'string', 'max:30'],
            'marital_status' => ['nullable', Rule::in(array_keys(Person::MARITAL))],
            'occupation' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_keys(Person::STATUSES))],
            'joined_on' => ['nullable', 'date', "before_or_equal:{$today}"],
            'how_joined' => ['nullable', Rule::in(array_keys(Person::HOW_JOINED))],
            'previous_church' => ['nullable', 'string', 'max:160'],
            'saved_on' => ['nullable', 'date', "before_or_equal:{$today}"],
            'baptised_on' => ['nullable', 'date', "before_or_equal:{$today}"],
            'next_of_kin_name' => ['nullable', 'string', 'max:120'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'date_of_birth.before_or_equal' => "The date of birth can't be in the future.",
            'date_of_birth.required' => 'Your church asks for a date of birth.',
            'national_id.required' => 'Your church asks for a national ID.',
        ]);
        foreach (['phone', 'next_of_kin_phone'] as $field) {
            if (! empty($data[$field])) {
                $normal = Phone::kenyaMobile($data[$field]);
                if (! $normal) {
                    throw ValidationException::withMessages([$field => 'That phone number doesn\'t look right - use a Kenyan mobile, e.g. 0712 345 678.']);
                }
                $data[$field] = $normal;
            }
        }
        $data['first_name'] = trim($data['first_name']);
        $data['last_name'] = trim($data['last_name']);
        if (! $person) {
            $data['status'] ??= 'member';
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

    /** Centre-crop to a square and scale down. */
    private function square(\GdImage $image, int $size): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $image, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        imagedestroy($image);

        return $out;
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
