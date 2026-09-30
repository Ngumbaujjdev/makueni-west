<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetDeduction;
use App\Models\BudgetDeductionItem;
use App\Models\BudgetLineItem;
use App\Services\Budgets\BudgetBook;
use App\Support\BudgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BudgetController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Budgets: a month or a whole year of one place (docs/specs/budgets-spec.md)
    |--------------------------------------------------------------------------
    | Each level keeps its own budgets (no approval); a level above can look
    | at the budgets below it, read-only. Access is checked by
    | EnsureBudgetAccess; every change goes through BudgetBook.
    */

    public function __construct(private BudgetBook $book) {}

    /**
     * A place's budgets - your own, or (read-only) a place below yours with
     * ?territory_id= - with the figures for the year.
     */
    public function index(Request $request): JsonResponse
    {
        $place = BudgetAccess::place($request->user(), $request->integer('territory_id') ?: null);
        if (! $place) {
            return $this->forbidden('You can only see your own budgets, or those of places below you.');
        }

        $year = $request->integer('year') ?: null;
        $budgets = Budget::with(['creator:id,firstname,lastname', 'starter:id,firstname,lastname'])
            ->where('territory_type', $place['type'])->where('territory_id', $place['id'])
            ->when($year, fn ($q) => $q->where('fiscal_year', $year))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('fiscal_year')
            ->orderByRaw('period_month IS NULL DESC, period_month DESC')
            ->get();

        $statsYear = $year ?? (int) now()->year;
        $ofYear = Budget::where('territory_type', $place['type'])->where('territory_id', $place['id'])->where('fiscal_year', $statsYear)->get();
        $years = Budget::where('territory_type', $place['type'])->where('territory_id', $place['id'])
            ->distinct()->pluck('fiscal_year')->push((int) now()->year)->unique()->sortDesc()->values();

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => $budgets->map(fn ($b) => $this->summaryOf($b))->values(),
            'place' => $this->placeInfo($place),
            'view_only' => ! BudgetAccess::isOwn($request->user(), $place['type'], $place['id']) && ! $request->user()->hasGlobalAccess(),
            'years' => $years,
            'stats' => [
                'year' => $statsYear,
                'budgets' => $ofYear->count(),
                'in_use' => $ofYear->where('status', 'active')->count(),
                'drafts' => $ofYear->where('status', 'draft')->count(),
                'in_planned' => round((float) $ofYear->sum('total_income_budgeted'), 2),
                'out_planned' => round((float) $ofYear->sum('total_expense_budgeted'), 2),
                'in_actual' => round((float) $ofYear->sum('total_income_actual'), 2),
                'out_actual' => round((float) $ofYear->sum('total_expense_actual'), 2),
            ],
        ]);
    }

    /** What the New budget form needs: usable lines, periods already taken, and last budget's amounts to copy. */
    public function form(Request $request): JsonResponse
    {
        $place = $this->ownPlace($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = $request->integer('year') ?: (int) now()->year;
        $month = $request->has('month') ? ($request->integer('month') ?: null) : (int) now()->month;

        return $this->formResponse($place, $year, $month);
    }

    /** The form for changing a budget, filled with its amounts. */
    public function formFor(Budget $budget): JsonResponse
    {
        $place = ['type' => $budget->territory_type, 'id' => (int) $budget->territory_id];
        $budget->load('budgetLineItems');

        return $this->formResponse($place, (int) $budget->fiscal_year, $budget->period_month, $budget);
    }

    public function store(Request $request): JsonResponse
    {
        $place = $this->ownPlace($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $this->validateBudget($request);
        $budget = $this->book->save($request->user(), $place['type'], $place['id'], $data);

        return response()->json([
            'success' => true,
            'status' => 201,
            'message' => $budget->status === 'active' ? "The {$budget->period_label} budget is in use" : "Saved the {$budget->period_label} budget as a draft",
            'data' => $this->detailOf($budget, $request),
        ], 201);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        if (! $budget->is_editable) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'A closed budget can\'t be changed. Reopen it first.'], 422);
        }
        if ($request->filled('updated_at') && ! $budget->updated_at?->equalTo(\Carbon\Carbon::parse($request->input('updated_at')))) {
            return response()->json(['success' => false, 'status' => 409, 'message' => 'Someone else changed this budget while you were editing. Reload it to see their changes.'], 409);
        }
        $data = $this->validateBudget($request);
        $budget = $this->book->save($request->user(), $budget->territory_type, (int) $budget->territory_id, $data, $budget->load('budgetLineItems'));

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => "Saved the {$budget->period_label} budget",
            'data' => $this->detailOf($budget, $request),
        ]);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $label = $budget->period_label;
        $this->book->delete($budget, $request->user());

        return response()->json(['success' => true, 'status' => 200, 'message' => "Deleted the draft {$label} budget", 'data' => null]);
    }

    public function start(Request $request, Budget $budget): JsonResponse
    {
        $this->book->start($budget, $request->user());

        return $this->moved($budget, $request, "The {$budget->period_label} budget is in use");
    }

    public function close(Request $request, Budget $budget): JsonResponse
    {
        $this->book->close($budget, $request->user());

        return $this->moved($budget, $request, "Closed the {$budget->period_label} budget");
    }

    public function reopen(Request $request, Budget $budget): JsonResponse
    {
        $this->book->reopen($budget, $request->user());

        return $this->moved($budget, $request, "Reopened the {$budget->period_label} budget");
    }

    /** One budget: its lines with planned, received/spent and left, totals, and what the user may do with it. */
    public function show(Request $request, Budget $budget): JsonResponse
    {
        return response()->json(['success' => true, 'status' => 200, 'data' => $this->detailOf($budget, $request)]);
    }

    /** History in plain sentences, newest first. */
    public function history(Budget $budget): JsonResponse
    {
        $logs = $budget->budgetLogs()->with('performer:id,firstname,lastname')->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'who' => $log->performer ? trim("{$log->performer->firstname} {$log->performer->lastname}") : null,
                'when' => $log->created_at?->toIso8601String(),
                'when_label' => $log->created_at?->format('j M Y, g:i a'),
            ])->values(),
        ]);
    }

    /* ---------------------------------------------------------------- */

    /** The acting place, for creating (a global admin names one with territory_id). */
    private function ownPlace(Request $request): array|JsonResponse
    {
        $user = $request->user();
        $place = $user->hasGlobalAccess()
            ? BudgetAccess::place($user, $request->integer('territory_id') ?: null)
            : BudgetAccess::acting($user);

        return $place ?? $this->forbidden('Choose the church, region or diocese this budget is for.');
    }

    private function validateBudget(Request $request): array
    {
        return $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'nullable|integer|between:1,12',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'present|array',
            'lines.*.budget_line_id' => 'required|integer|exists:budget_lines,id',
            'lines.*.amount' => 'nullable|numeric|min:0|max:9999999999',
            'start' => 'sometimes|boolean',
        ], [
            'year.required' => 'Choose the year.',
            'lines.*.amount.min' => 'Amounts can\'t be negative.',
        ]);
    }

    private function formResponse(array $place, int $year, ?int $month, ?Budget $budget = null): JsonResponse
    {
        $lines = $this->book->linesFor($place['type'], $place['id']);
        // A line the budget already has stays on the form even if it was switched off since.
        if ($budget) {
            $missing = $budget->budgetLineItems->pluck('budget_line_id')->diff($lines->pluck('id'));
            if ($missing->isNotEmpty()) {
                $lines = $lines->concat(\App\Models\BudgetLine::withTrashed()->with('budgetCategory')->whereIn('id', $missing)->get());
            }
        }
        $shape = fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'description' => $l->description,
            'is_own' => $l->territory_id !== null,
        ];
        $previous = $this->book->previous($place['type'], $place['id'], $year, $month);

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => [
                'place' => $this->placeInfo($place),
                'year' => $year,
                'month' => $month,
                'lines' => [
                    'in' => $lines->filter(fn ($l) => $l->budgetCategory?->slug === 'income')->map($shape)->values(),
                    'out' => $lines->filter(fn ($l) => $l->budgetCategory?->slug === 'expense')->map($shape)->values(),
                ],
                'taken' => $this->book->takenPeriods($place['type'], $place['id'], $year, $budget?->id),
                'copy' => $previous ? [
                    'budget_id' => $previous->id,
                    'period_label' => $previous->period_label,
                    'amounts' => $previous->budgetLineItems->mapWithKeys(fn ($i) => [$i->budget_line_id => (float) $i->budgeted_amount])->filter(),
                ] : null,
                'budget' => $budget ? [
                    'id' => $budget->id,
                    'status' => $budget->status,
                    'period_label' => $budget->period_label,
                    'notes' => $budget->description,
                    'updated_at' => $budget->updated_at?->toIso8601String(),
                    'amounts' => $budget->budgetLineItems->mapWithKeys(fn ($i) => [$i->budget_line_id => (float) $i->budgeted_amount])->filter(),
                ] : null,
            ],
        ]);
    }

    private function summaryOf(Budget $b): array
    {
        $name = fn ($u) => $u ? trim("{$u->firstname} {$u->lastname}") : null;

        return [
            'id' => $b->id,
            'period_label' => $b->period_label,
            'fiscal_year' => (int) $b->fiscal_year,
            'period_month' => $b->period_month,
            'status' => $b->status,
            'status_label' => $b->status_label,
            'in_planned' => (float) $b->total_income_budgeted,
            'out_planned' => (float) $b->total_expense_budgeted,
            'in_actual' => (float) $b->total_income_actual,
            'out_actual' => (float) $b->total_expense_actual,
            'left_planned' => round((float) $b->total_income_budgeted - (float) $b->total_expense_budgeted, 2),
            'prepared_by' => $name($b->creator),
            'started_by' => $name($b->starter),
            'started_at' => $b->started_at?->toIso8601String(),
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }

    private function detailOf(Budget $budget, Request $request): array
    {
        $budget->loadMissing(['budgetLineItems.budgetLine', 'budgetLineItems.budgetCategory', 'creator:id,firstname,lastname', 'starter:id,firstname,lastname', 'closer:id,firstname,lastname', 'updater:id,firstname,lastname']);
        $user = $request->user();
        $own = BudgetAccess::canWrite($user, $budget);
        $row = function ($item) {
            $planned = (float) $item->budgeted_amount;
            $actual = (float) $item->actual_amount;

            return [
                'item_id' => $item->id,
                'line_id' => $item->budget_line_id,
                'name' => $item->budgetLine?->name ?? 'Line '.$item->budget_line_id,
                'is_own' => $item->budgetLine?->territory_id !== null,
                'planned' => $planned,
                'actual' => $actual,
                'left' => round($planned - $actual, 2),
                'pct' => $planned > 0 ? round($actual / $planned * 100, 1) : null,
            ];
        };
        $items = $budget->budgetLineItems->sortBy(fn ($i) => $i->budgetLine?->display_order ?? 0);
        $name = fn ($u) => $u ? trim("{$u->firstname} {$u->lastname}") : null;

        return [
            'budget' => [
                ...$this->summaryOf($budget),
                'start_date' => $budget->start_date?->toDateString(),
                'end_date' => $budget->end_date?->toDateString(),
                'notes' => $budget->description,
                'created_at' => $budget->created_at?->toIso8601String(),
                'closed_at' => $budget->closed_at?->toIso8601String(),
                'closed_by' => $name($budget->closer),
                'updated_by' => $name($budget->updater),
                'place' => $this->placeInfo(['type' => $budget->territory_type, 'id' => (int) $budget->territory_id]),
            ],
            'lines' => [
                'in' => $items->filter(fn ($i) => $i->budgetCategory?->slug === 'income')->map($row)->values(),
                'out' => $items->filter(fn ($i) => $i->budgetCategory?->slug === 'expense')->map($row)->values(),
            ],
            'view_only' => ! $own,
            'can' => [
                'edit' => $own && $budget->is_editable,
                'start' => $own && $budget->status === 'draft',
                'close' => $own && $budget->status === 'active',
                'reopen' => $own && $budget->status === 'closed',
                'delete' => $own && $budget->status === 'draft',
            ],
        ];
    }

    private function placeInfo(array $place): array
    {
        return [...$place, 'name' => \App\Models\Territory::find($place['id'])?->name];
    }

    private function moved(Budget $budget, Request $request, string $message): JsonResponse
    {
        return response()->json(['success' => true, 'status' => 200, 'message' => $message, 'data' => $this->detailOf($budget->fresh(), $request)]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Older actions - replaced by the spending and deductions phases, then removed
    |--------------------------------------------------------------------------
    */

    /**
     * Get all line items for a budget
     */
    public function getLineItems(Budget $budget)
    {
        try {
            $lineItems = $budget->budgetLineItems()
                ->with(['budgetLine', 'budgetCategory'])
                ->orderBy('budget_category_id')
                ->orderBy('id')
                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line items retrieved successfully',
                'data' => $lineItems,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve line items: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve line items',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a budget line item
     */
    public function updateLineItem(Request $request, Budget $budget, BudgetLineItem $lineItem)
    {
        if ($lineItem->budget_id !== $budget->id) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That line is not part of this budget.'], 404);
        }

        return $this->updateLooseLineItem($request, $lineItem);
    }

    /**
     * Update a budget line item by its id alone (PUT /budgets/line-items/{lineItem})
     */
    public function updateLooseLineItem(Request $request, BudgetLineItem $lineItem)
    {
        try {
            // Check if editable
            if (! $lineItem->is_editable) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Cannot edit locked or non-editable line item.',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'budgeted_amount' => 'sometimes|required|numeric|min:0',
                'actual_amount' => 'sometimes|required|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();
            $data['updated_by'] = auth()->id();

            $lineItem->update($data);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Line item updated successfully',
                'data' => $lineItem->fresh(['budgetLine', 'budgetCategory']),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update line item: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update line item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a line to a budget that can still be edited
     */
    public function addLineItem(Request $request, Budget $budget)
    {
        if (! $budget->is_editable) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => "Cannot change a budget with status '{$budget->status}'.",
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'budget_line_id' => 'required|exists:budget_lines,id',
            'budgeted_amount' => 'required|numeric|min:0',
            'actual_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($error = $this->unusableLineError([$request->budget_line_id], $budget->territory_type, (int) $budget->territory_id)) {
            return $error;
        }
        if ($budget->budgetLineItems()->where('budget_line_id', $request->budget_line_id)->exists()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'That line is already in this budget - change its amount instead.',
                'errors' => ['budget_line_id' => ['Already in this budget.']],
            ], 422);
        }

        $line = \App\Models\BudgetLine::findOrFail($request->budget_line_id);
        $item = $budget->budgetLineItems()->create([
            'budget_line_id' => $line->id,
            'budget_category_id' => $line->budget_category_id,
            'budgeted_amount' => $request->budgeted_amount,
            'actual_amount' => $request->actual_amount ?? 0,
            'notes' => $request->notes,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'status' => 201,
            'message' => 'Line added to the budget',
            'data' => $item->fresh(['budgetLine', 'budgetCategory']),
        ], 201);
    }

    /**
     * Remove a line from a budget that can still be edited
     */
    public function deleteLineItem(Budget $budget, BudgetLineItem $lineItem)
    {
        if ($lineItem->budget_id !== $budget->id) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That line is not part of this budget.'], 404);
        }
        if (! $lineItem->is_editable) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cannot remove a locked line, or a line of a budget that can no longer be edited.',
            ], 400);
        }

        $lineItem->delete();

        return response()->json(['success' => true, 'status' => 200, 'message' => 'Line removed from the budget', 'data' => null]);
    }

    /**
     * A 422 when any of the lines can't be used by a budget of this territory:
     * a church budget may use the shared church lines and its own, any other
     * budget only shared lines.
     */
    private function unusableLineError(array $lineIds, string $territoryType, int $territoryId): ?\Illuminate\Http\JsonResponse
    {
        $lineIds = array_unique(array_filter($lineIds));
        if (! $lineIds) {
            return null;
        }
        $query = \App\Models\BudgetLine::whereIn('id', $lineIds);
        $usable = $query->forPlace($territoryType, $territoryId)->count();
        if ($usable === count($lineIds)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'status' => 422,
            'message' => 'One or more budget lines belong to another church and cannot be used here.',
            'errors' => ['items' => ['Choose lines shared by the diocese or your own church\'s lines.']],
        ], 422);
    }

    /**
     * Get audit trail for a budget
     */
    public function getAudits(Budget $budget)
    {
        try {
            $audits = $budget->audits()
                ->with('user:id,firstname,lastname,email')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($audit) {
                    return [
                        'id' => $audit->id,
                        'event' => $audit->event,
                        'user' => $audit->user ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->firstname.' '.$audit->user->lastname,
                            'email' => $audit->user->email,
                        ] : null,
                        'old_values' => $audit->old_values,
                        'new_values' => $audit->new_values,
                        'ip_address' => $audit->ip_address,
                        'user_agent' => $audit->user_agent,
                        'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                        'created_at_human' => $audit->created_at->diffForHumans(),
                    ];
                });

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Audit trail retrieved successfully',
                'data' => $audits,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve audit trail: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve audit trail',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get budget summary
     */
    public function getSummary(Budget $budget)
    {
        try {
            $summary = [
                'budget_id' => $budget->id,
                'budget_name' => $budget->name,
                'fiscal_year' => $budget->fiscal_year,
                'status' => $budget->status,

                // Income
                'total_income_budgeted' => $budget->total_income_budgeted,
                'total_income_actual' => $budget->total_income_actual,
                'income_variance' => $budget->total_income_actual - $budget->total_income_budgeted,

                // Expense
                'total_expense_budgeted' => $budget->total_expense_budgeted,
                'total_expense_actual' => $budget->total_expense_actual,
                'expense_variance' => $budget->total_expense_actual - $budget->total_expense_budgeted,

                // Overall
                'budgeted_balance' => $budget->budgeted_balance,
                'actual_balance' => $budget->actual_balance,
                'variance_percentage' => $budget->variance_percentage,

                // Line items count
                'total_line_items' => $budget->budgetLineItems()->count(),
                'income_line_items' => $budget->budgetLineItems()->income()->count(),
                'expense_line_items' => $budget->budgetLineItems()->expense()->count(),
            ];

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget summary retrieved successfully',
                'data' => $summary,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget summary: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all deductions for a budget
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeductions(Budget $budget)
    {
        try {
            $deductions = $budget->budgetDeductionItems()
                ->with(['budgetDeduction', 'creator', 'updater', 'reverser'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deductions retrieved successfully',
                'data' => $deductions,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget deductions: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget deductions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply a deduction to a budget
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyDeduction(Request $request, Budget $budget)
    {
        try {
            $validator = Validator::make($request->all(), [
                'budget_deduction_id' => 'required|exists:budget_deductions,id',
                'deduction_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $deduction = BudgetDeduction::findOrFail($request->budget_deduction_id);

            // Calculate deduction amount if not provided
            $amount = $request->deduction_amount;
            if (! $amount) {
                // Determine base amount
                $baseAmount = match ($deduction->applies_to) {
                    'income' => $budget->total_income_budgeted,
                    'expense' => $budget->total_expense_budgeted,
                    'both' => $budget->total_income_budgeted + $budget->total_expense_budgeted,
                };
                $amount = $deduction->calculateDeduction($baseAmount);
            }

            // Apply deduction
            $deductionItem = $budget->applyDeduction(
                $request->budget_deduction_id,
                $amount,
                $request->notes
            );

            $deductionItem->load(['budgetDeduction', 'creator']);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Deduction applied successfully',
                'data' => $deductionItem,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to apply deduction: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to apply deduction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reverse a deduction
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reverseDeduction(Request $request, Budget $budget, BudgetDeductionItem $deductionItem)
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Verify deduction belongs to this budget
            if ($deductionItem->budget_id !== $budget->id) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'This deduction does not belong to the specified budget',
                ], 400);
            }

            $budget->reverseDeduction($deductionItem->id, $request->reason);
            $deductionItem->refresh();
            $deductionItem->load(['budgetDeduction', 'reverser']);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Deduction reversed successfully',
                'data' => $deductionItem,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to reverse deduction: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to reverse deduction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate budget deductions
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function recalculateDeductions(Budget $budget)
    {
        try {
            $budget->recalculateDeductions();
            $budget->refresh();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deductions recalculated successfully',
                'data' => [
                    'total_deductions' => $budget->total_deductions,
                    'net_income_budgeted' => $budget->net_income_budgeted,
                    'net_income_actual' => $budget->net_income_actual,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to recalculate budget deductions: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to recalculate budget deductions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
