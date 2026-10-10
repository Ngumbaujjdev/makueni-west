<?php

namespace App\Http\Controllers\Api\HR;

use App\Models\Employee;
use App\Models\Person;
use App\Models\Territory;
use App\Services\HR\Lists;
use App\Services\HR\Staff;
use App\Support\HrAccess;
use App\Support\PayTo;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff (docs/specs/hr-spec.md): the people a place employs - who they are,
 * their job, grade and pay, where they have been posted, their papers. The
 * place's own for whoever holds an HR permission there; the places below for
 * whoever reads below. ID numbers and KRA PINs leave the API masked, always.
 */
class StaffController extends HrBase
{
    public function __construct(private Staff $staff, private Lists $lists) {}

    /** GET /hr/overview - totals here, and per place below for a region or the diocese. */
    public function overview(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $can = HrAccess::abilities($request->user(), $place);
        $sum = function ($people) {
            $active = $people->filter(fn ($e) => ! $e->end_date || $e->end_date->gte(today()));

            return ['staff' => $active->count(), 'monthly_pay' => round($active->sum(fn ($e) => (float) $e->basic_pay + array_sum(array_column($e->allowances ?? [], 'amount'))), 2),
                'by_type' => collect(Employee::TYPES)->map(fn ($label, $k) => $active->where('employment_type', $k)->count())->all(),
                'contracts_ending' => $active->filter(fn ($e) => $e->contract_end && $e->contract_end->between(today(), today()->addDays(60)))->count()];
        };
        $below = [];
        if ($can['below']) {
            $ids = PlaceAccess::descendantIds($place);
            $all = Employee::whereIn('territory_id', $ids)->get()->groupBy('territory_id');
            foreach (Territory::whereIn('id', $all->keys())->orderBy('name')->get() as $t) {
                $below[] = ['place' => $this->placeInfo($t)] + $sum($all[$t->id]);
            }
        }

        return $this->ok(['place' => $this->placeInfo($place), 'can' => $can, 'here' => $sum(Employee::where('territory_id', $place->id)->get()), 'below' => $below]);
    }

