<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\Territory;
use App\Services\AttendanceReportWidgetService;
use App\Services\Pdf\AttendanceSummaryPdfReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Backs the Attendance Reports tabbed dashboard's stat cards/charts/
 * breakdown tables - separate from the CRUD AttendanceController, same
 * split ifms-core-server draws between its report controllers and its
 * record CRUD controllers.
 */
class AttendanceReportController extends Controller
{
    public function __construct(private AttendanceReportWidgetService $widgets) {}

    /**
     * gathering_category_id omitted = the cross-tab combined summary strip
     * (all 3 categories together); provided = one tab's widgets.
     */
    public function widgets(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (! $territoryId || ! $this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance reports.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'fiscal_month_id' => 'nullable|exists:fiscal_months,id',
            'gathering_category_id' => 'nullable|exists:gathering_categories,id',
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
        $month = $request->filled('fiscal_month_id') ? FiscalMonth::find($request->query('fiscal_month_id')) : null;
        $category = $request->filled('gathering_category_id') ? GatheringCategory::find($request->query('gathering_category_id')) : null;

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance report widgets retrieved successfully',
            'data' => $this->widgets->widgetsFor($territoryId, $category, $year, $month),
        ]);
    }

    /**
     * Branded PDF version of one category's widgets tab - same territory-
     * ownership gate as widgets() above (this API doesn't enforce Spatie
     * permissions server-side anywhere else either; the two-layer PHP-page
     * + JS-sidebar/tab gate on the frontend is this app's existing
     * convention, not something this one endpoint should diverge from).
     * gathering_category_id is required here (unlike widgets(), where
     * omitting it means "combined summary") - the PDF report picker only
     * offers the 3 real categories, no combined report.
     */
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (! $territoryId || ! $this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance reports.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'fiscal_month_id' => 'nullable|exists:fiscal_months,id',
            'gathering_category_id' => 'required|exists:gathering_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $church = Territory::findOrFail($territoryId);
        $year = FiscalYear::findOrFail($request->query('fiscal_year_id'));
        $month = $request->filled('fiscal_month_id') ? FiscalMonth::find($request->query('fiscal_month_id')) : null;
        $category = GatheringCategory::findOrFail($request->query('gathering_category_id'));

        $widgets = $this->widgets->widgetsFor($territoryId, $category, $year, $month);

        $reportId = sprintf(
            'ATTREPORT-%d-%s-%s',
            $year->year,
            $month ? $month->short_name : 'FY',
            now()->format('YmdHis')
        );

        $pdf = (new AttendanceSummaryPdfReport($church->name, $category, $year, $month, $widgets, $reportId))->build();
        $filename = $reportId.'.pdf';

        return response($pdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Same ownership check as AttendanceController - each controller keeps
     * its own copy rather than sharing a trait, matching this codebase's
     * existing per-controller convention (see AttendanceController,
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
