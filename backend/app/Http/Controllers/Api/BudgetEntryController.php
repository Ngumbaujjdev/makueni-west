<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Reports\Budget\BudgetData;
use App\Services\Budgets\BudgetBook;
use App\Support\BudgetAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money in and out against a budget - the Spending page and the Record money
 * window (docs/specs/budgets-spec.md). A place records against its own
 * budgets in use; a level above can look (read-only) at the places below it.
 * Every change goes through BudgetBook, which keeps the totals and History.
 */
class BudgetEntryController extends Controller
{
    public function __construct(private BudgetBook $book) {}

    /** The entries of a period (a year, or one month of it), newest first, with the period's figures. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|between:1,12',
            'budget_id' => 'nullable|integer',
            'direction' => 'nullable|in:in,out',
        ]);
        $user = $request->user();
        $place = BudgetAccess::place($user, $request->integer('territory_id') ?: null);
        $own = $place && BudgetAccess::isOwn($user, $place['type'], $place['id']);
        if (! $place || ($own && ! BudgetAccess::can($user, 'spending.read'))) {
            return $this->forbidden('You can only see the money of your own place, or of places below you.');
        }

        $query = BudgetEntry::with('lineItem.budgetLine', 'recorder:id,firstname,lastname')
            ->withCount(['media as receipts_count' => fn ($q) => $q->where('collection_name', 'receipts')])
            ->whereHas('budget', fn ($q) => $q->where('territory_type', $place['type'])->where('territory_id', $place['id']))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->query('direction')));
        if ($request->filled('budget_id')) {
            $query->where('budget_id', $request->integer('budget_id'));
        } else {
            $year = $request->integer('year') ?: (int) now()->year;
            $month = $request->filled('month') ? $request->integer('month') : null;
            $start = CarbonImmutable::create($year, $month ?? 1, 1);
            $end = $month ? $start->endOfMonth() : $start->endOfYear();
            $query->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]);
        }
        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->get();

        $out = $entries->where('direction', 'out');
        $biggest = $out->groupBy(fn ($e) => $e->lineItem?->budgetLine?->name)->map->sum('amount')->sortDesc();

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => $entries->map(fn ($e) => BudgetData::entryRow($e))->values(),
            'stats' => [
                'in' => round((float) $entries->where('direction', 'in')->sum('amount'), 2),
                'out' => round((float) $out->sum('amount'), 2),
                'count' => $entries->count(),
                'biggest_line' => $biggest->keys()->first(),
                'biggest_amount' => round((float) $biggest->first(), 2),
            ],
            'place' => [...$place, 'name' => \App\Models\Territory::find($place['id'])?->name],
            'view_only' => ! $own && ! $user->hasGlobalAccess(),
            'can_record' => ($own && BudgetAccess::can($user, 'record')) || $user->hasGlobalAccess(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $budget = $this->budgetFor($request, $data);
        if ($budget instanceof JsonResponse) {
            return $budget;
        }
        $entry = $this->book->record($request->user(), $budget, $data);

        return response()->json([
            'success' => true,
            'status' => 201,
            'message' => ($entry->direction === 'in' ? 'Recorded KES '.number_format((float) $entry->amount, 2).' received' : 'Recorded KES '.number_format((float) $entry->amount, 2).' spent').' on '.$entry->lineItem?->budgetLine?->name,
            'data' => BudgetData::entryRow($entry),
            'line' => $this->lineFigures($entry),
        ], 201);
    }

    public function update(Request $request, BudgetEntry $entry): JsonResponse
    {
        if ($denied = $this->denyUnlessCanRecord($request, $entry->budget)) {
            return $denied;
        }
        $entry = $this->book->changeEntry($request->user(), $entry, $this->validated($request, false));

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Saved the change', 'data' => BudgetData::entryRow($entry), 'line' => $this->lineFigures($entry)]);
    }

    public function destroy(Request $request, BudgetEntry $entry): JsonResponse
    {
        if ($denied = $this->denyUnlessCanRecord($request, $entry->budget)) {
            return $denied;
        }
        $this->book->removeEntry($request->user(), $entry);

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Removed the entry', 'data' => ['id' => $entry->id]]);
    }

    /** Undo a removal. */
    public function restore(Request $request, int $entryId): JsonResponse
    {
        $entry = BudgetEntry::onlyTrashed()->find($entryId);
        if (! $entry) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Nothing to bring back.'], 404);
        }
        if ($denied = $this->denyUnlessCanRecord($request, $entry->budget)) {
            return $denied;
        }
        $entry = $this->book->restoreEntry($request->user(), $entry);

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Brought the entry back', 'data' => BudgetData::entryRow($entry)]);
    }

    /**
     * One entry, for its own page: the entry, its budget, what it did to its
     * line (before / after), other money on the line, and its History.
     */
    public function show(Request $request, int $entryId): JsonResponse
    {
        $entry = BudgetEntry::withTrashed()->with('budget', 'lineItem.budgetLine', 'lineItem.budgetCategory', 'recorder:id,firstname,lastname', 'updater:id,firstname,lastname')->find($entryId);
        if (! $entry || ! $entry->budget) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Entry not found.'], 404);
        }
        $budget = $entry->budget;
        if (! BudgetAccess::canSee($request->user(), $budget)) {
            return $this->forbidden('You can only see money of your own place, or of places below you.');
        }
        $item = $entry->lineItem;
        $others = BudgetEntry::with('lineItem.budgetLine', 'recorder:id,firstname,lastname')->where('budget_line_item_id', $entry->budget_line_item_id);
        // Money on the line before this entry: earlier days, or the same day recorded earlier.
        $before = (float) (clone $others)->where('id', '!=', $entry->id)
            ->where(fn ($q) => $q->where('entry_date', '<', $entry->entry_date->toDateString())
                ->orWhere(fn ($q) => $q->where('entry_date', $entry->entry_date->toDateString())->where('id', '<', $entry->id)))
            ->sum('amount');
        $name = fn ($u) => $u ? trim("{$u->firstname} {$u->lastname}") : null;
        $history = $budget->budgetLogs()->with('performer:id,firstname,lastname')
            ->where('affected_model', 'budget_entry')->where('affected_model_id', $entry->id)
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $canChange = ! $entry->trashed() && $budget->status === 'active' && BudgetAccess::canWrite($request->user(), $budget, 'record');

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => [
                'entry' => [
                    ...BudgetData::entryRow($entry),
                    'recorded_at' => $entry->created_at?->toIso8601String(),
                    'changed_by' => $entry->updated_by && $entry->updated_by !== $entry->recorded_by ? $name($entry->updater) : null,
                    'changed_at' => $entry->updated_at && $entry->created_at && $entry->updated_at->gt($entry->created_at->addSeconds(5)) ? $entry->updated_at->toIso8601String() : null,
                ],
                'budget' => $this->budgetBrief($budget),
                'line' => [
                    'line_id' => $item?->budget_line_id,
                    'name' => $item?->budgetLine?->name,
                    'side' => $item?->budgetCategory?->slug === 'income' ? 'in' : 'out',
                    'planned' => (float) $item?->budgeted_amount,
                    'actual' => (float) $item?->actual_amount,
                    'left' => round((float) $item?->budgeted_amount - (float) $item?->actual_amount, 2),
                    'before' => round($before, 2),
                    'is_unplanned' => (bool) $item?->is_unplanned,
                ],
                'others' => (clone $others)->where('id', '!=', $entry->id)->orderByDesc('entry_date')->orderByDesc('id')->limit(5)->get()->map(fn ($e) => BudgetData::entryRow($e))->values(),
                'others_count' => (clone $others)->where('id', '!=', $entry->id)->count(),
                'history' => $history->map(fn ($log) => $this->logRow($log))->values(),
                'receipts' => $this->receiptRows($entry),
                'view_only' => ! BudgetAccess::canWrite($request->user(), $budget, 'record'),
                'can' => ['change' => $canChange, 'receipts' => ! $entry->trashed() && BudgetAccess::canWrite($request->user(), $budget, 'record')],
            ],
        ]);
    }

    /**
     * One line of a budget, for its own page: planned against what came in or
     * went out, every amount on it, and when the money moved - month by month
     * (by entry date) for a whole-year budget, day by day for a month.
     */
    public function line(Request $request, Budget $budget, int $lineId): JsonResponse
    {
        if (! BudgetAccess::canSee($request->user(), $budget)) {
            return $this->forbidden('You can only see budgets of your own place, or of places below you.');
        }
        $item = $budget->budgetLineItems()->with('budgetLine', 'budgetCategory')->where('budget_line_id', $lineId)->first();
        if (! $item) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That line is not in this budget.'], 404);
        }
        $entries = $item->entries()->with('lineItem.budgetLine', 'recorder:id,firstname,lastname')->orderByDesc('entry_date')->orderByDesc('id')->get();
        $planned = (float) $item->budgeted_amount;
        $actual = (float) $item->actual_amount;
        $previous = $this->book->previous($budget->territory_type, (int) $budget->territory_id, (int) $budget->fiscal_year, $budget->period_month);
        $previousItem = $previous?->budgetLineItems->firstWhere('budget_line_id', $lineId);

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => [
                'budget' => $this->budgetBrief($budget),
                'line' => [
                    'line_id' => $lineId,
                    'name' => $item->budgetLine?->name ?? "Line {$lineId}",
                    'description' => $item->budgetLine?->description,
                    'side' => $item->budgetCategory?->slug === 'income' ? 'in' : 'out',
                    'planned' => $planned,
                    'actual' => $actual,
                    'left' => round($planned - $actual, 2),
                    'pct' => $planned > 0 ? round($actual / $planned * 100, 1) : null,
                    'is_unplanned' => (bool) $item->is_unplanned,
                    'last_date' => $entries->max('entry_date')?->toDateString(),
                ],
                'previous' => $previous ? ['id' => $previous->id, 'period_label' => $previous->period_label, 'planned' => $previousItem ? (float) $previousItem->budgeted_amount : null] : null,
                'entries' => $entries->map(fn ($e) => BudgetData::entryRow($e))->values(),
                'chart' => BudgetData::moneyOverTime($budget, $entries, $planned),
                'view_only' => ! BudgetAccess::canWrite($request->user(), $budget, 'record'),
                'can' => ['record' => $budget->status === 'active' && BudgetAccess::canWrite($request->user(), $budget, 'record')],
            ],
        ]);
    }

    private function budgetBrief(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'period_label' => $budget->period_label,
            'status' => $budget->status,
            'is_year' => $budget->period_month === null,
            'fiscal_year' => (int) $budget->fiscal_year,
            'start_date' => $budget->start_date?->toDateString(),
            'end_date' => $budget->end_date?->toDateString(),
            'place' => ['type' => $budget->territory_type, 'id' => (int) $budget->territory_id, 'name' => \App\Models\Territory::find($budget->territory_id)?->name],
        ];
    }

    private function logRow($log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'who' => $log->performer ? trim("{$log->performer->firstname} {$log->performer->lastname}") : null,
            'when' => $log->created_at?->toIso8601String(),
            'when_label' => $log->created_at?->format('j M Y, g:i a'),
        ];
    }

    /* ---------------------------------------------------------------- */

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'budget_id' => 'nullable|integer',
            'budget_line_id' => "{$required}|integer|exists:budget_lines,id",
            'amount' => "{$required}|numeric|min:0.01|max:9999999999",
            'entry_date' => "{$required}|date",
            'description' => "{$required}|string|max:255",
            'counterparty' => 'nullable|string|max:255',
            'method' => 'nullable|in:'.implode(',', array_keys(BudgetEntry::METHODS)),
            'reference' => 'nullable|string|max:100',
        ], [
            'amount.min' => 'Type the amount.',
            'description.required' => 'Say what it was for.',
            'budget_line_id.required' => 'Choose the line.',
        ]);
    }

    /** The budget to record against: the one named, or the place's budget in use on that date. */
    private function budgetFor(Request $request, array $data): Budget|JsonResponse
    {
        if (! empty($data['budget_id'])) {
            $budget = Budget::find($data['budget_id']);
            if (! $budget) {
                return response()->json(['success' => false, 'status' => 404, 'message' => 'Budget not found.'], 404);
            }

            return $this->denyUnlessCanRecord($request, $budget) ?? $budget;
        }
        $place = BudgetAccess::acting($request->user());
        if (! $place || ! BudgetAccess::can($request->user(), 'record')) {
            return $this->forbidden('You do not have permission to record money.');
        }
        $budget = $this->book->budgetInUseOn($place['type'], $place['id'], $data['entry_date']);
        if (! $budget) {
            $day = CarbonImmutable::parse($data['entry_date']);

            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'There\'s no budget in use for '.$day->format('F Y').'. Prepare one (or start using it) first.',
                'errors' => ['entry_date' => ['No budget in use for '.$day->format('F Y').'.']],
            ], 422);
        }

        return $budget;
    }

    /** POST /budget-entries/{entry}/receipts - attach a photo or PDF (5 MB, at most 3 per entry). */
    public function addReceipt(Request $request, BudgetEntry $entry): JsonResponse
    {
        if ($denied = $this->denyUnlessCanRecord($request, $entry->budget)) {
            return $denied;
        }
        $request->validate(
            ['receipt' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120'],
            ['receipt.mimes' => 'A receipt is a photo (JPG, PNG, WEBP) or a PDF.', 'receipt.max' => 'A receipt can be at most 5 MB.', 'receipt.required' => 'Choose the receipt to attach.'],
        );
        if ($entry->getMedia('receipts')->count() >= BudgetEntry::MAX_RECEIPTS) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'An entry can have at most '.BudgetEntry::MAX_RECEIPTS.' receipts. Remove one first.', 'errors' => ['receipt' => ['At most '.BudgetEntry::MAX_RECEIPTS.' receipts.']]], 422);
        }
        try {
            $this->book->addReceipt($request->user(), $entry, $request->file('receipt'));
        } catch (\Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'That file isn\'t a photo or a PDF we can read.', 'errors' => ['receipt' => ['Not a readable photo or PDF.']]], 422);
        }

        return response()->json(['success' => true, 'status' => 201, 'message' => 'Receipt attached', 'data' => $this->receiptRows($entry->fresh())], 201);
    }

    /** GET /budget-entries/{entryId}/receipts/{mediaId} - the file itself, for people who may see the budget. */
    public function showReceipt(Request $request, int $entryId, int $mediaId)
    {
        $entry = BudgetEntry::withTrashed()->with('budget')->find($entryId);
        if (! $entry || ! $entry->budget) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Receipt not found.'], 404);
        }
        if (! BudgetAccess::canSee($request->user(), $entry->budget)) {
            return $this->forbidden('You can only see receipts of your own place, or of places below you.');
        }
        $media = $entry->getMedia('receipts')->firstWhere('id', $mediaId);
        if (! $media) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Receipt not found.'], 404);
        }

        return $media->toInlineResponse($request);
    }

    /** DELETE /budget-entries/{entry}/receipts/{mediaId} */
    public function removeReceipt(Request $request, BudgetEntry $entry, int $mediaId): JsonResponse
    {
        if ($denied = $this->denyUnlessCanRecord($request, $entry->budget)) {
            return $denied;
        }
        $media = $entry->getMedia('receipts')->firstWhere('id', $mediaId);
        if (! $media) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'Receipt not found.'], 404);
        }
        $this->book->removeReceipt($request->user(), $entry, $media);

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Receipt removed', 'data' => $this->receiptRows($entry->fresh())]);
    }

    /** An entry's receipts: id, name, type (image / pdf), size, and where to fetch it. */
    private function receiptRows(BudgetEntry $entry): array
    {
        return $entry->getMedia('receipts')->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'type' => str_starts_with((string) $m->mime_type, 'image/') ? 'image' : 'pdf',
            'size' => (int) $m->size,
            'added_at' => $m->created_at?->toIso8601String(),
            'url' => "/budget-entries/{$entry->id}/receipts/{$m->id}",
        ])->values()->all();
    }

    private function denyUnlessCanRecord(Request $request, Budget $budget): ?JsonResponse
    {
        if (BudgetAccess::canWrite($request->user(), $budget, 'record')) {
            return null;
        }

        return $this->forbidden(BudgetAccess::canSee($request->user(), $budget)
            ? 'View only - only '.($budget->territory?->name ?? 'that place').' can record its money.'
            : 'You can only record money for your own budgets.');
    }

    /** The line's figures after the change, for the window's "left" line. */
    private function lineFigures(BudgetEntry $entry): array
    {
        $item = $entry->lineItem()->first();

        return ['planned' => (float) $item?->budgeted_amount, 'actual' => (float) $item?->actual_amount, 'left' => round((float) $item?->budgeted_amount - (float) $item?->actual_amount, 2)];
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
