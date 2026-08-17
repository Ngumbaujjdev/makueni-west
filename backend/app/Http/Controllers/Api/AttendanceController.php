<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    /**
     * service_type => permission-name-prefix, matching the permission tree
     * already seeded under Module 28 "Attendance" (see
     * FixDemographicsPermissionScaffoldingSeeder).
     */
    private const PERMISSION_PREFIXES = [
        'sunday_service' => 'attendancemanagement.serviceattendance',
        'special_event' => 'attendancemanagement.specialeventsattendance',
        'ministry_gathering' => 'attendancemanagement.ministryattendance',
    ];

    /**
     * List this church's attendance records (own church only), optionally
     * filtered by service_type and period.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (!$territoryId || !$this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance records.',
            ], 403);
        }

        $query = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $territoryId);

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->query('service_type'));
        }

        if ($request->filled('fiscal_year_id') && $request->filled('fiscal_month_id')) {
            $query->forPeriod((int) $request->query('fiscal_year_id'), (int) $request->query('fiscal_month_id'));
        }

        $records = $query->orderByDesc('service_date')->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance records retrieved successfully',
            'data' => $records,
        ]);
    }

    /**
     * Record one service/event/gathering's attendance for the caller's own
     * church. No approval workflow - attendance is high-frequency, low-stakes
     * data by design (see the module plan's Workflow section).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'territory_id' => 'required|integer',
            'service_date' => 'required|date',
            'service_type' => 'required|in:sunday_service,special_event,ministry_gathering',
            'event_name' => 'required_unless:service_type,sunday_service|nullable|string|max:255',
            'adults_count' => 'nullable|integer|min:0',
            'youth_count' => 'nullable|integer|min:0',
            'children_male_count' => 'nullable|integer|min:0',
            'children_female_count' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $permission = self::PERMISSION_PREFIXES[$data['service_type']] . '.create';
        if (!$user->can($permission)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to record this type of attendance.',
            ], 403);
        }

        if (!$this->userOwnsChurch($user, (int) $data['territory_id'])) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only record attendance for your own church.',
            ], 403);
        }

        $fiscalYear = FiscalYear::where('year', date('Y', strtotime($data['service_date'])))->first();
        $fiscalMonth = FiscalMonth::where('number', date('n', strtotime($data['service_date'])))->first();

        if (!$fiscalYear || !$fiscalMonth) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'No fiscal year/month is configured for this service date.',
            ], 422);
        }

        try {
            $data['territory_type'] = 'church';
            $data['fiscal_year_id'] = $fiscalYear->id;
            $data['fiscal_month_id'] = $fiscalMonth->id;
            $data['created_by'] = $user->id;
            $data['updated_by'] = $user->id;

            $record = ChurchAttendanceRecord::create($data);
            $record->load(['fiscalYear', 'fiscalMonth']);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Attendance recorded successfully',
                'data' => $record,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to record attendance: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to record attendance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, ChurchAttendanceRecord $attendance)
    {
        $user = $request->user();

        $permission = self::PERMISSION_PREFIXES[$attendance->service_type] . '.update';
        if (!$user->can($permission)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to update this type of attendance.',
            ], 403);
        }

        if (!$this->userOwnsChurch($user, $attendance->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only update attendance for your own church.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'event_name' => 'nullable|string|max:255',
            'adults_count' => 'nullable|integer|min:0',
            'youth_count' => 'nullable|integer|min:0',
            'children_male_count' => 'nullable|integer|min:0',
            'children_female_count' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['updated_by'] = $user->id;

        $attendance->update($data);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance updated successfully',
            'data' => $attendance,
        ]);
    }

    /**
     * Same ownership check as DemographicsController - deliberately
     * stricter than the Budget reference controller, which does no
     * server-side territory enforcement at all.
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
