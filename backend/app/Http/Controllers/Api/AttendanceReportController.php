<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceReportExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Territory;
use App\Services\AttendanceReportWidgetService;
use App\Services\Pdf\AttendanceSummaryPdfReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

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
     * offers the 3 real categories, no combined report. gathering_type_id
     * is an optional further drill-down to one configured type.
     */
    public function exportPdf(Request $request)
    {
        $context = $this->resolveExportContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$church, $year, $month, $category, $gatheringType] = $context;

        $widgets = $this->widgets->widgetsFor($church->id, $category, $year, $month, $gatheringType?->id);
        $reportId = $this->buildReportId($year, $month);

        $pdf = (new AttendanceSummaryPdfReport($church->name, $category, $year, $month, $widgets, $reportId, $gatheringType))->build();
        $filename = $reportId.'.pdf';

        return response($pdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Excel counterpart to exportPdf() - same widgetsFor() output and the
     * same request contract (including the gathering_type_id drill-down),
     * just tabular instead of drawn.
     */
    public function exportExcel(Request $request)
    {
        $context = $this->resolveExportContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$church, $year, $month, $category, $gatheringType] = $context;

        $widgets = $this->widgets->widgetsFor($church->id, $category, $year, $month, $gatheringType?->id);
        $reportId = $this->buildReportId($year, $month);

        return Excel::download(new AttendanceReportExport($category, $widgets), $reportId.'.xlsx');
    }

    private function buildReportId(FiscalYear $year, ?FiscalMonth $month): string
    {
        return sprintf('ATTREPORT-%d-%s-%s', $year->year, $month ? $month->short_name : 'FY', now()->format('YmdHis'));
    }

    /**
     * Shared validation + lookup for exportPdf()/exportExcel() - territory
     * ownership, the fiscal year/month/category/type params, and (matching
     * AttendanceController's own convention for this exact check) rejecting
     * a gathering_type_id that belongs to a different church.
     *
     * @return JsonResponse|array{0: Territory, 1: FiscalYear, 2: ?FiscalMonth, 3: GatheringCategory, 4: ?GatheringType}
     */
    private function resolveExportContext(Request $request): JsonResponse|array
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
            'gathering_type_id' => 'nullable|exists:gathering_types,id',
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

        $gatheringType = null;
        if ($request->filled('gathering_type_id')) {
            $gatheringType = GatheringType::find($request->query('gathering_type_id'));

            if (! $gatheringType || (int) $gatheringType->territory_id !== $territoryId) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'The selected gathering type does not belong to this church.',
                    'errors' => ['gathering_type_id' => ['Invalid gathering type for this church.']],
                ], 422);
            }
        }

        return [$church, $year, $month, $category, $gatheringType];
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
