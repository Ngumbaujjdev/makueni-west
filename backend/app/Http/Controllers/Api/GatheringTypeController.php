<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatheringType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GatheringTypeController extends Controller
{
    /**
     * List a church's own gathering types (own church only), optionally
     * filtered by gathering_category_id.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $territoryId = (int) $request->query('territory_id');

        if (! $territoryId || ! $this->userOwnsChurch($user, $territoryId)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this church\'s gathering types.',
            ], 403);
        }

        $query = GatheringType::with('category')->forTerritory($territoryId);

        if ($request->filled('gathering_category_id')) {
            $query->where('gathering_category_id', (int) $request->query('gathering_category_id'));
        }

        if ($request->filled('include_inactive') && $request->query('include_inactive') === 'false') {
            $query->active();
        } elseif (! $request->filled('include_inactive')) {
            $query->active();
        }

        $types = $query->orderBy('display_order')->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Gathering types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    /**
     * Create a gathering type for the caller's own church.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'territory_id' => 'required|integer',
            'gathering_category_id' => 'required|exists:gathering_categories,id',
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
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

        if (! $user->can('church.attendance.gatheringtypes.create')) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to configure gathering types.',
            ], 403);
        }

        if (! $this->userOwnsChurch($user, (int) $data['territory_id'])) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only configure gathering types for your own church.',
            ], 403);
        }

        $data['slug'] = Str::slug($data['name']);
        $data['created_by'] = $user->id;

        if (GatheringType::where('territory_id', $data['territory_id'])->where('slug', $data['slug'])->exists()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'A gathering type with this name already exists for your church.',
                'errors' => ['name' => ['This name is already in use.']],
            ], 422);
        }

        $gatheringType = GatheringType::create($data);
        $gatheringType->load('category');

        return response()->json([
            'success' => true,
            'status' => 201,
            'message' => 'Gathering type created successfully',
            'data' => $gatheringType,
        ], 201);
    }

    public function show(GatheringType $gatheringType)
    {
        $user = request()->user();

        if (! $this->userOwnsChurch($user, $gatheringType->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this gathering type.',
            ], 403);
        }

        $gatheringType->load('category');

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Gathering type retrieved successfully',
            'data' => $gatheringType,
        ], 200);
    }

    /**
     * Update a gathering type - no hard delete, is_active toggle only,
     * matching this app's established no-delete convention.
     */
    public function update(Request $request, GatheringType $gatheringType)
    {
        $user = $request->user();

        if (! $user->can('church.attendance.gatheringtypes.update')) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have permission to configure gathering types.',
            ], 403);
        }

        if (! $this->userOwnsChurch($user, $gatheringType->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You can only configure gathering types for your own church.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'gathering_category_id' => 'sometimes|required|exists:gathering_categories,id',
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
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

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $gatheringType->update($data);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Gathering type updated successfully',
            'data' => $gatheringType->fresh('category'),
        ], 200);
    }

    public function getAudits(GatheringType $gatheringType)
    {
        $user = request()->user();

        if (! $this->userOwnsChurch($user, $gatheringType->territory_id)) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You do not have access to this gathering type.',
            ], 403);
        }

        $audits = $gatheringType->audits()
            ->with('user:id,firstname,lastname,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'user' => $audit->user ? [
                        'id' => $audit->user->id,
                        'name' => $audit->user->firstname.' '.$audit->user->lastname,
                        'email' => $audit->user->email,
                    ] : null,
                    'old_values' => $audit->old_values,
                    'new_values' => $audit->new_values,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                    'created_at_human' => $audit->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Audit trail retrieved successfully',
            'data' => $audits,
        ], 200);
    }

    /**
     * Same ownership check as AttendanceController/DemographicsController.
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
