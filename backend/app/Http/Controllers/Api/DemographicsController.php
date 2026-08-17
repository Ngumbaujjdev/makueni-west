<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\ChurchDemographic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DemographicsController extends Controller
{
    /**
     * Church-level create/update permission - matches the permission tree
     * already seeded under Module 27 "Growth" (see
     * FixDemographicsPermissionScaffoldingSeeder). Reusing the
     * "Demographics Tracking" submodule's create/update permission for the
     * whole monthly-snapshot row rather than the more granular
     * per-sub-submodule ones (Sunday School Enrollment, Youth Ministry
     * Tracking, etc.) - those exist for future fine-grained UI gating, but
     * the row itself is one atomic submission.
     */
    private const WRITE_PERMISSION_CREATE = 'churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create';
    private const WRITE_PERMISSION_UPDATE = 'churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.update';

    /**
     * List this church's demographics submissions (own church only).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (!$territoryId || !$this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s demographics.',
            ], 403);
        }

        $demographics = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $territoryId)
            ->with(['fiscalYear', 'fiscalMonth', 'reviewer'])
            ->orderByDesc('fiscal_year_id')
            ->orderByDesc('fiscal_month_id')
            ->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Demographics retrieved successfully',
            'data' => $demographics,
        ]);
    }

    public function show(Request $request, ChurchDemographic $demographic)
    {
        if (!$this->userOwnsChurch($request->user(), $demographic->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s demographics.',
            ], 403);
        }

        $demographic->load(['fiscalYear', 'fiscalMonth', 'reviewer', 'creator']);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Demographic retrieved successfully',
            'data' => $demographic,
        ]);
    }

    /**
     * Create this month's draft snapshot for the caller's own church.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->can(self::WRITE_PERMISSION_CREATE)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to record demographics.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'territory_id' => 'required|integer',
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'fiscal_month_id' => 'required|exists:fiscal_months,id',
            'total_members' => 'nullable|integer|min:0',
            'male_count' => 'nullable|integer|min:0',
            'female_count' => 'nullable|integer|min:0',
            'youth_count' => 'nullable|integer|min:0',
            'womens_fellowship_count' => 'nullable|integer|min:0',
            'mens_fellowship_count' => 'nullable|integer|min:0',
            'sunday_school_male_count' => 'nullable|integer|min:0',
            'sunday_school_female_count' => 'nullable|integer|min:0',
            'seniors_count' => 'nullable|integer|min:0',
            'new_members_count' => 'nullable|integer|min:0',
            'transferred_out_count' => 'nullable|integer|min:0',
            'baptisms_count' => 'nullable|integer|min:0',
            'communion_participants_count' => 'nullable|integer|min:0',
            'conversions_count' => 'nullable|integer|min:0',
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

        if (!$this->userOwnsChurch($user, (int) $data['territory_id'])) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only record demographics for your own church.',
            ], 403);
        }

        $existing = ChurchDemographic::where('territory_type', 'church')
            ->where('territory_id', $data['territory_id'])
            ->where('fiscal_year_id', $data['fiscal_year_id'])
            ->where('fiscal_month_id', $data['fiscal_month_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'A demographics submission already exists for this month. Update it instead.',
                'data' => $existing,
            ], 422);
        }

        try {
            $data['territory_type'] = 'church';
            $data['status'] = 'draft';
            $data['created_by'] = $user->id;
            $data['updated_by'] = $user->id;

            $demographic = ChurchDemographic::create($data);
            $demographic->load(['fiscalYear', 'fiscalMonth']);

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Demographics draft created successfully',
                'data' => $demographic,
                'warnings' => $this->buildValidationWarnings($demographic),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create church demographics: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create demographics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a draft/changes_requested submission for the caller's own church.
     */
    public function update(Request $request, ChurchDemographic $demographic)
    {
        $user = $request->user();

        if (!$user->can(self::WRITE_PERMISSION_UPDATE)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to update demographics.',
            ], 403);
        }

        if (!$this->userOwnsChurch($user, $demographic->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only update demographics for your own church.',
            ], 403);
        }

        if (!$demographic->is_editable) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => "Cannot edit a submission in '{$demographic->status}' status.",
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'total_members' => 'nullable|integer|min:0',
            'male_count' => 'nullable|integer|min:0',
            'female_count' => 'nullable|integer|min:0',
            'youth_count' => 'nullable|integer|min:0',
            'womens_fellowship_count' => 'nullable|integer|min:0',
            'mens_fellowship_count' => 'nullable|integer|min:0',
            'sunday_school_male_count' => 'nullable|integer|min:0',
            'sunday_school_female_count' => 'nullable|integer|min:0',
            'seniors_count' => 'nullable|integer|min:0',
            'new_members_count' => 'nullable|integer|min:0',
            'transferred_out_count' => 'nullable|integer|min:0',
            'baptisms_count' => 'nullable|integer|min:0',
            'communion_participants_count' => 'nullable|integer|min:0',
            'conversions_count' => 'nullable|integer|min:0',
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

        // Editing after a request-changes cycle moves it back to draft
        if ($demographic->status === 'changes_requested') {
            $data['status'] = 'draft';
        }

        $demographic->update($data);
        $demographic->load(['fiscalYear', 'fiscalMonth']);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Demographics updated successfully',
            'data' => $demographic,
            'warnings' => $this->buildValidationWarnings($demographic),
        ]);
    }

    /**
     * Soft validation, per the module spec: youth/fellowship/Sunday-school/
     * senior counts individually exceeding total_members is surfaced as a
     * warning, not a hard block - a pastor might have a legitimate edge
     * case, so this never prevents create/update/submit.
     */
    private function buildValidationWarnings(ChurchDemographic $demographic): array
    {
        $warnings = [];
        $total = $demographic->total_members;

        $checks = [
            'youth_count' => 'Youth count',
            'womens_fellowship_count' => "Women's fellowship count",
            'mens_fellowship_count' => "Men's fellowship count",
            'seniors_count' => 'Seniors count',
        ];

        foreach ($checks as $field => $label) {
            if ($demographic->{$field} > $total) {
                $warnings[] = "{$label} ({$demographic->{$field}}) exceeds total members ({$total}).";
            }
        }

        if ($demographic->sunday_school_count > $total) {
            $warnings[] = "Sunday school count ({$demographic->sunday_school_count}) exceeds total members ({$total}).";
        }

        return $warnings;
    }

    /**
     * Submit a draft (or a changes_requested submission) for Subregion review.
     */
    public function submit(Request $request, ChurchDemographic $demographic)
    {
        $user = $request->user();

        if (!$this->userOwnsChurch($user, $demographic->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only submit demographics for your own church.',
            ], 403);
        }

        if (!$demographic->can_be_submitted) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => "Cannot submit a submission in '{$demographic->status}' status.",
            ], 422);
        }

        $demographic->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Demographics submitted for approval',
            'data' => $demographic,
        ]);
    }

    /**
     * Approve a submitted demographics submission - Subregion Overseer only,
     * scoped to churches directly under their own subregion.
     */
    public function approve(Request $request, ChurchDemographic $demographic)
    {
        return $this->reviewAction(
            $request,
            $demographic,
            'subregiondemographicsreview.churchsubmissions.approve',
            'approved',
            'Demographics approved and forwarded to region'
        );
    }

    /**
     * Flag a submission - stays visible with a flag, does not block it from
     * being forwarded/rolled up, per the module spec (surfaces an anomaly
     * like "youth up 40%" without necessarily rejecting).
     */
    public function flag(Request $request, ChurchDemographic $demographic)
    {
        return $this->reviewAction(
            $request,
            $demographic,
            'subregiondemographicsreview.churchsubmissions.flag',
            'flagged',
            'Demographics flagged'
        );
    }

    /**
     * Send a submission back to the Pastor for correction.
     */
    public function requestChanges(Request $request, ChurchDemographic $demographic)
    {
        return $this->reviewAction(
            $request,
            $demographic,
            'subregiondemographicsreview.churchsubmissions.requestchanges',
            'changes_requested',
            'Sent back to pastor for changes'
        );
    }

    private function reviewAction(
        Request $request,
        ChurchDemographic $demographic,
        string $permission,
        string $newStatus,
        string $successMessage
    ) {
        $user = $request->user();

        if (!$user->can($permission)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to review demographics.',
            ], 403);
        }

        if (!$this->userOverseesChurch($user, $demographic->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only review submissions from churches in your own subregion.',
            ], 403);
        }

        if ($demographic->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => "Cannot review a submission in '{$demographic->status}' status.",
            ], 422);
        }

        $validator = Validator::make($request->all(), [
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

        $demographic->update([
            'status' => $newStatus,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('notes'),
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => $successMessage,
            'data' => $demographic,
        ]);
    }

    /**
     * Whether the given user is a Subregion-tier reviewer whose assigned
     * subregion is this church's direct parent territory. A church without
     * a subregion parent (attached straight to a Region - real in this
     * data set, per the audit) has no subregion reviewer at all; that's
     * expected, not a bug - Region/Diocese are summary-only per the spec.
     */
    private function userOverseesChurch($user, int $territoryId): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $church = Church::find($territoryId);

        if (!$church || !$church->isUnderSubregion()) {
            return false;
        }

        return $user->activeAssignments()
            ->where('territory_id', $church->parent_territory_id)
            ->exists();
    }

    /**
     * Read the caller's own church's entry-mode setting.
     */
    public function getEntryMode(Request $request, Church $church)
    {
        if (!$this->userOwnsChurch($request->user(), $church->id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s settings.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Entry mode retrieved successfully',
            'data' => ['attendance_mode' => $church->getAttendanceMode()],
        ]);
    }

    /**
     * Set the caller's own church's entry-mode setting - 'weekly_and_monthly'
     * (default) or 'monthly_only', for churches with unreliable internet
     * where weekly attendance entry isn't realistic. See the module plan's
     * "Data Model Changes" section.
     */
    public function updateEntryMode(Request $request, Church $church)
    {
        $user = $request->user();

        if (!$user->can(self::WRITE_PERMISSION_UPDATE)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to update this church\'s settings.',
            ], 403);
        }

        if (!$this->userOwnsChurch($user, $church->id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only update your own church\'s settings.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'attendance_mode' => 'required|in:weekly_and_monthly,monthly_only',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $church->metadata = array_merge($church->metadata ?? [], [
            'attendance_mode' => $request->input('attendance_mode'),
        ]);
        $church->save();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Entry mode updated successfully',
            'data' => ['attendance_mode' => $church->getAttendanceMode()],
        ]);
    }

    /**
     * Whether the given user has an active assignment to this exact
     * church territory. Deliberately stricter than the Budget module's
     * reference controller (which does no server-side territory or
     * permission enforcement at all) - this module's plan explicitly
     * requires 403s for cross-church access, so that's checked here
     * rather than left to the frontend alone.
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
