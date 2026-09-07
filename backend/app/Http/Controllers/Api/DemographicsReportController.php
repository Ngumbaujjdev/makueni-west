<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Services\DemographicsReportWidgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Backs the Spiritual Activities and Monthly Statistics report pages -
 * separate from the CRUD DemographicsController, same split
 * AttendanceReportController already draws from AttendanceController.
 */
class DemographicsReportController extends Controller
{
    public function __construct(private DemographicsReportWidgetService $widgets) {}

    public function widgets(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (! $territoryId || ! $this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s demographics reports.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $year = FiscalYear::findOrFail($request->query('fiscal_year_id'));

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Demographics report widgets retrieved successfully',
            'data' => $this->widgets->widgetsFor($territoryId, $year),
        ]);
    }

    /**
     * Same ownership check as the other controllers - each keeps its own
     * copy rather than sharing a trait, matching this codebase's existing
     * per-controller convention (see AttendanceController, AttendanceReportController,
     * DemographicsController).
     */
    private function userOwnsChurch($user, int $territoryId): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return $user->activeAssignments()
            ->where('territory_id', $territoryId)
            ->exists();
    }
}
