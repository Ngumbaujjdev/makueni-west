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
