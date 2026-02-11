<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetLog;
use Illuminate\Http\Request;

class BudgetLogController extends Controller
{
    /**
     * Get all logs for a specific budget
     */
    public function index(Request $request, int $budgetId)
    {
        try {
            $query = BudgetLog::byBudget($budgetId)
                ->with(['performer:id,firstname,lastname,email']);

            // Filter by action
            if ($request->has('action')) {
                $query->byAction($request->action);
            }

            // Filter reversals
            if ($request->has('reversals_only') && $request->reversals_only == 'true') {
                $query->reversals();
            } elseif ($request->has('exclude_reversals') && $request->exclude_reversals == 'true') {
                $query->nonReversals();
            }

            $logs = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget logs retrieved successfully',
                'data' => $logs
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific log entry
     */
    public function show(int $budgetId, int $logId)
    {
        try {
            $log = BudgetLog::byBudget($budgetId)
                ->with(['performer:id,firstname,lastname,email', 'reversalOf', 'reversals'])
                ->findOrFail($logId);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget log retrieved successfully',
                'data' => $log
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget log',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent logs across all budgets
     */
    public function recent(Request $request)
    {
        try {
            $limit = $request->get('limit', 20);
            
            $logs = BudgetLog::with(['budget:id,name', 'performer:id,firstname,lastname,email'])
                ->recent($limit)
                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Recent budget logs retrieved successfully',
                'data' => $logs
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve recent logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
