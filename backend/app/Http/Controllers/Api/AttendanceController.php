<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Reports\Attendance\AttendanceRecordDetail;
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
    private const COUNTS = ['adults_count', 'youth_count', 'children_male_count', 'children_female_count'];

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
     * One recorded Sunday or meeting for the record page: its counts, the
     * meetings either side of it, the usual and the best, and - for a Sunday
     * - members on the roll and the Sundays before and after.
     */
    public function show(Request $request, ChurchAttendanceRecord $attendance)
    {
        if (! $this->userOwnsChurch($request->user(), $attendance->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance records.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'data' => (new AttendanceRecordDetail($attendance))->toArray(),
        ]);
    }

    /**
     * Who recorded a record and every change since (the model is Auditable),
     * newest first - the same shape as the Gathering Types audit trail.
     */
    public function audits(Request $request, ChurchAttendanceRecord $attendance)
    {
        if (! $this->userOwnsChurch($request->user(), $attendance->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s attendance records.',
            ], 403);
        }

        $labels = [
            'adults_count' => 'Adults', 'youth_count' => 'Youth', 'children_male_count' => 'Boys',
            'children_female_count' => 'Girls', 'notes' => 'Notes', 'event_name' => 'Name', 'service_date' => 'Date',
        ];
        $audits = $attendance->audits()
            ->with('user:id,firstname,lastname')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($audit) => [
                'id' => $audit->id,
                'event' => $audit->event,
                'user' => $audit->user ? trim("{$audit->user->firstname} {$audit->user->lastname}") : null,
                // Only the fields people recognise, as "Adults: 80 -> 85".
                'changes' => collect($audit->new_values)
                    ->only(array_keys($labels))
                    ->map(function ($new, $field) use ($audit, $labels) {
                        $old = $audit->old_values[$field] ?? null;
                        // Dates read as "16 Aug 2026", not a timestamp.
                        $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('j M Y') : $v;

                        return ['field' => $labels[$field], 'old' => $field === 'service_date' ? $date($old) : $old, 'new' => $field === 'service_date' ? $date($new) : $new];
                    })
                    ->values()
                    ->all(),
                'created_at' => $audit->created_at->toIso8601String(),
                'created_at_human' => $audit->created_at->diffForHumans(),
            ]);

        return response()->json(['success' => true, 'status' => 200, 'data' => $audits]);
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
            'activity_id' => 'nullable|integer',
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
        // A count left empty is nobody in that group (the columns can't be null).
        foreach (self::COUNTS as $field) {
            $data[$field] = (int) ($data[$field] ?? 0);
        }

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

        if ($error = $this->sundayRuleError($category, $data['service_date'], (int) $data['territory_id'])) {
            return $error;
        }

        // Attendance at the church's own event (Events, docs/specs/events-initiatives-spec.md).
        if (! empty($data['activity_id']) && ! \App\Models\Activity::whereKey($data['activity_id'])->where('kind', 'event')
            ->where('territory_id', $data['territory_id'])->where('status', '!=', 'cancelled')->exists()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'That event isn\'t one of this church\'s.',
                'errors' => ['activity_id' => ['That event isn\'t one of this church\'s.']],
            ], 422);
        }

        $fiscal = $this->fiscalIdsFor($data['service_date']);
        if (! $fiscal) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'No fiscal year/month is configured for this service date.',
            ], 422);
        }

        try {
            $data['territory_type'] = 'church';
            [$data['fiscal_year_id'], $data['fiscal_month_id']] = $fiscal;
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
            // Moving a record entered on the wrong date.
            'service_date' => 'nullable|date',
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

        foreach (self::COUNTS as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) ($data[$field] ?? 0);
            }
        }

        if (! empty($data['service_date']) && $data['service_date'] !== $attendance->service_date->toDateString()) {
            if ($error = $this->sundayRuleError($attendance->gatheringCategory, $data['service_date'], (int) $attendance->territory_id, $attendance->id)) {
                return $error;
            }
            $fiscal = $this->fiscalIdsFor($data['service_date']);
            if (! $fiscal) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'No fiscal year/month is configured for that date.',
                    'errors' => ['service_date' => ['No fiscal year is set up for that date.']],
                ], 422);
            }
            [$data['fiscal_year_id'], $data['fiscal_month_id']] = $fiscal;
        } else {
            unset($data['service_date']);
        }

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
     * Delete a record entered by mistake. Soft delete: it leaves every list,
     * total and report, and can be put back with restore() (the Undo on the
     * page). Needs the category's `.delete` permission.
     */
    public function destroy(Request $request, ChurchAttendanceRecord $attendance)
    {
        if ($denied = $this->denyUnless($request, $attendance, 'delete')) {
            return $denied;
        }

        $attendance->delete();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance record deleted',
            'data' => ['id' => $attendance->id],
        ]);
    }

    /** Put back a deleted record (the Undo after a delete). */
    public function restore(Request $request, int $id)
    {
        $attendance = ChurchAttendanceRecord::onlyTrashed()->with('gatheringCategory')->find($id);
        if (! $attendance) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That record is not in the deleted records.'], 404);
        }
        if ($denied = $this->denyUnless($request, $attendance, 'delete')) {
            return $denied;
        }
        // Someone may have recorded that Sunday again since.
        if ($error = $this->sundayRuleError($attendance->gatheringCategory, $attendance->service_date->toDateString(), (int) $attendance->territory_id, $attendance->id)) {
            return $error;
        }

        $attendance->restore();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Attendance record restored',
            'data' => $attendance->fresh(['gatheringCategory', 'gatheringType']),
        ]);
    }

    /** 403 unless the user holds the category's `$action` permission and owns the church. */
    private function denyUnless(Request $request, ChurchAttendanceRecord $attendance, string $action)
    {
        $prefix = self::PERMISSION_PREFIXES[$attendance->gatheringCategory?->slug] ?? null;
        if (! $prefix || ! $request->user()->can("{$prefix}.{$action}")) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => "You do not have permission to {$action} this type of attendance.",
            ], 403);
        }
        if (! $this->userOwnsChurch($request->user(), $attendance->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only change attendance for your own church.',
            ], 403);
        }

        return null;
    }

    /**
     * A Sunday service must be on a Sunday, and only one per church per
     * Sunday - a second record would count that week twice in every average.
     * 422 (with existing_id when it's a duplicate), or null when fine.
     */
    private function sundayRuleError(?GatheringCategory $category, string $date, int $territoryId, ?int $ignoreId = null)
    {
        if (! $category?->is_weekly) {
            return null;
        }
        if (! \Carbon\Carbon::parse($date)->isSunday()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'A Sunday service has to be on a Sunday.',
                'errors' => ['service_date' => ['Pick a Sunday.']],
            ], 422);
        }
        $existing = ChurchAttendanceRecord::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->where('gathering_category_id', $category->id)
            ->whereDate('service_date', $date)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'This Sunday is already recorded. Edit that record instead.',
                'errors' => ['service_date' => ['This Sunday is already recorded.']],
                'existing_id' => $existing->id,
            ], 422);
        }

        return null;
    }

    /** [fiscal_year_id, fiscal_month_id] for a date, or null when no fiscal year is set up for it. */
    private function fiscalIdsFor(string $date): ?array
    {
        $year = FiscalYear::where('year', date('Y', strtotime($date)))->value('id');
        $month = FiscalMonth::where('number', date('n', strtotime($date)))->value('id');

        return $year && $month ? [$year, $month] : null;
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
