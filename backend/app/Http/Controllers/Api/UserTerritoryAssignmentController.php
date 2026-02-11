<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserTerritoryAssignment;
use App\Models\User;
use App\Models\Territory;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserTerritoryAssignmentController extends Controller
{
    /**
     * Display a listing of territorial assignments
     */
    public function index(Request $request)
    {
        try {
            $authUser = auth()->user();

            // Get query parameters
            $userId = $request->query('user_id');
            $territoryId = $request->query('territory_id');
            $roleId = $request->query('role_id');
            $assignmentType = $request->query('assignment_type');
            $isActive = $request->query('is_active', true);

            // Base query with relationships
            $query = UserTerritoryAssignment::with(['user', 'territory', 'role', 'assignedByUser']);

            // Apply territorial access control
            if (!$authUser->hasGlobalAccess()) {
                $accessibleTerritories = $this->getUserAccessibleTerritories($authUser);
                $territoryIds = $accessibleTerritories->pluck('id');
                $query->whereIn('territory_id', $territoryIds);
            }

            // Apply filters
            if ($userId) {
                $query->where('user_id', $userId);
            }

            if ($territoryId) {
                $query->where('territory_id', $territoryId);
            }

            if ($roleId) {
                $query->where('role_id', $roleId);
            }

            if ($assignmentType) {
                $query->where('assignment_type', $assignmentType);
            }

            // Filter by active status
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }

            $assignments = $query->orderBy('assigned_at', 'desc')->get();

            $assignmentsData = $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->full_name,
                        'email' => $assignment->user->email,
                        'employee_code' => $assignment->user->employee_code,
                        'position' => $assignment->user->position,
                    ],
                    'territory' => [
                        'id' => $assignment->territory->id,
                        'name' => $assignment->territory->name,
                        'type' => $assignment->territory->territory_type,
                        'code' => $assignment->territory->code,
                        'hierarchy_path' => $assignment->territory->hierarchy_path,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'is_active' => $assignment->is_active,
                    'assigned_by' => $assignment->assignedByUser ? [
                        'id' => $assignment->assignedByUser->id,
                        'name' => $assignment->assignedByUser->full_name,
                    ] : null,
                    'assigned_at' => $assignment->assigned_at,
                    'created_at' => $assignment->created_at,
                ];
            });

            return successResponse('Territorial assignments retrieved successfully', [
                'assignments' => $assignmentsData,
                'total' => $assignmentsData->count(),
                'filters' => [
                    'user_id' => $userId,
                    'territory_id' => $territoryId,
                    'role_id' => $roleId,
                    'assignment_type' => $assignmentType,
                    'is_active' => $isActive,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve territorial assignments', [
                'user_id' => auth()->id(),
                'filters' => $request->query(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve territorial assignments', $e->getMessage());
        }
    }


    /**
     * Display the specified assignment
     */
    public function show(UserTerritoryAssignment $assignment)
    {
        try {
            $authUser = auth()->user();

            // Check territorial access
            if (!$this->canAccessAssignment($authUser, $assignment)) {
                return errorResponse('You do not have permission to view this assignment', 403);
            }

            $assignment->load(['user', 'territory.parent', 'role', 'assignedByUser']);

            $assignmentData = [
                'id' => $assignment->id,
                'user' => [
                    'id' => $assignment->user->id,
                    'name' => $assignment->user->full_name,
                    'email' => $assignment->user->email,
                    'employee_code' => $assignment->user->employee_code,
                    'position' => $assignment->user->position,
                    'status' => $assignment->user->status,
                ],
                'territory' => [
                    'id' => $assignment->territory->id,
                    'name' => $assignment->territory->name,
                    'type' => $assignment->territory->territory_type,
                    'code' => $assignment->territory->code,
                    'hierarchy_path' => $assignment->territory->hierarchy_path,
                    'parent' => $assignment->territory->parent ? [
                        'id' => $assignment->territory->parent->id,
                        'name' => $assignment->territory->parent->name,
                        'type' => $assignment->territory->parent->territory_type,
                    ] : null,
                ],
                'role' => [
                    'id' => $assignment->role->id,
                    'name' => $assignment->role->name,
                    'territory_level' => $assignment->role->territory_level,
                    'description' => $assignment->role->description,
                ],
                'assignment_type' => $assignment->assignment_type,
                'is_primary' => $assignment->is_primary,
                'is_active' => $assignment->is_active,
                'assigned_by' => $assignment->assignedByUser ? [
                    'id' => $assignment->assignedByUser->id,
                    'name' => $assignment->assignedByUser->full_name,
                ] : null,
                'assigned_at' => $assignment->assigned_at,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ];

            return successResponse('Territorial assignment retrieved successfully', $assignmentData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve territorial assignment', [
                'assignment_id' => $assignment->id,
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve territorial assignment', $e->getMessage());
        }
    }

    /**
     * Store a new user territory assignment
     */
    public function store(Request $request)
    {
        try {
            $authUser = auth()->user();

            // Validate request
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'territory_id' => 'required|exists:territories,id',
                'role_id' => 'required|exists:roles,id',
                'assignment_type' => 'required|in:primary,secondary,temporary',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors(), 'Validation failed');
            }

            // Get the target user and territory
            $targetUser = User::findOrFail($request->user_id);
            $territory = Territory::findOrFail($request->territory_id);
            $role = Role::findOrFail($request->role_id);

            // Check if auth user can access the target user
            if (!$this->canAccessUser($authUser, $targetUser)) {
                return errorResponse('You do not have permission to manage this user\'s assignments', 403);
            }

            // Check if auth user can assign to this territory
            if (!$this->canAssignToTerritory($authUser, $territory)) {
                return errorResponse('You do not have permission to assign to this territory', 403);
            }

            // Check if role is compatible with territory
            if (!$this->isRoleCompatibleWithTerritory($role, $territory)) {
                return errorResponse(
                    "Role '{$role->name}' (level: {$role->territory_level}) is not compatible with territory '{$territory->name}' (type: {$territory->territory_type->value})",
                    400
                );
            }

            // Check if this exact assignment already exists (including soft-deleted ones)
            $existingAssignment = UserTerritoryAssignment::withTrashed()
                ->where('user_id', $request->user_id)
                ->where('territory_id', $request->territory_id)
                ->where('role_id', $request->role_id)
                ->first();


            if ($existingAssignment) {
                // Check if assignment is soft-deleted
                $isSoftDeleted = $existingAssignment->trashed();
                
                if ($existingAssignment->is_active && !$isSoftDeleted) {
                    return errorResponse(
                        'This user already has an active assignment with this role in this territory. Please update the existing assignment instead.',
                        400
                    );
                } else {
                    // Reactivate or restore the existing assignment
                    DB::beginTransaction();
                    
                    try {
                        // Restore if soft-deleted
                        if ($isSoftDeleted) {
                            $existingAssignment->restore();
                            
                            Log::info('Soft-deleted assignment restored', [
                                'assignment_id' => $existingAssignment->id,
                                'user_id' => $request->user_id,
                                'territory_id' => $request->territory_id,
                                'role_id' => $request->role_id,
                                'restored_by' => $authUser->id,
                            ]);
                        }
                        
                        // Handle primary assignment logic if reactivating as primary
                        if ($request->assignment_type === 'primary') {
                            $existingPrimary = UserTerritoryAssignment::where('user_id', $request->user_id)
                                ->where('assignment_type', 'primary')
                                ->where('is_active', true)
                                ->where('id', '!=', $existingAssignment->id)
                                ->first();

                            if ($existingPrimary) {
                                $existingPrimary->update([
                                    'assignment_type' => 'secondary',
                                    'updated_at' => now(),
                                ]);

                                Log::info('Existing primary assignment demoted to secondary', [
                                    'assignment_id' => $existingPrimary->id,
                                    'user_id' => $request->user_id,
                                    'territory_id' => $existingPrimary->territory_id,
                                    'demoted_by' => $authUser->id,
                                ]);
                            }
                        }

                        // Reactivate/update the assignment
                        $existingAssignment->update([
                            'assignment_type' => $request->assignment_type,
                            'is_active' => true,
                            'assigned_by' => $authUser->id,
                            'assigned_at' => now(),
                        ]);

                        Log::info('User territory assignment reactivated', [
                            'assignment_id' => $existingAssignment->id,
                            'user_id' => $request->user_id,
                            'territory_id' => $request->territory_id,
                            'role_id' => $request->role_id,
                            'assignment_type' => $request->assignment_type,
                            'reactivated_by' => $authUser->id,
                        ]);

                        DB::commit();

                        // Load relationships for response
                        $existingAssignment->load(['user', 'territory', 'role', 'assignedByUser']);

                        $assignmentData = [
                            'id' => $existingAssignment->id,
                            'user' => [
                                'id' => $existingAssignment->user->id,
                                'name' => $existingAssignment->user->full_name,
                                'email' => $existingAssignment->user->email,
                                'employee_code' => $existingAssignment->user->employee_code,
                            ],
                            'territory' => [
                                'id' => $existingAssignment->territory->id,
                                'name' => $existingAssignment->territory->name,
                                'type' => $existingAssignment->territory->territory_type,
                                'code' => $existingAssignment->territory->code,
                            ],
                            'role' => [
                                'id' => $existingAssignment->role->id,
                                'name' => $existingAssignment->role->name,
                                'territory_level' => $existingAssignment->role->territory_level,
                            ],
                            'assignment_type' => $existingAssignment->assignment_type,
                            'is_active' => $existingAssignment->is_active,
                            'assigned_by' => [
                                'id' => $existingAssignment->assignedByUser->id,
                                'name' => $existingAssignment->assignedByUser->full_name,
                            ],
                            'assigned_at' => $existingAssignment->assigned_at,
                            'created_at' => $existingAssignment->created_at,
                        ];

                        return successResponse('User territory assignment reactivated successfully', $assignmentData);

                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                }
            }

            DB::beginTransaction();

            try {
                // Handle primary assignment logic
                if ($request->assignment_type === 'primary') {
                    // Check if user already has a primary assignment
                    $existingPrimary = UserTerritoryAssignment::where('user_id', $request->user_id)
                        ->where('assignment_type', 'primary')
                        ->where('is_active', true)
                        ->first();

                    if ($existingPrimary) {
                        // Demote existing primary to secondary
                        $existingPrimary->update([
                            'assignment_type' => 'secondary',
                            'updated_at' => now(),
                        ]);

                        Log::info('Existing primary assignment demoted to secondary', [
                            'assignment_id' => $existingPrimary->id,
                            'user_id' => $request->user_id,
                            'territory_id' => $existingPrimary->territory_id,
                            'demoted_by' => $authUser->id,
                        ]);
                    }
                }

                // Create the new assignment
                $assignment = UserTerritoryAssignment::create([
                    'user_id' => $request->user_id,
                    'territory_id' => $request->territory_id,
                    'role_id' => $request->role_id,
                    'assignment_type' => $request->assignment_type,
                    'is_active' => true,
                    'assigned_by' => $authUser->id,
                    'assigned_at' => now(),
                ]);

                // Log the assignment creation
                Log::info('User territory assignment created', [
                    'assignment_id' => $assignment->id,
                    'user_id' => $request->user_id,
                    'territory_id' => $request->territory_id,
                    'role_id' => $request->role_id,
                    'assignment_type' => $request->assignment_type,
                    'assigned_by' => $authUser->id,
                ]);

                DB::commit();

                // Load relationships for response
                $assignment->load(['user', 'territory', 'role', 'assignedByUser']);

                $assignmentData = [
                    'id' => $assignment->id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->full_name,
                        'email' => $assignment->user->email,
                        'employee_code' => $assignment->user->employee_code,
                    ],
                    'territory' => [
                        'id' => $assignment->territory->id,
                        'name' => $assignment->territory->name,
                        'type' => $assignment->territory->territory_type,
                        'code' => $assignment->territory->code,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_active' => $assignment->is_active,
                    'assigned_by' => [
                        'id' => $assignment->assignedByUser->id,
                        'name' => $assignment->assignedByUser->full_name,
                    ],
                    'assigned_at' => $assignment->assigned_at,
                    'created_at' => $assignment->created_at,
                ];

                return successResponse('User territory assignment created successfully', $assignmentData);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to create user territory assignment', [
                'request_data' => $request->all(),
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to create user territory assignment', $e->getMessage());
        }
    }

    /**
     * Update an existing user territory assignment
     */
    public function update(Request $request, UserTerritoryAssignment $assignment)
    {
        try {
            $authUser = auth()->user();

            // Check if auth user can access this assignment
            if (!$this->canAccessAssignment($authUser, $assignment)) {
                return errorResponse('You do not have permission to modify this assignment', 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'territory_id' => 'sometimes|exists:territories,id',
                'role_id' => 'sometimes|exists:roles,id',
                'assignment_type' => 'sometimes|in:primary,secondary,temporary',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors(), 'Validation failed');
            }

            // Get territory and role if being updated
            $territory = $request->has('territory_id') 
                ? Territory::findOrFail($request->territory_id) 
                : $assignment->territory;
            
            $role = $request->has('role_id') 
                ? Role::findOrFail($request->role_id) 
                : $assignment->role;

            // Check if auth user can assign to the new territory (if changed)
            if ($request->has('territory_id') && $request->territory_id != $assignment->territory_id) {
                if (!$this->canAssignToTerritory($authUser, $territory)) {
                    return errorResponse('You do not have permission to assign to this territory', 403);
                }
            }

            // Check if role is compatible with territory (if either is being changed)
            if ($request->has('territory_id') || $request->has('role_id')) {
                if (!$this->isRoleCompatibleWithTerritory($role, $territory)) {
                    return errorResponse(
                        "Role '{$role->name}' (level: {$role->territory_level}) is not compatible with territory '{$territory->name}' (type: {$territory->territory_type->value})",
                        400
                    );
                }
            }

            DB::beginTransaction();

            try {
                $oldAssignmentType = $assignment->assignment_type;
                $newAssignmentType = $request->assignment_type ?? $oldAssignmentType;

                // Handle primary assignment logic if changing to/from primary
                if ($newAssignmentType === 'primary' && $oldAssignmentType !== 'primary') {
                    // Changing TO primary - demote existing primary
                    $existingPrimary = UserTerritoryAssignment::where('user_id', $assignment->user_id)
                        ->where('assignment_type', 'primary')
                        ->where('is_active', true)
                        ->where('id', '!=', $assignment->id)
                        ->first();

                    if ($existingPrimary) {
                        $existingPrimary->update([
                            'assignment_type' => 'secondary',
                            'updated_at' => now(),
                        ]);

                        Log::info('Existing primary assignment demoted to secondary during update', [
                            'assignment_id' => $existingPrimary->id,
                            'user_id' => $assignment->user_id,
                            'demoted_by' => $authUser->id,
                        ]);
                    }
                }

                // Update the assignment
                $updateData = [];
                if ($request->has('territory_id')) {
                    $updateData['territory_id'] = $request->territory_id;
                }
                if ($request->has('role_id')) {
                    $updateData['role_id'] = $request->role_id;
                }
                if ($request->has('assignment_type')) {
                    $updateData['assignment_type'] = $request->assignment_type;
                }
                $updateData['updated_at'] = now();

                $assignment->update($updateData);

                // Log the assignment update
                Log::info('User territory assignment updated', [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'updated_fields' => array_keys($updateData),
                    'updated_by' => $authUser->id,
                ]);

                DB::commit();

                // Reload relationships for response
                $assignment->load(['user', 'territory', 'role', 'assignedByUser']);

                $assignmentData = [
                    'id' => $assignment->id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->full_name,
                        'email' => $assignment->user->email,
                        'employee_code' => $assignment->user->employee_code,
                    ],
                    'territory' => [
                        'id' => $assignment->territory->id,
                        'name' => $assignment->territory->name,
                        'type' => $assignment->territory->territory_type,
                        'code' => $assignment->territory->code,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_active' => $assignment->is_active,
                    'assigned_by' => [
                        'id' => $assignment->assignedByUser->id,
                        'name' => $assignment->assignedByUser->full_name,
                    ],
                    'assigned_at' => $assignment->assigned_at,
                    'updated_at' => $assignment->updated_at,
                ];

                return successResponse('User territory assignment updated successfully', $assignmentData);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to update user territory assignment', [
                'assignment_id' => $assignment->id,
                'request_data' => $request->all(),
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to update user territory assignment', $e->getMessage());
        }
    }

    /**
     * Soft delete a user territory assignment
     */
    public function destroy(UserTerritoryAssignment $assignment)
    {
        try {
            $authUser = auth()->user();

            // Check if auth user can access this assignment
            if (!$this->canAccessAssignment($authUser, $assignment)) {
                return errorResponse('You do not have permission to remove this assignment', 403);
            }

            // Prevent deleting the only primary assignment
            if ($assignment->assignment_type === 'primary') {
                $activeAssignmentsCount = UserTerritoryAssignment::where('user_id', $assignment->user_id)
                    ->where('is_active', true)
                    ->count();

                if ($activeAssignmentsCount <= 1) {
                    return errorResponse(
                        'Cannot remove the only active assignment for this user. Users must have at least one active assignment.',
                        400
                    );
                }
            }

            DB::beginTransaction();

            try {
                // Soft delete: set is_active to false
                $assignment->update([
                    'is_active' => false,
                ]);

                // Log the assignment removal
                Log::info('User territory assignment removed', [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'territory_id' => $assignment->territory_id,
                    'role_id' => $assignment->role_id,
                    'removed_by' => $authUser->id,
                ]);

                DB::commit();

                return successResponse('User territory assignment removed successfully', [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'is_active' => $assignment->is_active,
                    'removed_by' => [
                        'id' => $authUser->id,
                        'name' => $authUser->full_name,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to remove user territory assignment', [
                'assignment_id' => $assignment->id,
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to remove user territory assignment', $e->getMessage());
        }
    }

    /**
     * Get assignments for a specific user
     */
    public function getUserAssignments(User $user)
    {
        try {
            $authUser = auth()->user();

            // Check if auth user can view this user's assignments
            if (!$this->canAccessUser($authUser, $user)) {
                return errorResponse('You do not have permission to view this user\'s assignments', 403);
            }

            $assignments = $user->allAssignments()
                              ->with(['territory', 'role', 'assignedByUser'])
                              ->orderBy('assigned_at', 'desc')
                              ->get();

            $assignmentsData = $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'territory' => [
                        'id' => $assignment->territory->id,
                        'name' => $assignment->territory->name,
                        'type' => $assignment->territory->territory_type,
                        'hierarchy_path' => $assignment->territory->hierarchy_path,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'is_active' => $assignment->is_active,
                    'assigned_by' => $assignment->assignedByUser ? $assignment->assignedByUser->full_name : null,
                    'assigned_at' => $assignment->assigned_at,
                ];
            });

            return successResponse('User assignments retrieved successfully', [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'employee_code' => $user->employee_code,
                ],
                'assignments' => $assignmentsData,
                'active_assignments' => $assignmentsData->where('is_active', true)->values(),
                'total_assignments' => $assignmentsData->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user assignments', [
                'user_id' => $user->id,
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve user assignments', $e->getMessage());
        }
    }

    /**
     * Get assignments for a specific territory
     */
    public function getTerritoryAssignments(Territory $territory)
    {
        try {
            $authUser = auth()->user();

            // Check territorial access
            if (!$this->canAccessTerritory($authUser, $territory)) {
                return errorResponse('You do not have permission to view this territory\'s assignments', 403);
            }

            $assignments = $territory->userAssignments()
                                   ->with(['user', 'role', 'assignedByUser'])
                                   ->active()
                                   ->orderBy('assigned_at', 'desc')
                                   ->get();

            $assignmentsData = $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->full_name,
                        'email' => $assignment->user->email,
                        'employee_code' => $assignment->user->employee_code,
                        'position' => $assignment->user->position,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'assigned_by' => $assignment->assignedByUser ? $assignment->assignedByUser->full_name : null,
                    'assigned_at' => $assignment->assigned_at,
                ];
            });

            return successResponse('Territory assignments retrieved successfully', [
                'territory' => [
                    'id' => $territory->id,
                    'name' => $territory->name,
                    'type' => $territory->territory_type,
                    'hierarchy_path' => $territory->hierarchy_path,
                ],
                'assignments' => $assignmentsData,
                'total_assignments' => $assignmentsData->count(),
                'primary_assignments' => $assignmentsData->where('is_primary', true)->values(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve territory assignments', [
                'territory_id' => $territory->id,
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve territory assignments', $e->getMessage());
        }
    }

    // === HELPER METHODS ===

    /**
     * Get user's accessible territories
     */
    private function getUserAccessibleTerritories($user)
    {
        if ($user->hasGlobalAccess()) {
            return Territory::all();
        }

        $userTerritories = collect();

        foreach ($user->activeAssignments as $assignment) {
            $territory = $assignment->territory;
            $userTerritories->push($territory);

            if ($assignment->role->hasHierarchicalAccess()) {
                $children = $territory->getAllChildren();
                $userTerritories = $userTerritories->merge($children);
            }
        }

        return $userTerritories->unique('id');
    }

    /**
     * Check if user can access assignment
     */
    private function canAccessAssignment($authUser, $assignment): bool
    {
        if ($authUser->hasGlobalAccess()) {
            return true;
        }

        $accessibleTerritories = $this->getUserAccessibleTerritories($authUser);
        return $accessibleTerritories->contains('id', $assignment->territory_id);
    }

    /**
     * Check if user can access territory
     */
    private function canAccessTerritory($authUser, $territory): bool
    {
        if ($authUser->hasGlobalAccess()) {
            return true;
        }

        $accessibleTerritories = $this->getUserAccessibleTerritories($authUser);
        return $accessibleTerritories->contains('id', $territory->id);
    }

    /**
     * Check if user can assign to territory
     */
    private function canAssignToTerritory($authUser, $territory): bool
    {
        return $this->canAccessTerritory($authUser, $territory);
    }

    /**
     * Check if user can access another user
     */
    private function canAccessUser($authUser, $targetUser): bool
    {
        if ($authUser->hasGlobalAccess()) {
            return true;
        }

        $authTerritories = $this->getUserAccessibleTerritories($authUser);
        $targetTerritories = $targetUser->activeAssignments->pluck('territory_id');

        return $authTerritories->pluck('id')->intersect($targetTerritories)->isNotEmpty();
    }

    /**
     * Check if role is compatible with territory
     */
    private function isRoleCompatibleWithTerritory($role, $territory): bool
    {
        $compatibilityMap = [
            'diocese' => ['diocese'],
            'region' => ['diocese', 'region'],
            'subregion' => ['region', 'subregion'],
            'church' => ['diocese', 'region', 'subregion', 'church'], // Church roles can work at any level
        ];

        // Convert territory_type Enum to string value for comparison
        $territoryType = $territory->territory_type->value ?? $territory->territory_type;
        
        return in_array($territoryType, $compatibilityMap[$role->territory_level] ?? []);
    }
    /**
     * Switch primary assignment for a user
     * Makes a secondary assignment primary and demotes the current primary to secondary
     */
    public function switchPrimaryAssignment(Request $request, $userId)
    {
        try {
            $authUser = auth()->user();
            $targetUser = User::findOrFail($userId);

            // Check if auth user can manage this user
            if (!$this->canManageUser($authUser, $targetUser)) {
                return errorResponse('You do not have permission to modify this user\'s assignments', 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'new_primary_assignment_id' => 'required|exists:user_territory_assignments,id',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse('Validation failed', $validator->errors());
            }

            $newPrimaryAssignmentId = $request->new_primary_assignment_id;

            // Get the assignment to be made primary
            $newPrimaryAssignment = UserTerritoryAssignment::where('id', $newPrimaryAssignmentId)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            if (!$newPrimaryAssignment) {
                return errorResponse('Assignment not found or not active for this user', 404);
            }

            // Check if this assignment is already primary
            if ($newPrimaryAssignment->assignment_type === 'primary') {
                return errorResponse('This assignment is already the primary assignment', 400);
            }

            DB::beginTransaction();

            try {
                // Get current primary assignment
                $currentPrimary = UserTerritoryAssignment::where('user_id', $userId)
                    ->where('assignment_type', 'primary')
                    ->where('is_active', true)
                    ->first();

                // Demote current primary to secondary
                if ($currentPrimary) {
                    $currentPrimary->update([
                        'assignment_type' => 'secondary',
                        'updated_at' => now(),
                    ]);

                    // Log the demotion
                    Log::info('Primary assignment demoted to secondary', [
                        'assignment_id' => $currentPrimary->id,
                        'user_id' => $userId,
                        'territory_id' => $currentPrimary->territory_id,
                        'role_id' => $currentPrimary->role_id,
                        'changed_by' => $authUser->id,
                    ]);
                }

                // Promote new assignment to primary
                $newPrimaryAssignment->update([
                    'assignment_type' => 'primary',
                    'updated_at' => now(),
                ]);

                // Log the promotion
                Log::info('Secondary assignment promoted to primary', [
                    'assignment_id' => $newPrimaryAssignment->id,
                    'user_id' => $userId,
                    'territory_id' => $newPrimaryAssignment->territory_id,
                    'role_id' => $newPrimaryAssignment->role_id,
                    'changed_by' => $authUser->id,
                ]);

                DB::commit();

                // Reload assignments
                $targetUser->load(['activeAssignments.territory', 'activeAssignments.role']);

                $assignments = $targetUser->activeAssignments->map(function ($assignment) {
                    return [
                        'id' => $assignment->id,
                        'territory' => [
                            'id' => $assignment->territory->id,
                            'name' => $assignment->territory->name,
                            'type' => $assignment->territory->territory_type,
                        ],
                        'role' => [
                            'id' => $assignment->role->id,
                            'name' => $assignment->role->name,
                        ],
                        'assignment_type' => $assignment->assignment_type,
                        'is_primary' => $assignment->assignment_type === 'primary',
                    ];
                });

                return successResponse('Primary assignment switched successfully', [
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => $targetUser->full_name,
                        'employee_code' => $targetUser->employee_code,
                    ],
                    'previous_primary' => $currentPrimary ? [
                        'id' => $currentPrimary->id,
                        'territory' => $currentPrimary->territory->name,
                        'role' => $currentPrimary->role->name,
                        'now_secondary' => true,
                    ] : null,
                    'new_primary' => [
                        'id' => $newPrimaryAssignment->id,
                        'territory' => $newPrimaryAssignment->territory->name,
                        'role' => $newPrimaryAssignment->role->name,
                        'was_secondary' => true,
                    ],
                    'all_assignments' => $assignments,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to switch primary assignment', [
                'user_id' => $userId,
                'new_primary_assignment_id' => $request->new_primary_assignment_id ?? null,
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to switch primary assignment', $e->getMessage());
        }
    }

    /**
     * Check if auth user can manage target user's assignments
     */
    private function canManageUser($authUser, $targetUser): bool
    {
        // Global admin can manage anyone
        if ($authUser->hasGlobalAccess()) {
            return true;
        }

        // Get auth user's territories
        $authTerritories = collect();
        foreach ($authUser->activeAssignments as $assignment) {
            $authTerritories->push($assignment->territory_id);

            // If user can see children, include child territories
            if ($assignment->can_see_children && $assignment->territory) {
                $children = $assignment->territory->getAllChildren();
                $authTerritories = $authTerritories->merge($children->pluck('id'));
            }
        }

        // Get target user's territories
        $targetTerritories = $targetUser->activeAssignments->pluck('territory_id');

        // Check if there's any overlap
        return $authTerritories->unique()->intersect($targetTerritories)->isNotEmpty();
    }
}
