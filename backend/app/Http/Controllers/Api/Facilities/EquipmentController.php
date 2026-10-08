<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Models\Equipment;
use App\Models\EquipmentLoan;
use App\Models\EquipmentPhoto;
use App\Models\MaintenanceJob;
use App\Models\Person;
use App\Models\Territory;
use App\Models\User;
use App\Services\Images\ImageEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OwenIt\Auditing\Models\Audit;

/**
 * What the church owns, where it is kept, who has borrowed it, and its
 * repairs (P5); and, as assets (round 2), its photos, receipts and the
 * Budgets entry that paid for it, and asking to borrow it.
 */
class EquipmentController extends FacilitiesBase
{
    /** GET /equipment */
    public function index(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $items = Equipment::with(['room', 'photos'])->where('territory_id', $church->id)->orderBy('name')->get();
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
        if (EquipmentLoan::where('equipment_id', $e->id)->where('status', 'out')->exists()) {
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

    /**
     * POST /equipment/{id}/loans {to_person_id|to_name, quantity, due_on, note}
     * - a manager lends it now. With ask: true anyone who sees the facilities
     * asks to borrow it {quantity, from, due_on, note}; a manager agrees later.
     */
    public function lend(Request $request, int $id): JsonResponse
    {
        if ($request->boolean('ask')) {
            return $this->ask($request, $id);
        }
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
            'note' => $d['note'] ?? null, 'by' => $request->user()->id, 'status' => 'out',
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
        $loan = EquipmentLoan::whereHas('equipment', fn ($q) => $q->where('territory_id', $church->id))->where('status', 'out')->find($id);
        if (! $loan) {
            return $this->notFound("That loan isn't open in your church.");
        }
        $loan->update(['status' => 'returned', 'returned_on' => $this->facilities->today()->toDateString()]);

        return $this->ok($this->detail($request, $church, $loan->equipment), 'Marked back.');
    }

    /** GET /loans?status=out|requested - what is out now (the default), or the asks waiting, for the Equipment page. */
    public function loans(Request $request): JsonResponse
    {
        $church = $this->church($request);
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $status = in_array($request->query('status'), ['out', 'requested'], true) ? $request->query('status') : 'out';
        $loans = EquipmentLoan::with(['equipment', 'person', 'asker'])->whereHas('equipment', fn ($q) => $q->where('territory_id', $church->id))
            ->where('status', $status)->orderBy($status === 'out' ? 'due_on' : 'out_on')->get();

        return $this->ok($loans->map(fn ($l) => $this->loanFor($request, $church, $l))->values());
    }

    /** POST /loans/{id}/approve - a manager agrees to an ask: it is out from today (or the day asked for, if later). */
    public function approve(Request $request, int $id): JsonResponse
    {
        [$church, $loan, $error] = $this->ask_($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $e = $loan->equipment;
        $available = (int) $e->quantity - (int) ($this->facilities->onLoan([$e->id])[$e->id] ?? 0);
        if ($loan->quantity > $available) {
            return $this->unprocessable('quantity', $available ? "Only {$available} are here now - lend fewer, or wait for some to come back." : 'All of them are out on loan now.');
        }
        $today = $this->facilities->today()->toDateString();
        $loan->update([
            'status' => 'out', 'by' => $request->user()->id, 'decided_by' => $request->user()->id, 'decided_at' => now(),
            'out_on' => max($today, $loan->out_on->toDateString()),
        ]);

        return $this->ok($this->detail($request, $church, $e->fresh()), "Agreed - {$e->name} is lent to {$loan->to_name}.");
    }

    /** POST /loans/{id}/decline {reason?} */
    public function decline(Request $request, int $id): JsonResponse
    {
        [$church, $loan, $error] = $this->ask_($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $loan->update(['status' => 'declined', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decline_reason' => $d['reason'] ?? null]);

        return $this->ok($this->detail($request, $church, $loan->equipment->fresh()), 'Declined.');
    }

    /** DELETE /loans/{id} - the one who asked takes their ask back (a manager may too). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [$church, $loan, $error] = $this->ask_($request, $id, 'read');
        if ($error) {
            return $error;
        }
        if ((int) $loan->requested_by !== (int) $request->user()->id && ! $this->facilities->canManage($request->user(), $church)) {
            return $this->forbidden('Only the one who asked can take the ask back.');
        }
        $e = $loan->equipment;
        $loan->delete();

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Your ask is taken back.');
    }

    // ------------------------------------------------------------------ photos

    /** POST /equipment/{id}/photos {photos[]} - up to EquipmentPhoto::MAX, stored upright as WebP. */
    public function addPhotos(Request $request, int $id, ImageEngine $engine): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $request->validate(
            ['photos' => ['required', 'array', 'min:1', 'max:'.EquipmentPhoto::MAX], 'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']],
            ['photos.*.mimes' => 'A photo is a JPG, PNG or WEBP.', 'photos.*.max' => 'A photo can be at most 10 MB.', 'photos.*.image' => 'That file is not a photo.'],
        );
        $have = $e->photos()->count();
        if ($have + count($request->file('photos')) > EquipmentPhoto::MAX) {
            return $this->unprocessable('photos', 'An item can have at most '.EquipmentPhoto::MAX.' photos'.($have ? " - it has {$have}." : '.'));
        }
        foreach ($request->file('photos') as $n => $file) {
            $img = $engine->store($file, "equipment/{$church->id}/{$e->id}", 1600, 82, true, 'photos');
            EquipmentPhoto::create(['equipment_id' => $e->id, 'path' => $img->path, 'thumb_path' => $img->thumbPath, 'width' => $img->width, 'height' => $img->height, 'bytes' => $img->bytes, 'position' => $have + $n, 'created_by' => $request->user()->id]);
        }

        return $this->ok($this->detail($request, $church, $e->fresh()), count($request->file('photos')) === 1 ? 'Photo added.' : 'Photos added.', 201);
    }

    /** DELETE /equipment/{id}/photos/{photo} */
    public function removePhoto(Request $request, int $id, int $photo, ImageEngine $engine): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $p = $e->photos()->find($photo);
        if (! $p) {
            return $this->notFound('That photo is not on this item.');
        }
        $engine->delete($p->path, $p->thumb_path);
        $p->delete();

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Photo removed.');
    }

    /** POST /equipment/{id}/photos/order {ids[]} - the first is the one shown. */
    public function orderPhotos(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        foreach (array_values($d['ids']) as $n => $pid) {
            $e->photos()->whereKey($pid)->update(['position' => $n]);
        }

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Order saved.');
    }

    /** GET /equipment-photos/{photo}/{size?} - a signed link (see EquipmentPhoto::present). */
    public function photo(int $photo, ?string $size = null)
    {
        $p = EquipmentPhoto::find($photo);
        $path = $p ? ($size === 'thumb' ? ($p->thumb_path ?: $p->path) : $p->path) : null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
    }

    // ------------------------------------------------------------------ receipts

    /** POST /equipment/{id}/receipts {receipt} - a photo or PDF of what was paid. */
    public function addReceipt(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $request->validate(
            ['receipt' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120'],
            ['receipt.mimes' => 'A receipt is a photo (JPG, PNG, WEBP) or a PDF.', 'receipt.max' => 'A receipt can be at most 5 MB.', 'receipt.required' => 'Choose the receipt to attach.'],
        );
        if ($e->getMedia('receipts')->count() >= Equipment::MAX_RECEIPTS) {
            return $this->unprocessable('receipt', 'An item can have at most '.Equipment::MAX_RECEIPTS.' receipts. Remove one first.');
        }
        $file = $request->file('receipt');
        try {
            $e->addMedia($file)
                ->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Receipt')
                ->withCustomProperties(['added_by' => $request->user()->id])
                ->toMediaCollection('receipts');
        } catch (\Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection) {
            return $this->unprocessable('receipt', "That file isn't a photo or a PDF we can read.");
        }

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Receipt attached.', 201);
    }

    /** GET /equipment/{id}/receipts/{media} - the file, for those who see the facilities. */
    public function showReceipt(Request $request, int $id, int $media)
    {
        [, $e, $error] = $this->item($request, $id);
        if ($error) {
            return $error;
        }
        $m = $e->getMedia('receipts')->firstWhere('id', $media);

        return $m ? $m->toInlineResponse($request) : $this->notFound('Receipt not found.');
    }

    /** DELETE /equipment/{id}/receipts/{media} */
    public function removeReceipt(Request $request, int $id, int $media): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $m = $e->getMedia('receipts')->firstWhere('id', $media);
        if (! $m) {
            return $this->notFound('Receipt not found.');
        }
        $m->delete();

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Receipt removed.');
    }

    // ------------------------------------------------------------------ Budgets

    /**
     * POST /equipment/{id}/expense {budget_entry_id} - the Budgets entry that
     * paid for it (ours only). Fills the price if it was empty, and when the
     * entry has no receipt yet, the item's receipts are copied onto it.
     */
    public function linkExpense(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $d = $request->validate(['budget_entry_id' => ['required', 'integer']]);
        $entry = BudgetEntry::find($d['budget_entry_id']);
        $budget = $entry ? Budget::find($entry->budget_id) : null;
        if (! $budget || $budget->territory_type !== 'church' || (int) $budget->territory_id !== (int) $church->id) {
            return $this->unprocessable('budget_entry_id', "That entry isn't in our church's budget.");
        }
        if ($entry->direction !== 'out') {
            return $this->unprocessable('budget_entry_id', 'That entry is money in - pick what was paid out.');
        }
        DB::transaction(function () use ($e, $entry) {
            $e->update(['budget_entry_id' => $entry->id, 'value' => $e->value ?? round((float) $entry->amount / max(1, (int) $e->quantity), 2), 'bought_on' => $e->bought_on ?? $entry->entry_date]);
            if ($entry->getMedia('receipts')->isEmpty()) {
                foreach ($e->getMedia('receipts')->take(BudgetEntry::MAX_RECEIPTS) as $m) {
                    $m->copy($entry, 'receipts', 'local');
                }
            }
        });

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Recorded in Budgets.');
    }

    /** DELETE /equipment/{id}/expense - not that entry after all (the entry itself stays in Budgets). */
    public function unlinkExpense(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id, 'manage');
        if ($error) {
            return $error;
        }
        $e->update(['budget_entry_id' => null]);

        return $this->ok($this->detail($request, $church, $e->fresh()), 'No longer linked to Budgets.');
    }

    /** GET /equipment/expenses?q= - our church's money paid out (the latest first), to link one already recorded. */
    public function expenses(Request $request): JsonResponse
    {
        $church = $this->church($request, 'manage');
        if ($church instanceof JsonResponse) {
            return $church;
        }
        $q = trim((string) $request->query('q', ''));
        $budgetIds = Budget::where('territory_type', 'church')->where('territory_id', $church->id)->pluck('id');
        $taken = Equipment::where('territory_id', $church->id)->whereNotNull('budget_entry_id')->pluck('budget_entry_id')->all();
        $entries = BudgetEntry::with('lineItem')->whereIn('budget_id', $budgetIds->all() ?: [0])->where('direction', 'out')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('description', 'like', "%{$q}%")->orWhere('counterparty', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%")))
            ->orderByDesc('entry_date')->orderByDesc('id')->limit(30)->get();

        return $this->ok($entries->map(fn ($x) => $this->entryRow($x) + ['taken' => in_array($x->id, $taken, true)])->values());
    }

    // ------------------------------------------------------------------ helpers

    private function detail(Request $request, Territory $church, Equipment $e): array
    {
        $e->loadMissing(['room', 'photos', 'budgetEntry', 'loans.person', 'loans.asker', 'repairs.reporter', 'repairs.assignee']);
        $out = (int) $e->loans->where('status', 'out')->sum('quantity');
        $loanIds = $e->loans->pluck('id')->all();
        $jobIds = $e->repairs->pluck('id')->all();
        $audits = Audit::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', 'equipment')->where('auditable_id', $e->id))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'equipment_loan')->whereIn('auditable_id', $loanIds ?: [0]))
            ->orWhere(fn ($w) => $w->where('auditable_type', 'maintenance_job')->whereIn('auditable_id', $jobIds ?: [0])))->latest('id')->limit(100)->get();
        $users = User::whereIn('id', $audits->pluck('user_id')->filter()->unique())->get()->keyBy('id');

        return $this->facilities->equipmentRow($e, $out, $e->repairs->where('status', '!=', 'done')->count()) + [
            'photos' => $e->photos->map(fn ($p) => $p->present())->values(),
            'receipts' => $e->getMedia('receipts')->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'type' => str_starts_with((string) $m->mime_type, 'image/') ? 'image' : 'pdf',
                'size' => (int) $m->size, 'added_at' => $m->created_at?->toIso8601String(), 'url' => "/equipment/{$e->id}/receipts/{$m->id}",
            ])->values(),
            'budget_entry' => $e->budgetEntry ? $this->entryRow($e->budgetEntry) : null,
            'budget_in_use' => ! $e->budget_entry_id && $this->facilities->canManage($request->user(), $church)
                ? (fn ($b) => $b ? ['id' => $b->id, 'name' => $b->name ?? 'Budget'] : null)(app(\App\Services\Budgets\BudgetBook::class)->budgetInUseOn('church', $church->id, $this->facilities->today()->toDateString()))
                : null,
            'repairs_spent' => round((float) $e->repairs->sum('cost'), 2),
            'loans' => $e->loans->map(fn ($l) => $this->loanFor($request, $church, $l))->values(),
            'repairs' => $e->repairs->map(fn ($j) => $this->facilities->repairRow($j))->values(),
            'history' => $audits->map(function (Audit $a) use ($users) {
                $who = ($u = $users->get($a->user_id)) ? trim("{$u->firstname} {$u->lastname}") : 'The system';
                $v = (array) ($a->new_values ?: $a->old_values);
                $sentence = match (true) {
                    $a->auditable_type === 'equipment_loan' && $a->event === 'created' && ($v['status'] ?? 'out') === 'requested' => "{$who} asked to borrow it for ".($v['to_name'] ?? 'someone'),
                    $a->auditable_type === 'equipment_loan' && $a->event === 'created' => "{$who} lent it to ".($v['to_name'] ?? 'someone'),
                    $a->auditable_type === 'equipment_loan' && $a->event === 'deleted' => "{$who} took back an ask to borrow it",
                    $a->auditable_type === 'equipment_loan' && ($v['status'] ?? null) === 'out' => "{$who} agreed to lend it",
                    $a->auditable_type === 'equipment_loan' && ($v['status'] ?? null) === 'declined' => "{$who} declined an ask to borrow it",
                    $a->auditable_type === 'equipment_loan' => "{$who} marked it back",
                    $a->event === 'updated' && array_key_exists('budget_entry_id', $v) => $v['budget_entry_id'] ? "{$who} recorded what it cost in Budgets" : "{$who} unlinked it from Budgets",
                    $a->auditable_type === 'maintenance_job' && $a->event === 'created' => "{$who} reported a repair: ".($v['title'] ?? ''),
                    $a->auditable_type === 'maintenance_job' => "{$who} moved its repair to ".strtolower(\App\Models\MaintenanceJob::STATUSES[$v['status'] ?? '']['0'] ?? 'a new status'),
                    $a->event === 'created' => "{$who} added it",
                    array_key_exists('condition', $v) => "{$who} marked it ".strtolower(Equipment::CONDITIONS[$v['condition']][0] ?? $v['condition']),
                    default => "{$who} changed ".collect(array_keys($v))->map(fn ($k) => ['room_id' => 'where it is kept', 'bought_on' => 'when it was bought', 'value' => 'its price', 'supplier' => 'where it was bought'][$k] ?? str_replace('_', ' ', $k))->implode(', '),
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
            'supplier' => ['sometimes', 'nullable', 'string', 'max:120'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], ['room_id.exists' => 'Pick one of our rooms.']);

        return $e ? array_filter($d, fn ($v, $k) => $request->exists($k), ARRAY_FILTER_USE_BOTH) : $d;
    }

    /** POST /equipment/{id}/loans with ask: true - asking to borrow {quantity, from, due_on, note}. */
    private function ask(Request $request, int $id): JsonResponse
    {
        [$church, $e, $error] = $this->item($request, $id);
        if ($error) {
            return $error;
        }
        $today = $this->facilities->today()->toDateString();
        $d = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'from' => ['nullable', 'date', "after_or_equal:{$today}"],
            'due_on' => ['required', 'date', 'after_or_equal:'.($request->input('from') ?: $today)],
            'note' => ['required', 'string', 'max:300'],
        ], ['due_on.required' => 'Say when it will come back.', 'due_on.after_or_equal' => 'It comes back after it goes out.', 'note.required' => 'Say what it is for.']);
        $qty = (int) ($d['quantity'] ?? 1);
        if ($qty > (int) $e->quantity) {
            return $this->unprocessable('quantity', "We only have {$e->quantity}.");
        }
        $user = $request->user();
        if (EquipmentLoan::where('equipment_id', $e->id)->where('status', 'requested')->where('requested_by', $user->id)->exists()) {
            return $this->unprocessable('note', 'You have already asked for this - wait for an answer, or take that ask back.');
        }
        EquipmentLoan::create([
            'equipment_id' => $e->id, 'to_name' => trim("{$user->firstname} {$user->lastname}") ?: $user->email, 'quantity' => $qty, 'status' => 'requested',
            'out_on' => $d['from'] ?? $today, 'due_on' => $d['due_on'], 'note' => $d['note'], 'requested_by' => $user->id,
        ]);

        return $this->ok($this->detail($request, $church, $e->fresh()), 'Asked - the facilities manager will say yes or no.', 201);
    }

    /** [church, the ask, error] - an ask still waiting, in our church. */
    private function ask_(Request $request, int $id, string $ability): array
    {
        $church = $this->church($request, $ability);
        if ($church instanceof JsonResponse) {
            return [null, null, $church];
        }
        $loan = EquipmentLoan::with('equipment')->whereHas('equipment', fn ($q) => $q->where('territory_id', $church->id))->where('status', 'requested')->find($id);

        return $loan ? [$church, $loan, null] : [$church, null, $this->notFound("That ask isn't waiting in your church.")];
    }

    /** A loan, and what this user may do with it. */
    private function loanFor(Request $request, Territory $church, EquipmentLoan $l): array
    {
        $manage = $this->facilities->canManage($request->user(), $church);

        return $this->facilities->loanRow($l) + [
            'asker' => $l->asker ? trim("{$l->asker->firstname} {$l->asker->lastname}") : null,
            'mine' => (int) $l->requested_by === (int) $request->user()->id,
            'can_decide' => $manage && $l->status === 'requested',
            'can_cancel' => $l->status === 'requested' && ($manage || (int) $l->requested_by === (int) $request->user()->id),
        ];
    }

    /** A Budgets entry, short. */
    private function entryRow(BudgetEntry $x): array
    {
        return [
            'id' => $x->id, 'budget_id' => $x->budget_id, 'date' => $x->entry_date?->toDateString(), 'amount' => (float) $x->amount,
            'description' => $x->description, 'counterparty' => $x->counterparty, 'reference' => $x->reference, 'deleted' => (bool) $x->deleted_at,
        ];
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
