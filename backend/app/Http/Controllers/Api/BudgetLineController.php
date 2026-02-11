<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetLine;
use App\Models\BudgetCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BudgetLineController extends Controller
{
    /**
     * Display a listing of budget lines.
     */
    public function index(Request $request)
    {
        try {
            $query = BudgetLine::with('budgetCategory');

            // Filter by category
            if ($request->has('category_id')) {
                $query->where('budget_category_id', $request->category_id);
            }

            // Filter by territory scope
            if ($request->has('territory_scope')) {
                $query->byTerritoryScope($request->territory_scope);
            }

            // Filter by active status
            if ($request->has('include_inactive') && $request->include_inactive == 'false') {
                $query->active();
            }

            // Filter by system default or user-created
            if ($request->has('system_defaults_only') && $request->system_defaults_only == 'true') {
                $query->systemDefaults();
            }

            // Order by
            $budgetLines = $query->orderBy('budget_category_id', 'asc')
                                ->orderBy('display_order', 'asc')
                                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget lines retrieved successfully',
                'data' => $budgetLines
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget lines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget lines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get budget lines grouped by category.
     */
    public function getGroupedByCategory(Request $request)
    {
        try {
            $territoryScope = $request->get('territory_scope', 'all');

            $categories = BudgetCategory::with(['budgetLines' => function ($query) use ($territoryScope) {
                $query->active()
                      ->byTerritoryScope($territoryScope)
                      ->orderBy('display_order', 'asc');
            }])
            ->active()
            ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget lines grouped by category retrieved successfully',
                'data' => $categories
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve grouped budget lines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve grouped budget lines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created budget line.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'budget_category_id' => 'required|exists:budget_categories,id',
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budget_lines,slug',
                'territory_scope' => 'required|in:diocese,region,subregion,church,all',
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
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

            // Auto-generate slug if not provided
            if (!isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            // Set as user-created (not system default)
            $data['is_system_default'] = false;

            // Set created_by to authenticated user
            $data['created_by'] = auth()->id();

            // Auto-generate display_order if not provided
            if (!isset($data['display_order'])) {
                $maxOrder = BudgetLine::where('budget_category_id', $data['budget_category_id'])->max('display_order');
                $data['display_order'] = $maxOrder ? $maxOrder + 1 : 1;
            }

            $budgetLine = BudgetLine::create($data);
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget line created successfully',
                'data' => $budgetLine
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget line',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified budget line.
     */
    public function show(BudgetLine $budgetLine)
    {
        try {
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line retrieved successfully',
                'data' => $budgetLine
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget line',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified budget line.
     */
    public function update(Request $request, BudgetLine $budgetLine)
    {
        try {
            $validator = Validator::make($request->all(), [
                'budget_category_id' => 'sometimes|required|exists:budget_categories,id',
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budget_lines,slug,' . $budgetLine->id,
                'territory_scope' => 'sometimes|required|in:diocese,region,subregion,church,all',
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
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

            // Auto-generate slug if name is updated but slug is not provided
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            // Set updated_by to authenticated user
            $data['updated_by'] = auth()->id();

            $budgetLine->update($data);
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line updated successfully',
                'data' => $budgetLine->fresh(['budgetCategory'])
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget line',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update display order of budget line.
     */
    public function updateOrder(Request $request, BudgetLine $budgetLine)
    {
        try {
            $validator = Validator::make($request->all(), [
                'display_order' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $budgetLine->update([
                'display_order' => $request->display_order,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line order updated successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget line order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget line order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail for a specific budget line.
     */
    public function getAudits(BudgetLine $budgetLine)
    {
        try {
            $audits = $budgetLine->audits()
                ->with('user:id,firstname,lastname,email')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($audit) {
                    return [
                        'id' => $audit->id,
                        'event' => $audit->event, // created, updated, deleted
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
     * Remove the specified budget line.
     */
    public function destroy(BudgetLine $budgetLine)
    {
        try {
            // Prevent deletion of system default lines
            if ($budgetLine->is_system_default) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Cannot delete system default budget lines.',
                    'data' => null
                ], 400);
            }

            // TODO: Check if line is being used in any budget line items when that feature is implemented
            // Note: budgetLineItems() relationship references BudgetLineItem model which doesn't exist yet
            // Uncomment this check when budget line items functionality is added
            // $usageCount = $budgetLine->budgetLineItems()->count();
            // if ($usageCount > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'status' => 400,
            //         'message' => "Cannot delete budget line. It is being used in {$usageCount} budget(s). Please remove it from budgets first.",
            //         'data' => null
            //     ], 400);
            // }

            $budgetLine->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget line',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
