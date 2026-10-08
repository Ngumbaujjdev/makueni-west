<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Models\MaintenanceJob;
use App\Services\Budgets\BudgetBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Repairs (P5): anyone who sees the facilities can report one; those who
 * manage them move it along - reported, in progress, done - and record what
 * it cost in Budgets (no money is written here; the entry is linked after).
 */
class RepairsController extends FacilitiesBase
{
    /** GET /repairs */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $jobs = MaintenanceJob::with(['equipment', 'room', 'reporter', 'assignee'])->where('territory_id', $church->id)
            ->where(fn ($w) => $w->where('status', '!=', 'done')->orWhere('done_on', '>=', $this->facilities->today()->subMonths(3)->toDateString()))
            ->orderByRaw("priority = 'urgent' desc")->orderByDesc('id')->get();
        $budget = app(BudgetBook::class)->budgetInUseOn('church', $church->id, $this->facilities->today()->toDateString());

        return $this->ok([
            'items' => $jobs->map(fn ($j) => $this->facilities->repairRow($j))->values(),
            'budget_in_use' => $budget ? ['id' => $budget->id, 'name' => $budget->name ?? 'Budget'] : null,
            'can' => $this->can($request, $church),
        ]);
    }

    /** POST /repairs - anyone who sees the facilities can report one. */
    public function store(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'detail' => ['nullable', 'string', 'max:1000'],
            'priority' => ['sometimes', Rule::in(['normal', 'urgent'])],
            'equipment_id' => ['nullable', 'integer', Rule::exists('equipment', 'id')->where('territory_id', $church->id)->whereNull('deleted_at')],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('territory_id', $church->id)->whereNull('deleted_at')],
        ], ['title.required' => "Say what's wrong."]);
        $j = MaintenanceJob::create($d + ['territory_id' => $church->id, 'status' => 'reported', 'reported_by' => $request->user()->id]);

        return $this->ok($this->facilities->repairRow($j->fresh(['equipment', 'room', 'reporter'])), 'Repair reported.', 201);
    }

    /** PUT /repairs/{id} - status, who is on it, the cost (those who manage the facilities). */
    public function update(Request $request, int $id): JsonResponse
    {
        [$church, $j, $error] = $this->job($request, $id);
        if ($error) {
            return $error;
        }
        $d = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'priority' => ['sometimes', Rule::in(['normal', 'urgent'])],
            'status' => ['sometimes', Rule::in(array_keys(MaintenanceJob::STATUSES))],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);
        if (array_key_exists('status', $d)) {
            $d['done_on'] = $d['status'] === 'done' ? ($j->done_on?->toDateString() ?? $this->facilities->today()->toDateString()) : null;
        }
        $j->fill($d)->save();
        $label = MaintenanceJob::STATUSES[$j->status][0];

        return $this->ok($this->facilities->repairRow($j->fresh(['equipment', 'room', 'reporter', 'assignee'])), array_key_exists('status', $d) ? "Moved to {$label}." : 'Saved.');
    }

    /** POST /repairs/{id}/expense {budget_entry_id} - the Budgets entry recorded for it (ours only). */
    public function expense(Request $request, int $id): JsonResponse
    {
        [$church, $j, $error] = $this->job($request, $id);
        if ($error) {
            return $error;
        }
        $d = $request->validate(['budget_entry_id' => ['required', 'integer']]);
        $entry = BudgetEntry::find($d['budget_entry_id']);
        $budget = $entry ? Budget::find($entry->budget_id) : null;
        if (! $budget || $budget->territory_type !== 'church' || (int) $budget->territory_id !== (int) $church->id) {
            return $this->unprocessable('budget_entry_id', "That entry isn't in our church's budget.");
        }
        $j->update(['budget_entry_id' => $entry->id, 'cost' => $j->cost ?? (float) $entry->amount]);

        return $this->ok($this->facilities->repairRow($j->fresh(['equipment', 'room', 'reporter', 'assignee'])), 'The cost is recorded in the budget.');
    }

    /** [church, job, error] - those who manage the facilities. */
    private function job(Request $request, int $id): array
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $j = MaintenanceJob::where('territory_id', $church->id)->find($id);

        return $j ? [$church, $j, null] : [$church, null, $this->notFound("That repair isn't in your church.")];
    }
}
