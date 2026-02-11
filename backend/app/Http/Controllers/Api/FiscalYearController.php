<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    /**
     * List all fiscal years
     * 
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FiscalYear::query();

            // Optional: Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Order by year descending (most recent first)
            $years = $query->orderBy('year', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $years,
                'message' => 'Fiscal years retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve fiscal years: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single fiscal year with its related data
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $fiscalYear = FiscalYear::with(['quarters', 'semiAnnuals'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $fiscalYear,
                'message' => 'Fiscal year retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fiscal year not found'
            ], 404);
        }
    }
}
