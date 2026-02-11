<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StatusController extends Controller
{
    /**
     * Display a listing of statuses
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Status::with('creator:id,firstname,lastname,email');

            // Filter by category
            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
                if ($isActive) {
                    $query->active();
                } else {
                    $query->where('is_active', false);
                }
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Order
            $query->ordered();

            // Pagination
            $perPage = $request->get('per_page', 50);
            $statuses = $perPage === 'all' ? $query->get() : $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Statuses retrieved successfully',
                'data' => $statuses
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve statuses: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve statuses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255',
                'category' => 'required|string|max:255',
                'description' => 'nullable|string',
                'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
                'icon' => 'nullable|string|max:255',
                'display_order' => 'nullable|integer',
                'is_initial' => 'nullable|boolean',
                'is_final' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Auto-generate slug if not provided
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            // Check for duplicate slug within category
            $exists = Status::where('category', $data['category'])
                           ->where('slug', $data['slug'])
                           ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'status' => 409,
                    'message' => 'A status with this slug already exists in this category',
                ], 409);
            }

            // Add created_by
            $data['created_by'] = auth()->id();

            $status = Status::create($data);
            $status->load('creator:id,firstname,lastname,email');

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Status created successfully',
                'data' => $status
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified status
     *
     * @param Status $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Status $status)
    {
        try {
            $status->load('creator:id,firstname,lastname,email');

            // Get usage count
            $status->budgets_count = $status->budgets()->count();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Status retrieved successfully',
                'data' => $status
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified status
     *
     * @param Request $request
     * @param Status $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Status $status)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'sometimes|required|string|max:255',
                'category' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
                'icon' => 'nullable|string|max:255',
                'display_order' => 'nullable|integer',
                'is_initial' => 'nullable|boolean',
                'is_final' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Check for duplicate slug within category if slug or category changed
            if (isset($data['slug']) || isset($data['category'])) {
                $newSlug = $data['slug'] ?? $status->slug;
                $newCategory = $data['category'] ?? $status->category;

                $exists = Status::where('category', $newCategory)
                               ->where('slug', $newSlug)
                               ->where('id', '!=', $status->id)
                               ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'status' => 409,
                        'message' => 'A status with this slug already exists in this category',
                    ], 409);
                }
            }

            $status->update($data);
            $status->load('creator:id,firstname,lastname,email');

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Status updated successfully',
                'data' => $status
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update display order of a status
     *
     * @param Request $request
     * @param Status $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrder(Request $request, Status $status)
    {
        try {
            $validator = Validator::make($request->all(), [
                'display_order' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $status->update(['display_order' => $request->display_order]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Display order updated successfully',
                'data' => $status
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update display order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update display order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified status
     *
     * @param Status $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Status $status)
    {
        try {
            // Check if status is in use
            $budgetsCount = $status->budgets()->count();

            if ($budgetsCount > 0) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => "Cannot delete status. It is being used by {$budgetsCount} budget(s)",
                ], 400);
            }

            $status->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Status deleted successfully',
                'data' => null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit trail for a status
     *
     * @param Status $status
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAudits(Status $status)
    {
        try {
            $audits = $status->audits()
                ->with('user:id,firstname,lastname,email')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($audit) {
                    return [
                        'id' => $audit->id,
                        'event' => $audit->event,
                        'user' => $audit->user ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->firstname . ' ' . $audit->user->lastname,
                            'email' => $audit->user->email,
                        ] : null,
                        'old_values' => $audit->old_values,
                        'new_values' => $audit->new_values,
                        'ip_address' => $audit->ip_address,
                        'user_agent' => $audit->user_agent,
                        'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                        'created_at_human' => $audit->created_at->diffForHumans(),
                    ];
                });

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Audit trail retrieved successfully',
                'data' => $audits
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve audit trail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve audit trail',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
