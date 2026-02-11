<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetPeriodController extends Controller
{
    /**
     * List budget periods with optional filters
     * 
     * Query Parameters:
     * - fiscal_year_id: Filter by fiscal year (required for meaningful results)
     * - budget_type_id: Filter by budget type (monthly, quarterly, etc.)
     * - is_active: Filter by active status
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = BudgetPeriod::with(['budgetType', 'fiscalYear', 'fiscalMonth', 'fiscalQuarter', 'fiscalSemiAnnual']);

            // Filter by fiscal year (recommended)
            if ($request->has('fiscal_year_id')) {
                $query->where('fiscal_year_id', $request->input('fiscal_year_id'));
            }

            // Filter by budget type (monthly, quarterly, semi-annual, yearly)
            if ($request->has('budget_type_id')) {
                $query->where('budget_type_id', $request->input('budget_type_id'));
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Order by start date
            $periods = $query->orderBy('start_date', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => $periods,
                'message' => 'Budget periods retrieved successfully',
                'count' => $periods->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve budget periods: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single budget period with all related data
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $period = BudgetPeriod::with(['budgetType', 'fiscalYear', 'fiscalMonth', 'fiscalQuarter', 'fiscalSemiAnnual'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $period,
                'message' => 'Budget period retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Budget period not found'
            ], 404);
        }
    }

    /**
     * Get budget periods grouped by type for a specific year
     * Useful for populating dropdowns
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function byYear(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'fiscal_year_id' => 'required|integer|exists:fiscal_years,id'
            ]);

            $periods = BudgetPeriod::with(['budgetType'])
                ->where('fiscal_year_id', $request->input('fiscal_year_id'))
                ->where('is_active', true)
                ->orderBy('budget_type_id')
                ->orderBy('start_date')
                ->get()
                ->groupBy(function ($period) {
                    return $period->budgetType->slug ?? 'unknown';
                });

            return response()->json([
                'success' => true,
                'data' => $periods,
                'message' => 'Budget periods grouped by type'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve budget periods: ' . $e->getMessage()
            ], 500);
        }
    }
}
