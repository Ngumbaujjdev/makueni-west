<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Budget types, read-only - the Demographics tracking page uses them to find
 * the monthly periods (budgets themselves are a month or a year,
 * docs/specs/budgets-spec.md).
 */
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
                'data' => $budgetTypes,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget types: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget types',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
