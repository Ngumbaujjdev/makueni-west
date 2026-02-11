<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BudgetCategoryController extends Controller
{
    /**
     * Display a listing of budget categories.
     */
    public function index(Request $request)
    {
        try {
            $query = BudgetCategory::query();

            // Include budget lines count
            $query->withCount('budgetLines');

            // Filter by active status
            if ($request->has('include_inactive') && $request->include_inactive == 'false') {
                $query->active();
            }

            $budgetCategories = $query->orderBy('name', 'asc')->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget categories retrieved successfully',
                'data' => $budgetCategories
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget categories: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created budget category.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:budget_categories,name',
                'slug' => 'nullable|string|max:255|unique:budget_categories,slug',
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
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

            // Set created_by to authenticated user
            $data['created_by'] = auth()->id();

            $budgetCategory = BudgetCategory::create($data);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget category created successfully',
                'data' => $budgetCategory
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget category: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified budget category with its lines.
     */
    public function show(BudgetCategory $budgetCategory)
    {
        try {
            $budgetCategory->load(['budgetLines' => function ($query) {
                $query->active()->orderBy('display_order', 'asc');
            }]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget category retrieved successfully',
                'data' => $budgetCategory
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget category: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified budget category.
     */
    public function update(Request $request, BudgetCategory $budgetCategory)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255|unique:budget_categories,name,' . $budgetCategory->id,
                'slug' => 'nullable|string|max:255|unique:budget_categories,slug,' . $budgetCategory->id,
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
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

            $budgetCategory->update($data);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget category updated successfully',
                'data' => $budgetCategory->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget category: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail for a specific budget category.
     */
    public function getAudits(BudgetCategory $budgetCategory)
    {
        try {
            $audits = $budgetCategory->audits()
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
     * Remove the specified budget category.
     */
    public function destroy(BudgetCategory $budgetCategory)
    {
        try {
            // Check if category has any budget lines
            $linesCount = $budgetCategory->budgetLines()->count();

            if ($linesCount > 0) {
                // Cascade delete: Delete all budget lines associated with this category
                $budgetCategory->budgetLines()->delete();

                Log::info("Cascade deleted {$linesCount} budget line(s) for category: {$budgetCategory->name}");
            }

            $budgetCategory->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => $linesCount > 0
                    ? "Budget category and {$linesCount} associated budget line(s) deleted successfully"
                    : 'Budget category deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget category: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget category',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
