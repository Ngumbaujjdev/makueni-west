<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\Equipment;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceJob;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Models\Audit;

/** What the church owns, where it is kept, who has borrowed it, and its repairs (P5). */
class EquipmentController extends FacilitiesBase
{
    /** GET /equipment */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $items = Equipment::with('room')->where('territory_id', $church->id)->orderBy('name')->get();
        $out = $this->facilities->onLoan($items->pluck('id')->all());
        $open = MaintenanceJob::whereIn('equipment_id', $items->pluck('id')->all() ?: [0])->where('status', '!=', 'done')->selectRaw('equipment_id, count(*) n')->groupBy('equipment_id')->pluck('n', 'equipment_id');

        return $this->ok(['items' => $items->map(fn ($e) => $this->facilities->equipmentRow($e, (int) ($out[$e->id] ?? 0), (int) ($open[$e->id] ?? 0)))->values(), 'can' => $this->can($request, $church)]);
    }

    /** GET /equipment/{id} - with its loans and repairs. */
    public function show(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id);
        if ($error) {
            return $error;
        }

        return $this->ok($this->detail($request, $church, $e));
    }

    /** POST /equipment */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $e = Equipment::create($this->validated($request, $church) + ['territory_id' => $church->id]);

        return $this->ok($this->detail($request, $church, $e), "{$e->name} added.", 201);
    }

    /** PUT /equipment/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $e->fill($this->validated($request, $church, $e))->save();

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Saved.');
    }

    /** DELETE /equipment/{id} - gone (sold, thrown away); its history stays. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        if (EquipmentLoan::where('equipment_id', $e->id)->whereNull('returned_on')->exists()) {
            return $this->unprocessable('id', "{$e->name} is lent out - mark it back first.");
        }
        $e->delete();

        return $this->ok(['id' => $e->id], "{$e->name} removed.");
    }

    /** POST /equipment/bulk {ids[], action: room|condition, value} */
    public function bulk(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['room', 'condition'])],
            'value' => ['nullable', $request->input('action') === 'condition' ? Rule::in(array_keys(Equipment::CONDITIONS)) : Rule::exists('rooms', 'id')->where('territory_id', $church->id)->whereNull('deleted_at')],
        ]);
        $done = 0;
        foreach (Equipment::where('territory_id', $church->id)->whereIn('id', $d['ids'])->get() as $e) {
            $e->update([$d['action'] === 'room' ? 'room_id' : 'condition' => $d['value'] ?? null]);
            $done++;
        }

        return $this->ok(['done' => $done], "{$done} ".($done === 1 ? 'item' : 'items').' changed.');
    }

    /** POST /equipment/{id}/loans {to_person_id|to_name, quantity, due_on, note} */
    public function lend(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $today = $this->facilities->today()->toDateString();
        $d = $request->validate([
            'to_person_id' => ['nullable', 'integer', Rule::exists('people', 'id')->where('territory_id', $church->id)->whereNull('anonymised_at')],
            'to_name' => ['nullable', 'required_without:to_person_id', 'string', 'max:120'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'due_on' => ['nullable', 'date', "after_or_equal:{$today}"],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['to_name.required_without' => 'Pick who has it, or type their name.']);
        $available = (int) $e->quantity - (int) ($this->facilities->onLoan([$e->id])[$e->id] ?? 0);
        $qty = (int) ($d['quantity'] ?? 1);
        if ($qty > $available) {
            return $this->unprocessable('quantity', $available ? "Only {$available} are here to lend." : 'All of them are out on loan.');
        }
        $person = ! empty($d['to_person_id']) ? Person::find($d['to_person_id']) : null;
        EquipmentLoan::create([
            'equipment_id' => $e->id, 'to_person_id' => $person?->id, 'to_name' => $person?->name ?? $d['to_name'], 'quantity' => $qty,
            'out_on' => $today, 'due_on' => $d['due_on'] ?? $this->facilities->today()->addDays($this->facilities->loanDays($church))->toDateString(),
            'note' => $d['note'] ?? null, 'by' => $request->user()->id,
        ]);

        $to = $person?->name ?? $d['to_name'];

        return $this->ok($this->detail($request, $church, $e->fresh()), "Lent to {$to}.", 201);
    }

    /** POST /loans/{id}/return */
    public function giveBack(Request $request, int $id): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $loan = EquipmentLoan::whereHas('equipment', fn ($q) => $q->where('territory_id', $church->id))->whereNull('returned_on')->find($id);
        if (! $loan) {
            return $this->notFound("That loan isn't open in your church.");
        }
        $loan->update(['returned_on' => $this->facilities->today()->toDateString()]);

        return $this->ok($this->detail($request, $church, $loan->equipment), 'Marked back.');
    }

    /** GET /loans - everything out now, for the Equipment page. */
    public function loans(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $loans = EquipmentLoan::with(['equipment', 'person'])->whereHas('equipment', fn ($q) => $q->where('territory_id', $church->id))->whereNull('returned_on')->orderBy('due_on')->get();

        return $this->ok($loans->map(fn ($l) => $this->facilities->loanRow($l))->values());
    }

    // ------------------------------------------------------------------ helpers

    private function detail(Request $request, Territory $church, Equipment $e): array
    {
        $e->loadMissing(['room', 'loans.person', 'repairs.reporter', 'repairs.assignee']);
        $out = (int) $e->loans->whereNull('returned_on')->sum('quantity');
        $loanIds = $e->loans->pluck('id')->all();
        $jobIds = $e->repairs->pluck('id')->all();
        $audits = Audit::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', 'equipment')->where('auditable_id', $e->id))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'equipment_loan')->whereIn('auditable_id', $loanIds ?: [0]))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'maintenance_job')->whereIn('auditable_id', $jobIds ?: [0])))->latest('id')->limit(100)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');

        return $this->facilities->equipmentRow($e, $out, $e->repairs->where('status', '!=', 'done')->count()) + [
            'loans' => $e->loans->map(fn ($l) => $this->facilities->loanRow($l))->values(),
            'repairs' => $e->repairs->map(fn ($j) => $this->facilities->repairRow($j))->values(),
            'history' => $audits->map(function (Audit $a) use ($users) {
                $who = ($u = $users->get($a->user_id)) ? trim("{$u->firstname} {$u->lastname}") : 'The system';
                $v = (array) ($a->new_values ?: $a->old_values);
                $sentence = match (true) {
                    $a->auditable_type === 'equipment_loan' && $a->event === 'created' => "{$who} lent it to ".($v['to_name'] ?? 'someone'),
                    $a->auditable_type === 'equipment_loan' => "{$who} marked it back",
                    $a->auditable_type === 'maintenance_job' && $a->event === 'created' => "{$who} reported a repair: ".($v['title'] ?? ''),
                    $a->auditable_type === 'maintenance_job' => "{$who} moved its repair to ".strtolower(\App\Models\MaintenanceJob::STATUSES[$v['status'] ?? '']['0'] ?? 'a new status'),
                    $a->event === 'created' => "{$who} added it",
                    array_key_exists('condition', $v) => "{$who} marked it ".strtolower(Equipment::CONDITIONS[$v['condition']][0] ?? $v['condition']),
                    default => "{$who} changed ".collect(array_keys($v))->map(fn ($k) => ['room_id' => 'where it is kept', 'bought_on' => 'when it was bought'][$k] ?? str_replace('_', ' ', $k))->implode(', '),
                };

                return ['id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'who' => $who, 'sentence' => $sentence];
            })->values(),
            'can' => $this->can($request, $church),
        ];
    }

    private function validated(Request $request, Territory $church, ?Equipment $e = null): array
    {
        $s = $e ? 'sometimes' : 'required';
        $d = $request->validate([
            'name' => [$s, 'string', 'max:120'],
            'category' => [$s, Rule::in(array_keys(Equipment::CATEGORIES))],
            'room_id' => ['sometimes', 'nullable', 'integer', Rule::exists('rooms', 'id')->where('territory_id', $church->id)->whereNull('deleted_at')],
            'quantity' => ['sometimes', 'integer', 'between:1,100000'],
            'condition' => ['sometimes', Rule::in(array_keys(Equipment::CONDITIONS))],
            'bought_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], ['room_id.exists' => 'Pick one of our rooms.']);

        return $e ? array_filter($d, fn ($v, $k) => $request->exists($k), ARRAY_FILTER_USE_BOTH) : $d;
    }

    /** [church, equipment, error] */
    private function item(Request $request, int $id, string $ability = 'read'): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $e = Equipment::where('territory_id', $church->id)->find($id);

        return $e ? [$church, $e, null] : [$church, null, $this->notFound("That item isn't in your church.")];
    }
}
