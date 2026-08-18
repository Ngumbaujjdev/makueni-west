<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    /**
     * gathering_categories.slug => permission-name-prefix, matching the
     * permission tree already seeded under Module 28 "Attendance" (see
     * FixDemographicsPermissionScaffoldingSeeder). Categories are now real
     * rows in the gathering_categories table rather than a hardcoded DB
     * enum, but the permission scheme itself still keys off these 3 known
     * slugs - a future 4th category needs one more seeder pass to grant its
     * own bucket (see the gathering-types-config plan's "Explicitly out of
     * scope").
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

        if (! $territoryId || ! $this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance records.',
            ], 403);
        }

        $query = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->with(['gatheringCategory', 'gatheringType']);

        if ($request->filled('gathering_category_id')) {
            $query->where('gathering_category_id', (int) $request->query('gathering_category_id'));
        }

        if ($request->filled('gathering_type_id')) {
            $query->where('gathering_type_id', (int) $request->query('gathering_type_id'));
        }

        if ($request->filled('fiscal_year_id') && $request->filled('fiscal_month_id')) {
            $query->forPeriod((int) $request->query('fiscal_year_id'), (int) $request->query('fiscal_month_id'));
        } elseif ($request->filled('fiscal_year_id')) {
            // Whole-year lookup, used by the gathering-type attendance
            // report (Phase E) to bucket records by fiscal month client-side.
            $query->where('fiscal_year_id', (int) $request->query('fiscal_year_id'));
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
            'gathering_category_id' => 'required|exists:gathering_categories,id',
            'gathering_type_id' => 'nullable|exists:gathering_types,id',
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

        $category = GatheringCategory::find($data['gathering_category_id']);
        $permissionPrefix = self::PERMISSION_PREFIXES[$category->slug] ?? null;

        if (! $permissionPrefix || ! $user->can($permissionPrefix.'.create')) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to record this type of attendance.',
            ], 403);
        }

        if (! $this->userOwnsChurch($user, (int) $data['territory_id'])) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only record attendance for your own church.',
            ], 403);
        }

        // "Other" free-text path (gathering_type_id null) still requires a
        // name for non-Sunday categories; a configured type auto-fills it.
        if (! empty($data['gathering_type_id'])) {
            $gatheringType = GatheringType::find($data['gathering_type_id']);

            if (! $gatheringType || (int) $gatheringType->territory_id !== (int) $data['territory_id']) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'The selected gathering type does not belong to this church.',
                    'errors' => ['gathering_type_id' => ['Invalid gathering type for this church.']],
                ], 422);
            }

            $data['event_name'] = $gatheringType->name;
        } elseif (! $category->is_weekly && empty($data['event_name'])) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => ['event_name' => ['An event name is required when no gathering type is selected.']],
            ], 422);
        }

        $fiscalYear = FiscalYear::where('year', date('Y', strtotime($data['service_date'])))->first();
        $fiscalMonth = FiscalMonth::where('number', date('n', strtotime($data['service_date'])))->first();

        if (! $fiscalYear || ! $fiscalMonth) {
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
            $record->load(['fiscalYear', 'fiscalMonth', 'gatheringCategory', 'gatheringType']);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Attendance recorded successfully',
                'data' => $record,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to record attendance: '.$e->getMessage());

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

        $categorySlug = $attendance->gatheringCategory?->slug;
        $permissionPrefix = $categorySlug ? (self::PERMISSION_PREFIXES[$categorySlug] ?? null) : null;

        if (! $permissionPrefix || ! $user->can($permissionPrefix.'.update')) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to update this type of attendance.',
            ], 403);
        }

        if (! $this->userOwnsChurch($user, $attendance->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only update attendance for your own church.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'gathering_type_id' => 'nullable|exists:gathering_types,id',
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

        if (array_key_exists('gathering_type_id', $data) && $data['gathering_type_id']) {
            $gatheringType = GatheringType::find($data['gathering_type_id']);

            if (! $gatheringType || (int) $gatheringType->territory_id !== (int) $attendance->territory_id) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'The selected gathering type does not belong to this church.',
                    'errors' => ['gathering_type_id' => ['Invalid gathering type for this church.']],
                ], 422);
            }

            $data['event_name'] = $gatheringType->name;
        }

        $data['updated_by'] = $user->id;

        $attendance->update($data);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance updated successfully',
            'data' => $attendance->fresh(['gatheringCategory', 'gatheringType']),
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
