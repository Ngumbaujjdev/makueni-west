<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatheringCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GatheringCategoryController extends Controller
{
    /**
     * Read-only list of the global gathering categories (Sunday Service,
     * Ministry Gathering, Special Event). Any authenticated user can read
     * these - they're structural lookup data, not sensitive, and every
     * attendance-entry page needs them to resolve which category to submit
     * against.
     */
    public function index(Request $request)
    {
        try {
            $query = GatheringCategory::query();

            if ($request->filled('include_inactive') && $request->query('include_inactive') === 'false') {
                $query->active();
            } elseif (! $request->filled('include_inactive')) {
                $query->active();
            }

            $categories = $query->orderBy('display_order')->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Gathering categories retrieved successfully',
                'data' => $categories,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve gathering categories: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve gathering categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
