<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BudgetTypeController extends Controller
{
    /**
     * Display a listing of budget types.
     */
    public function index(Request $request)
    {
        try {
            $query = BudgetType::query();

            // Filter by active status
            if ($request->has('include_inactive') && $request->include_inactive == 'false') {
                $query->active();
            }

            // Order by display order
            $budgetTypes = $query->orderBy('duration_months', 'asc')->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget types retrieved successfully',
                'data' => $budgetTypes
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget types: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created budget type.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:budget_types,name',
                'slug' => 'nullable|string|max:255|unique:budget_types,slug',
                'duration_months' => 'nullable|integer|min:1',
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

            $budgetType = BudgetType::create($data);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget type created successfully',
                'data' => $budgetType
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified budget type.
     */
    public function show(BudgetType $budgetType)
    {
        try {
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget type retrieved successfully',
                'data' => $budgetType
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified budget type.
     */
    public function update(Request $request, BudgetType $budgetType)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255|unique:budget_types,name,' . $budgetType->id,
                'slug' => 'nullable|string|max:255|unique:budget_types,slug,' . $budgetType->id,
                'duration_months' => 'nullable|integer|min:1',
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

            $budgetType->update($data);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget type updated successfully',
                'data' => $budgetType->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail for a specific budget type.
     */
    public function getAudits(BudgetType $budgetType)
    {
        try {
            $audits = $budgetType->audits()
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
     * Remove the specified budget type.
     */
    public function destroy(BudgetType $budgetType)
    {
        try {
            // TODO: Uncomment this check when Budget model is created
            // Check if budget type is being used by any budgets
            // $budgetsCount = $budgetType->budgets()->count();

            // if ($budgetsCount > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'status' => 400,
            //         'message' => "Cannot delete budget type. It is being used by {$budgetsCount} budget(s). Please delete or reassign budgets first.",
            //         'data' => null
            //     ], 400);
            // }

            $budgetType->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget type deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget type',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