    /** GET /hr/staff?q&position_id&type&status&below&place_id&page&per */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $can = HrAccess::abilities($request->user(), $place);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'], 'position_id' => ['nullable', 'integer'], 'type' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:active,left,all'], 'below' => ['nullable', 'boolean'], 'place_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'], 'per' => ['nullable', 'integer', 'between:5,200'],
        ]);
        $ids = [(int) $place->id];
        if ($request->boolean('below') && $can['below']) {
            $ids = [...$ids, ...PlaceAccess::descendantIds($place)];
            if (! empty($f['place_id'])) {
                $ids = in_array((int) $f['place_id'], $ids, true) ? [(int) $f['place_id']] : [];
            }
        }
        $q = $this->staff->query($ids, $f)->with(['person', 'user', 'grade'])->orderBy('name');
        $per = (int) ($f['per'] ?? 25);
        $page = $q->paginate($per, ['*'], 'page', (int) ($f['page'] ?? 1));

        return $this->ok([
            'place' => $this->placeInfo($place), 'can' => $can,
            'rows' => collect($page->items())->map(fn ($e) => $this->staff->present($e))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'per' => $per,
            'counts' => [
                'active' => $this->staff->query($ids, ['status' => 'active'])->count(),
                'left' => $this->staff->query($ids, ['status' => 'left'])->count(),
            ],
        ]);
    }

    /** GET /hr/staff/{id} - one person with their postings and papers. */
    public function show(Request $request, int $id): JsonResponse
    {
        [$e, $place, $deny] = $this->person($request, $id);
        if ($deny) {
            return $deny;
        }
        $can = HrAccess::abilities($request->user(), $place);

        return $this->ok($this->staff->present($e, true) + ['can' => [
            'edit' => $can['manage'], 'end' => $can['manage'] && ! $e->end_date, 'delete' => $can['manage'],
            'transfer' => HrAccess::canTransfer($request->user(), $place, $place) && PlaceAccess::acting($request->user())?->territory_type?->value !== 'church',
        ]]);
    }

    /** POST /hr/staff · PUT /hr/staff/{id} */
    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $e = null;
        if ($id) {
            [$e, $place, $deny] = $this->person($request, $id, 'manage');
            if ($deny) {
                return $deny;
            }
        } else {
            $place = $this->place($request, 'manage');
            if ($place instanceof JsonResponse) {
                return $place;
            }
        }
        $data = $request->validate($this->rules(), ['name.required_without_all' => 'Who is it? Pick a member or a login, or type a name.']);
        $e = $this->staff->save($place, $request->user(), $data, $e);

        return $this->ok($this->staff->present($e, true), $id ? 'Saved.' : "{$e->name} added to the staff.", $id ? 200 : 201);
    }

    /** POST /hr/staff/{id}/transfer {to_territory_id, date, position_id?, note?} - from the level above. */
    public function transfer(Request $request, int $id): JsonResponse
    {
        [$e, $place, $deny] = $this->person($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['to_territory_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'position_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:255']],
            ['to_territory_id.required' => 'Where are they going?', 'date.required' => 'From when?']);
        $to = Territory::find((int) $data['to_territory_id']);
        if (! $to || ! in_array($to->territory_type->value, HrAccess::LEVELS, true)) {
            throw ValidationException::withMessages(['to_territory_id' => ['Pick a church, region or the diocese.']]);
        }
        if (! HrAccess::canTransfer($request->user(), $place, $to)) {
            return $this->forbidden('A move between places is made by the level above both - the region between its churches, the diocese anywhere.');
        }
        $e = $this->staff->transfer($e, $request->user(), $to, $data['date'], $data['position_id'] ?? null, $data['note'] ?? null);

        return $this->ok($this->staff->present($e, true), "{$e->name} moves to {$to->name} from ".date('j M Y', strtotime($data['date'])).'.');
    }

    /** POST /hr/staff/{id}/end {date, reason?} */
    public function end(Request $request, int $id): JsonResponse
    {
        [$e, , $deny] = $this->person($request, $id, 'manage');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:255']], ['date.required' => 'When do they leave?']);
        $e = $this->staff->end($e, $request->user(), $data['date'], $data['reason'] ?? null);

        return $this->ok($this->staff->present($e, true), "{$e->name} leaves on ".date('j M Y', strtotime($data['date'])).' - paid for that month, not after.');
    }

    /** DELETE /hr/staff/{id} - only someone never paid. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$e, , $deny] = $this->person($request, $id, 'manage');
        if ($deny) {
            return $deny;
        }
        $this->staff->delete($e);

        return $this->ok(null, 'Removed.');
    }

    // ------------------------------------------------------------ papers

    /** POST /hr/staff/{id}/documents {file} */
    public function addDocument(Request $request, int $id): JsonResponse
    {
        [$e, , $deny] = $this->person($request, $id, 'manage');
        if ($deny) {
            return $deny;
        }
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'], 'name' => ['nullable', 'string', 'max:100']],
            ['file.max' => 'The file must be 5 MB or smaller.', 'file.mimes' => 'Attach a photo (JPG, PNG, WebP) or a PDF.']);
        if ($e->getMedia('documents')->count() >= Employee::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['file' => ['A person can hold '.Employee::MAX_DOCUMENTS.' papers - remove one first.']]);
        }
        $file = $request->file('file');
        $e->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
            ->usingName(trim((string) $request->input('name')) ?: (pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Document'))
            ->withCustomProperties(['added_by' => $request->user()->id])->toMediaCollection('documents');

        return $this->ok($this->staff->present($e->fresh(), true), 'Attached.', 201);
    }

    /** GET /hr/staff/{id}/documents/{media} */
    public function showDocument(Request $request, int $id, int $media): Response|JsonResponse
    {
        [$e, , $deny] = $this->person($request, $id);
        if ($deny) {
            return $deny;
        }
        $file = $e->getMedia('documents')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That paper isn\'t on their record.');
        }

        return response()->file($file->getPath(), ['Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($file->file_name).'"']);
    }

    /** DELETE /hr/staff/{id}/documents/{media} */
    public function removeDocument(Request $request, int $id, int $media): JsonResponse
    {
        [$e, , $deny] = $this->person($request, $id, 'manage');
        if ($deny) {
            return $deny;
        }
        $file = $e->getMedia('documents')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That paper isn\'t on their record.');
        }
        $file->delete();

        return $this->ok($this->staff->present($e->fresh(), true), 'Removed.');
    }

    // ------------------------------------------------------------ pickers

    /** GET /hr/options - what the windows offer here. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $pick = fn ($rows, $extra) => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name] + $extra($r))->values();
        $user = $request->user();
        $acting = PlaceAccess::acting($user);

        return $this->ok([
            'place' => $this->placeInfo($place),
            'positions' => $pick($this->lists->usable($place, 'position'), fn ($r) => ['grade_id' => $r->grade_id]),
            'grades' => $pick($this->lists->usable($place, 'grade'), fn ($r) => ['code' => $r->code, 'min_pay' => $r->min_pay !== null ? (float) $r->min_pay : null,
                'max_pay' => $r->max_pay !== null ? (float) $r->max_pay : null, 'default_pay' => $r->default_pay !== null ? (float) $r->default_pay : null]),
            'allowances' => $pick($this->lists->usable($place, 'allowance'), fn ($r) => ['default_amount' => $r->default_amount !== null ? (float) $r->default_amount : null]),
            'types' => collect(Employee::TYPES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values(),
            'pay_methods' => collect(PayTo::METHODS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values(),
            'churches' => Territory::whereIn('id', $this->staff->churchIds($place))->orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'transfer_to' => $acting && $acting->territory_type->value !== 'church' && HrAccess::can($user, $acting, 'manage')
                ? $this->staff->transferPlaces($user)->map(fn ($t) => $this->placeInfo($t))->values() : [],
        ]);
    }

    /** GET /hr/people?q&church_id - members of a church here or below, to make someone staff. */
    public function people(Request $request): JsonResponse
    {
        $place = $this->place($request, 'manage');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80'], 'church_id' => ['nullable', 'integer']]);
        $churches = $this->staff->churchIds($place);
        $church = (int) ($d['church_id'] ?? ($place->territory_type->value === 'church' ? $place->id : 0));
        if (! in_array($church, $churches, true)) {
            throw ValidationException::withMessages(['church_id' => ['Pick the church they are a member of.']]);
        }
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($d['q'])).'%';
        $rows = Person::where('territory_id', $church)->whereNull('anonymised_at')->whereNull('archived_at')->where('status', 'member')
            ->where(fn ($w) => $w->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term])->orWhere('phone', 'like', $term))
            ->orderBy('first_name')->limit(20)->get();
        $taken = Employee::whereIn('person_id', $rows->pluck('id'))->pluck('name', 'person_id');

        return $this->ok($rows->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'initials' => $p->initials, 'phone' => $p->phone, 'area' => $p->area, 'staff' => $taken->has($p->id)])->values());
    }

    /** GET /hr/logins?q - people with a role here or below. */
    public function logins(Request $request): JsonResponse
    {
        $place = $this->place($request, 'manage');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($d['q'])).'%';
        $users = $this->staff->loginsQuery($place)
            ->where(fn ($w) => $w->where('firstname', 'like', $term)->orWhere('lastname', 'like', $term)->orWhereRaw("CONCAT(firstname, ' ', lastname) like ?", [$term])->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term))
            ->orderBy('firstname')->limit(20)->get();

        return $this->ok($users->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name, 'phone' => $u->phone, 'email' => $u->email,
            'role' => $u->territoryAssignments()->effective()->with('role', 'territory')->first()?->role?->name])->values());
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: ?Employee, 1: ?Territory, 2: ?JsonResponse} */
    private function person(Request $request, int $id, ?string $ability = null): array
    {
        $e = Employee::find($id);
        $place = $e ? Territory::find($e->territory_id) : null;
        if (! $e || ! $place || ! HrAccess::canRead($request->user(), $place)) {
            return [null, null, $this->notFound('That person isn\'t on your staff.')];
        }
        if ($ability && ! HrAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden('Your role can\'t change staff here.')];
        }

        return [$e, $place, null];
    }

    private function rules(): array
    {
        return [
            'person_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'name' => ['required_without_all:person_id,user_id', 'nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'position_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'string', 'max:100'],
            'grade_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'in:'.implode(',', array_keys(Employee::TYPES))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date'],
            'pay_method' => ['nullable', 'in:'.implode(',', array_keys(PayTo::METHODS))],
            'pay_to' => ['nullable', 'string', 'max:150'],
            ...PayTo::rules(),
            'basic_pay' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'allowances' => ['nullable', 'array', 'max:10'],
            'allowances.*.type_id' => ['nullable', 'integer'],
            'allowances.*.name' => ['nullable', 'string', 'max:60'],
            'allowances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'id_number' => ['nullable', 'string', 'max:20'],
            'kra_pin' => ['nullable', 'string', 'max:20'],
        ];
    }
}
