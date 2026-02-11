<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BudgetDeductionController extends Controller
{
    /**
     * Display a listing of budget deductions
     */
    public function index(Request $request)
    {
        try {
            $query = BudgetDeduction::query();

            // Filter by territory scope
            if ($request->has('territory_scope')) {
                $query->byTerritoryScope($request->territory_scope);
            }

            // Filter by applies_to
            if ($request->has('applies_to')) {
                $query->byAppliesTo($request->applies_to);
            }

            // Filter by mandatory
            if ($request->has('mandatory_only') && $request->mandatory_only == 'true') {
                $query->mandatory();
            }

            // Filter by active status
            if ($request->has('include_inactive') && $request->include_inactive == 'false') {
                $query->active();
            }

            $deductions = $query->orderBy('display_order', 'asc')->get();

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
     * Store a newly created budget deduction
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budget_deductions,slug',
                'description' => 'nullable|string|max:1000',
                'deduction_type' => 'required|in:percentage,fixed_amount',
                'deduction_value' => 'required|numeric|min:0',
                'applies_to' => 'required|in:income,expense,both',
                'territory_scope' => 'required|in:diocese,region,subregion,church,all',
                'is_mandatory' => 'nullable|boolean',
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

            // Set created_by
            $data['created_by'] = auth()->id();

            // Auto-generate display_order if not provided
            if (!isset($data['display_order'])) {
                $maxOrder = BudgetDeduction::max('display_order');
                $data['display_order'] = $maxOrder ? $maxOrder + 1 : 1;
            }

            $deduction = BudgetDeduction::create($data);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget deduction created successfully',
                'data' => $deduction
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified budget deduction
     */
    public function show(BudgetDeduction $budgetDeduction)
    {
        try {
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deduction retrieved successfully',
                'data' => $budgetDeduction
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified budget deduction
     */
    public function update(Request $request, BudgetDeduction $budgetDeduction)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:budget_deductions,slug,' . $budgetDeduction->id,
                'description' => 'nullable|string|max:1000',
                'deduction_type' => 'sometimes|required|in:percentage,fixed_amount',
                'deduction_value' => 'sometimes|required|numeric|min:0',
                'applies_to' => 'sometimes|required|in:income,expense,both',
                'territory_scope' => 'sometimes|required|in:diocese,region,subregion,church,all',
                'is_mandatory' => 'nullable|boolean',
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

            // Set updated_by
            $data['updated_by'] = auth()->id();

            $budgetDeduction->update($data);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deduction updated successfully',
                'data' => $budgetDeduction->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update display order
     */
    public function updateOrder(Request $request, BudgetDeduction $budgetDeduction)
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

            $budgetDeduction->update([
                'display_order' => $request->display_order,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Display order updated successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update display order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update display order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified budget deduction
     */
    public function destroy(BudgetDeduction $budgetDeduction)
    {
        try {
            // Check if deduction is being used
            $usageCount = $budgetDeduction->budgetDeductionItems()->count();
            if ($usageCount > 0) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot delete deduction. It is being used in {$usageCount} budget(s).",
                    'data' => null
                ], 400);
            }

            $budgetDeduction->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget deduction deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget deduction: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget deduction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail for a specific deduction
     */
    public function getAudits(BudgetDeduction $budgetDeduction)
    {
        try {
            $audits = $budgetDeduction->audits()
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
}
