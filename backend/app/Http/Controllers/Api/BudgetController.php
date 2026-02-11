<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetLineItem;
use App\Models\BudgetDeduction;
use App\Models\BudgetDeductionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Exports\BudgetsExport;
use Maatwebsite\Excel\Facades\Excel;

class BudgetController extends Controller
{
    /**
     * Display a listing of budgets (Territory-Agnostic)
     */
    public function index(Request $request)
    {
        try {
            $query = Budget::with(['budgetType', 'creator', 'approver', 'budgetLineItems.budgetLine.budgetCategory']);

            // Filter by territory type
            if ($request->has('territory_type')) {
                $query->byTerritoryType($request->territory_type);
            }

            // Filter by territory ID
            if ($request->has('territory_id')) {
                $query->byTerritoryId($request->territory_id);
            }

            // Filter by fiscal year
            if ($request->has('fiscal_year')) {
                $query->byFiscalYear($request->fiscal_year);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Filter by budget type
            if ($request->has('budget_type_id')) {
                $query->where('budget_type_id', $request->budget_type_id);
            }

            // Search by name
            if ($request->has('search') && !empty($request->search)) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            // Order by
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            $query->orderBy($orderBy, $orderDir);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $budgets = $query->paginate($perPage);

            // Calculate statistics based on SAME FILTERS as the table
            $statsQuery = Budget::query();

            // Apply same filters as the main query
            if ($request->has('territory_type')) {
                $statsQuery->byTerritoryType($request->territory_type);
            }
            if ($request->has('territory_id')) {
                $statsQuery->byTerritoryId($request->territory_id);
            }
            if ($request->has('fiscal_year')) {
                $statsQuery->byFiscalYear($request->fiscal_year);
            }
            if ($request->has('status')) {
                $statsQuery->byStatus($request->status);
            }
            if ($request->has('budget_type_id')) {
                $statsQuery->where('budget_type_id', $request->budget_type_id);
            }
            if ($request->has('search') && !empty($request->search)) {
                $statsQuery->where('name', 'like', '%' . $request->search . '%');
            }

            // Current stats based on filtered data
            $total = (clone $statsQuery)->count();
            $active = (clone $statsQuery)->where('status', 'active')->count();
            $pendingApproval = (clone $statsQuery)->whereIn('status', ['submitted', 'under_review'])->count();
            $totalIncome = (clone $statsQuery)->sum('total_income_budgeted');
            $totalExpense = (clone $statsQuery)->sum('total_expense_budgeted');
            $totalAmount = $totalIncome + $totalExpense; // Combined income + expense for overall budget volume

            // Calculate trends (this month vs last month) within filtered data
            $currentMonth = now()->startOfMonth();
            $lastMonth = now()->subMonth()->startOfMonth();

            $totalLastMonth = (clone $statsQuery)->whereMonth('created_at', $lastMonth->month)->whereYear('created_at', $lastMonth->year)->count();
            $activeLastMonth = (clone $statsQuery)->where('status', 'active')->whereMonth('updated_at', $lastMonth->month)->whereYear('updated_at', $lastMonth->year)->count();

            // Calculate percentage change
            $totalTrend = $totalLastMonth > 0 ? round((($total - $totalLastMonth) / $totalLastMonth) * 100, 1) : 0;
            $activeTrend = $activeLastMonth > 0 ? round((($active - $activeLastMonth) / $activeLastMonth) * 100, 1) : 0;

            $stats = [
                'total' => $total,
                'total_trend' => $totalTrend,
                'active' => $active,
                'active_trend' => $activeTrend,
                'pending_approval' => $pendingApproval,
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'total_amount' => $totalAmount
            ];

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budgets retrieved successfully',
                'data' => $budgets->items(),
                'meta' => [
                    'current_page' => $budgets->currentPage(),
                    'last_page' => $budgets->lastPage(),
                    'per_page' => $budgets->perPage(),
                    'total' => $budgets->total(),
                    'from' => $budgets->firstItem(),
                    'to' => $budgets->lastItem()
                ],
                'stats' => $stats
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budgets: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budgets',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export budgets to Excel
     */
    public function export(Request $request)
    {
        try {
            $query = Budget::with(['budgetType', 'creator', 'approver', 'status']);

            // Apply same filters as index method
            if ($request->has('territory_type')) {
                $query->byTerritoryType($request->territory_type);
            }

            if ($request->has('territory_id')) {
                $query->byTerritoryId($request->territory_id);
            }

            if ($request->has('fiscal_year')) {
                $query->byFiscalYear($request->fiscal_year);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('budget_type_id')) {
                $query->where('budget_type_id', $request->budget_type_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Order by
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            $query->orderBy($orderBy, $orderDir);

            // Generate filename with timestamp
            $timestamp = now()->format('Y-m-d_His');
            $filename = "budgets_export_{$timestamp}.xlsx";

            // Export to Excel
            return Excel::download(new BudgetsExport($query), $filename);

        } catch (\Exception $e) {
            Log::error('Failed to export budgets: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to export budgets',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created budget
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'budget_type_id' => 'required|exists:budget_types,id',
                'territory_type' => 'required|in:diocese,region,subregion,church',
                'territory_id' => 'required|integer',
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budgets,slug',
                'description' => 'nullable|string',
                'fiscal_year' => 'required|integer|min:2000|max:2100',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'budget_period_id' => 'nullable|exists:budget_periods,id',
                'items' => 'nullable|array',
                'items.*.budget_line_id' => 'required|exists:budget_lines,id',
                'items.*.budgeted_amount' => 'required|numeric|min:0',
                'items.*.actual_amount' => 'nullable|numeric|min:0',
                'items.*.notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $items = $data['items'] ?? [];
            unset($data['items']); // Remove items from budget data

            // Auto-generate unique slug if not provided
            if (!isset($data['slug'])) {
                $baseSlug = Str::slug($data['name']);
                $slug = $baseSlug;
                $counter = 1;

                // Keep incrementing counter until we find a unique slug
                while (Budget::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }

                $data['slug'] = $slug;
            }

            // Set created_by and status
            $data['created_by'] = auth()->id();

            // Use status from request if provided, otherwise default to 'draft'
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }

            // Create the budget
            $budget = Budget::create($data);

            // Create line items if provided
            if (!empty($items)) {
                foreach ($items as $item) {
                    // Get the budget line to fetch its category_id
                    $budgetLine = \App\Models\BudgetLine::find($item['budget_line_id']);

                    if (!$budgetLine) {
                        continue; // Skip if budget line not found
                    }

                    $budget->budgetLineItems()->create([
                        'budget_line_id' => $item['budget_line_id'],
                        'budget_category_id' => $budgetLine->budget_category_id,
                        'budgeted_amount' => $item['budgeted_amount'],
                        'actual_amount' => $item['actual_amount'] ?? 0,
                        'notes' => $item['notes'] ?? null,
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            // Load relationships including line items
            $budget->load(['budgetType', 'creator', 'budgetLineItems.budgetLine']);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget created successfully',
                'data' => $budget
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified budget
     */
    public function show(Budget $budget)
    {
        try {
            $budget->load(['budgetType', 'budgetLineItems.budgetLine', 'budgetLineItems.budgetCategory', 'creator', 'updater', 'approver']);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget retrieved successfully',
                'data' => $budget
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified budget
     */
    public function update(Request $request, Budget $budget)
    {
        try {
            // Check if budget is editable
            if (!$budget->is_editable) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot edit budget with status '{$budget->status}'. Only draft, under_review, or rejected budgets can be edited.",
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'budget_type_id' => 'sometimes|required|exists:budget_types,id',
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budgets,slug,' . $budget->id,
                'description' => 'nullable|string',
                'fiscal_year' => 'sometimes|required|integer|min:2000|max:2100',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'sometimes|required|date|after:start_date',
                'budget_period_id' => 'nullable|exists:budget_periods,id',
            ]);

            // Additional validation for budget_period_id to ensure it matches fiscal year and budget type
            if ($request->has('budget_period_id') && $request->budget_period_id) {
                $budgetPeriod = \App\Models\BudgetPeriod::find($request->budget_period_id);

                if ($budgetPeriod) {
                    $fiscalYearId = $request->has('fiscal_year') ?
                        \App\Models\FiscalYear::where('year', $request->fiscal_year)->value('id') :
                        $budget->budgetType->fiscal_year_id ?? null;

                    $budgetTypeId = $request->budget_type_id ?? $budget->budget_type_id;

                    if ($budgetPeriod->fiscal_year_id != $fiscalYearId) {
                        return response()->json([
                            'success' => false,
                            'status' => 422,
                            'message' => 'Validation error',
                            'errors' => ['budget_period_id' => ['The selected budget period does not belong to the specified fiscal year.']]
                        ], 422);
                    }

                    if ($budgetPeriod->budget_type_id != $budgetTypeId) {
                        return response()->json([
                            'success' => false,
                            'status' => 422,
                            'message' => 'Validation error',
                            'errors' => ['budget_period_id' => ['The selected budget period does not belong to the specified budget type.']]
                        ], 422);
                    }
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Auto-generate slug if name is updated but slug is not provided
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $data['updated_by'] = auth()->id();

            $budget->update($data);
            $budget->load(['budgetType', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget updated successfully',
                'data' => $budget->fresh(['budgetType', 'creator', 'updater'])
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified budget
     */
    public function destroy(Budget $budget)
    {
        try {
            // Only allow deletion of draft or rejected budgets
            if (!in_array($budget->status, ['draft', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot delete budget with status '{$budget->status}'. Only draft or rejected budgets can be deleted.",
                ], 400);
            }

            // Cascade delete will handle budget_line_items
            $budget->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit budget for review (draft → submitted)
     */
    public function submit(Budget $budget)
    {
        try {
            if (!$budget->can_be_submitted) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Budget cannot be submitted. Status must be draft and must have line items.',
                ], 400);
            }

            $budget->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget submitted for review successfully',
                'data' => $budget->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to submit budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to submit budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve budget (submitted/under_review → approved)
     */
    public function approve(Request $request, Budget $budget)
    {
        try {
            if (!$budget->can_be_approved) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot approve budget with status '{$budget->status}'.",
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'approval_notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $budget->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'approval_notes' => $request->approval_notes,
                'updated_by' => auth()->id()
            ]);

            // Lock all line items
            $budget->budgetLineItems()->update(['is_locked' => true]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget approved successfully',
                'data' => $budget->fresh(['approver'])
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to approve budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to approve budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject budget (submitted/under_review → rejected)
     */
    public function reject(Request $request, Budget $budget)
    {
        try {
            if (!in_array($budget->status, ['submitted', 'under_review'])) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot reject budget with status '{$budget->status}'.",
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $budget->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget rejected successfully',
                'data' => $budget->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to reject budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to reject budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate budget (approved → active)
     */
    public function activate(Budget $budget)
    {
        try {
            if (!$budget->can_be_activated) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot activate budget with status '{$budget->status}'. Budget must be approved first.",
                ], 400);
            }

            $budget->update([
                'status' => 'active',
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget activated successfully',
                'data' => $budget->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to activate budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to activate budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close budget (active → closed)
     */
    public function close(Budget $budget)
    {
        try {
            if ($budget->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot close budget with status '{$budget->status}'. Budget must be active.",
                ], 400);
            }

            $budget->update([
                'status' => 'closed',
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget closed successfully',
                'data' => $budget->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to close budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to close budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clone budget to new fiscal year
     */
    public function clone(Request $request, Budget $budget)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fiscal_year' => 'required|integer|min:2000|max:2100',
                'name' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Clone budget
            $newBudget = $budget->replicate();
            $newBudget->fiscal_year = $request->fiscal_year;
            $newBudget->name = $request->name ?? "{$budget->name} ({$request->fiscal_year})";
            $newBudget->slug = Str::slug($newBudget->name);
            $newBudget->status = 'draft';
            $newBudget->total_income_budgeted = 0;
            $newBudget->total_expense_budgeted = 0;
            $newBudget->total_income_actual = 0;
            $newBudget->total_expense_actual = 0;
            $newBudget->submitted_at = null;
            $newBudget->approved_at = null;
            $newBudget->approved_by = null;
            $newBudget->approval_notes = null;
            $newBudget->rejection_reason = null;
            $newBudget->created_by = auth()->id();
            $newBudget->updated_by = null;
            $newBudget->save();

            // Clone line items
            foreach ($budget->budgetLineItems as $lineItem) {
                $newLineItem = $lineItem->replicate();
                $newLineItem->budget_id = $newBudget->id;
                $newLineItem->actual_amount = 0;
                $newLineItem->is_locked = false;
                $newLineItem->created_by = auth()->id();
                $newLineItem->updated_by = null;
                $newLineItem->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget cloned successfully',
                'data' => $newBudget->fresh(['budgetType', 'budgetLineItems'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to clone budget: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to clone budget',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
                'data' => $lineItems
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve line items: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve line items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a budget line item
     */
    public function updateLineItem(Request $request, BudgetLineItem $lineItem)
    {
        try {
            // Check if editable
            if (!$lineItem->is_editable) {
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
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['updated_by'] = auth()->id();

            $lineItem->update($data);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Line item updated successfully',
                'data' => $lineItem->fresh(['budgetLine', 'budgetCategory'])
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update line item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update line item',
                'error' => $e->getMessage()
            ], 500);
        }
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
                            'name' => $audit->user->firstname . ' ' . $audit->user->lastname,
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
                'data' => $audits
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve audit trail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve audit trail',
                'error' => $e->getMessage()
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
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all deductions for a budget
     *
     * @param Budget $budget
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
                'data' => $deductions
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget deductions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget deductions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply a deduction to a budget
     *
     * @param Request $request
     * @param Budget $budget
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
                    'errors' => $validator->errors()
                ], 422);
            }

            $deduction = BudgetDeduction::findOrFail($request->budget_deduction_id);

            // Calculate deduction amount if not provided
            $amount = $request->deduction_amount;
            if (!$amount) {
                // Determine base amount
                $baseAmount = match($deduction->applies_to) {
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
                'data' => $deductionItem
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to apply deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to apply deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reverse a deduction
     *
     * @param Request $request
     * @param Budget $budget
     * @param BudgetDeductionItem $deductionItem
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
                    'errors' => $validator->errors()
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
                'data' => $deductionItem
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to reverse deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to reverse deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate budget deductions
     *
     * @param Budget $budget
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
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to recalculate budget deductions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to recalculate budget deductions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
