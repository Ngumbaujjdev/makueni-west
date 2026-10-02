<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Budget periods, read-only - the Demographics tracking page uses them to
 * find a year's fiscal months (budgets themselves are a month or a year,
 * docs/specs/budgets-spec.md).
 */
class BudgetPeriodController extends Controller
{
    /**
     * List budget periods with optional filters
     *
     * Query Parameters:
     * - fiscal_year_id: Filter by fiscal year (required for meaningful results)
     * - budget_type_id: Filter by budget type (monthly, quarterly, etc.)
     * - is_active: Filter by active status
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
                'count' => $periods->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve budget periods: '.$e->getMessage(),
            ], 500);
        }
    }
}
