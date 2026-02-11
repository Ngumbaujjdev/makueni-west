<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Models\UserTerritoryAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
 * Display a listing of roles with territorial information
 * Filtered by user's territorial access level (like modules)
 *
 * Query Parameters:
 * - territory_level: Filter by territory level (diocese, region, subregion, church)
 * - is_active: Filter by status (true/false)
 * - search: Search in role name or description
 * - include_audits: Include audit summary (default: false)
 * - per_page: Items per page (default: 15)
 * - page: Page number
 * - sort_by: Field to sort by (default: territory_level)
 * - sort_order: asc or desc (default: asc)
 */
public function index(Request $request)
{
    try {
        $authUser = $request->user();

        // Determine effective territory level (SAME LOGIC AS MODULES!)
        $superAdminConfig = \App\Models\SuperAdminConfig::where('user_id', $authUser->id)
                                                        ->where('global_access', true)
                                                        ->first();

        if ($superAdminConfig) {
            // ✅ Global admin sees diocese-level roles
            $effectiveTerritoryLevel = 'diocese';
        } else {
            // ✅ Regular user - get their highest territorial level
            $effectiveTerritoryLevel = $this->getUserHighestTerritorialLevel($authUser);
        }

        // Get query parameters
        $territoryLevel = $request->query('territory_level');
        $isActive = $request->query('is_active');
        $search = $request->query('search');
        $includeAudits = $request->boolean('include_audits', false);
        $perPage = $request->input('per_page', 15);
        $sortBy = $request->input('sort_by', 'territory_level');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort parameters
        $allowedSortFields = ['name', 'territory_level', 'is_active', 'created_at'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'territory_level';
        }
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'asc';
        }

        // Get total counts for comparison
        $totalUsers = User::count();
        $totalPermissions = Permission::count();
        $totalModules = Module::count();
        $totalSubmodules = Submodule::count();


        // Base query with counts (removed 'users' from withCount to avoid Spatie morphToMany issue)
        $query = Role::withCount(['permissions'])
                     ->with(['permissions.module', 'permissions.submodule']);

        // ============================================================
        // ✅ SUPER ADMIN BYPASS: Allow viewing all territory levels
        // ============================================================
        if ($request->user()->isSuperAdmin()) {
            // Super admin can view roles at ANY territory level
            // If territory_level filter is provided, use it; otherwise show diocese-level
            if ($territoryLevel) {
                $query->where('territory_level', $territoryLevel);
            } else {
                $query->where('territory_level', 'diocese');
            }
        } else {
            // Regular user - apply territorial access control
            $userTerritorialLevel = $effectiveTerritoryLevel;
            $query->accessibleByLevel($userTerritorialLevel);
            
            // Apply territory level filter if provided
            if ($territoryLevel) {
                $query->where('territory_level', $territoryLevel);
            }
        }

        // Apply active status filter
        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        // Search functionality
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Apply sorting
        $query->orderBy($sortBy, $sortOrder);

        // Add secondary sort for consistent ordering
        if ($sortBy !== 'name') {
            $query->orderBy('name', 'asc');
        }

        // Paginate results
        $roles = $query->paginate($perPage);

        // Transform role data
        $rolesData = $roles->map(function ($role) use ($totalUsers, $totalPermissions, $totalModules, $totalSubmodules, $includeAudits) {
            $modules = $role->permissions->pluck('module.id')->unique()->filter();
            $submodules = $role->permissions->pluck('submodule.id')->unique()->filter();

            // Calculate users count here to avoid Spatie morphToMany issue
            $usersCount = $role->users()->count();

            $data = [
                'id' => $role->id,
                'name' => $role->name,
                'territory_level' => $role->territory_level,
                'territory_level_name' => $role->territory_level_name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'users_count' => $usersCount . '/' . $totalUsers,
                'permissions_count' => $role->permissions_count . '/' . $totalPermissions,
                'modules_count' => $modules->count() . '/' . $totalModules,
                'submodules_count' => $submodules->count() . '/' . $totalSubmodules,
                'created_at' => $role->created_at,
            ];

            // Include audit summary if requested
            if ($includeAudits) {
                $data['audit_summary'] = [
                    'total_changes' => $role->audits()->count(),
                    'last_modified_by' => $role->getLastModifiedBy(),
                ];
            }

            return $data;
        });

        return successResponse('Roles retrieved successfully', [
            'roles' => $rolesData,
            'pagination' => [
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'from' => $roles->firstItem(),
                'to' => $roles->lastItem(),
            ],
            'filters' => [
                'territory_level' => $territoryLevel,
                'is_active' => $isActive,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'territory_level' => $effectiveTerritoryLevel,
            'is_global_admin' => $superAdminConfig ? true : false,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve roles', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return serverErrorResponse('Failed to retrieve roles', $e->getMessage());
    }
}

    /**
     * Store a newly created role
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles',
            'territory_level' => 'required|in:diocese,region,subregion,church',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check if user can create roles at this territorial level
            $user = auth()->user();
            if (!$this->canManageTerritorialLevel($user, $request->territory_level)) {
                return errorResponse('You do not have permission to create roles at this territorial level', 403);
            }

            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'web',
                'territory_level' => $request->territory_level,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            Log::info('Role created successfully', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'territory_level' => $role->territory_level,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Role created successfully', 201);
        } catch (\Exception $e) {
            Log::error('Failed to create role', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create role', $e->getMessage());
        }
    }
/**
 * Display the specified role with detailed permissions
 * Returns ALL available modules for the role's territory level with assigned permissions marked
 *
 * Query Parameters:
 * - include_audits: Include full audit trail (default: false)
 * - audit_limit: Number of audit records (default: 20)
 * - audit_type: Filter audits by type (created, updated, role_renamed, role_activated, etc.)
 * - audit_from: Filter audits from date (Y-m-d)
 * - audit_to: Filter audits to date (Y-m-d)
 * - audit_page: Page number for audit pagination
 */
public function show(Request $request, Role $role)
{
    try {
        // Check territorial access
        $user = auth()->user();
        if (!$this->canAccessRole($user, $role)) {
            return errorResponse('You do not have permission to view this role', 403);
        }

        // Load existing permissions for this role
        $role->load('permissions');

        // Create a map of assigned permissions for quick lookup
        $assignedPermissions = $role->permissions->groupBy(function($permission) {
            if ($permission->sub_submodule_id) {
                return $permission->module_id . '_' . $permission->submodule_id . '_' . $permission->sub_submodule_id;
            }
            return $permission->module_id . '_' . $permission->submodule_id;
        });

        // Get ALL modules for this role's territory level (NOT the logged-in user's level)
        $moduleGroups = \App\Models\ModuleGroup::where('territory_scope', $role->territory_level)
            ->where('is_active', true)
            ->orderBy('order')
            ->with([
                'modules' => function($query) {
                    $query->where('is_active', true)
                          ->orderBy('number')
                          ->orderBy('name')
                          ->with([
                              'submodules' => function($subQuery) {
                                  $subQuery->where('is_active', true)
                                           ->orderBy('title')
                                           ->with(['subSubmodules' => function($subSubQuery) {
                                               $subSubQuery->where('is_active', true)
                                                           ->orderBy('title');
                                           }]);
                              }
                          ]);
                }
            ])
            ->get();

        // Process each module group to build the response
        $modules = [];
        $availableActions = ['create', 'read', 'update', 'delete', 'approve', 'export'];

        foreach ($moduleGroups as $group) {
            foreach ($group->modules as $module) {
                $submodules = [];

                foreach ($module->submodules as $submodule) {
                    if ($submodule->subSubmodules->count() > 0) {
                        // Has sub_submodules
                        $subSubmodules = [];

                        foreach ($submodule->subSubmodules as $subSub) {
                            $key = $module->id . '_' . $submodule->id . '_' . $subSub->id;

                            // Get ALL available permissions for this sub_submodule from database
                            $allPermissions = Permission::where('module_id', $module->id)
                                ->where('submodule_id', $submodule->id)
                                ->where('sub_submodule_id', $subSub->id)
                                ->get()
                                ->map(function($perm) {
                                    return [
                                        'id' => $perm->id,
                                        'action' => $perm->action,
                                        'name' => $perm->name
                                    ];
                                })
                                ->toArray();

                            // Get assigned permission IDs for this sub_submodule
                            $assignedPermissionIds = $assignedPermissions->get($key)?->pluck('id')->toArray() ?? [];

                            $subSubmodules[] = [
                                'id' => $subSub->id,
                                'title' => $subSub->title,
                                'actions' => $availableActions,
                                'permissions' => $allPermissions, // ALL available permissions
                                'assigned_permission_ids' => $assignedPermissionIds // IDs of assigned permissions
                            ];
                        }

                        $submodules[] = [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'sub_submodules' => $subSubmodules
                        ];
                    } else {
                        // Regular submodule (no sub_submodules)
                        $key = $module->id . '_' . $submodule->id;

                        // Get ALL available permissions for this submodule from database
                        $allPermissions = Permission::where('module_id', $module->id)
                            ->where('submodule_id', $submodule->id)
                            ->whereNull('sub_submodule_id')
                            ->get()
                            ->map(function($perm) {
                                return [
                                    'id' => $perm->id,
                                    'action' => $perm->action,
                                    'name' => $perm->name
                                ];
                            })
                            ->toArray();

                        // Get assigned permission IDs for this submodule
                        $assignedPermissionIds = $assignedPermissions->get($key)?->pluck('id')->toArray() ?? [];

                        $submodules[] = [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'actions' => $availableActions,
                            'permissions' => $allPermissions, // ALL available permissions
                            'assigned_permission_ids' => $assignedPermissionIds // IDs of assigned permissions
                        ];
                    }
                }

                $modules[] = [
                    'id' => $module->id,
                    'name' => $module->name,
                    'icon' => $module->icon ?? null,
                    'submodules' => $submodules
                ];
            }
        }

        $roleData = [
            'id' => $role->id,
            'name' => $role->name,
            'territory_level' => $role->territory_level,
            'territory_level_name' => $role->territory_level_name,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'modules' => $modules, // ALL modules for this territory, with assigned permissions marked
            'users_count' => $role->users()->count(),
            'permissions_count' => $role->permissions->count(),
            'created_at' => $role->created_at,
        ];

        // Include audit trails if requested
        if ($request->boolean('include_audits', false)) {
            $auditLimit = $request->input('audit_limit', 20);

            // Build audit query
            $auditQuery = $role->audits()
                ->with('user:id,firstname,lastname,username');

            // Filter by event type if specified
            if ($request->filled('audit_type')) {
                $auditQuery->where('event', $request->audit_type);
            }

            // Filter by date range
            if ($request->filled('audit_from')) {
                $auditQuery->whereDate('created_at', '>=', $request->audit_from);
            }
            if ($request->filled('audit_to')) {
                $auditQuery->whereDate('created_at', '<=', $request->audit_to);
            }

            // Get paginated or limited results
            if ($request->has('audit_page')) {
                $audits = $auditQuery->latest()->paginate($auditLimit, ['*'], 'audit_page');

                $roleData['audit_trail'] = [
                    'data' => $audits->map(function ($audit) {
                        return [
                            'id' => $audit->id,
                            'event' => $audit->event,
                            'changed_by' => $audit->user ? [
                                'id' => $audit->user->id,
                                'name' => $audit->user->full_name,
                            ] : 'System',
                            'old_values' => $audit->old_values,
                            'new_values' => $audit->new_values,
                            'ip_address' => $audit->ip_address,
                            'user_agent' => $audit->user_agent,
                            'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $audits->currentPage(),
                        'last_page' => $audits->lastPage(),
                        'per_page' => $audits->perPage(),
                        'total' => $audits->total(),
                    ]
                ];
            } else {
                $roleData['audit_trail'] = $auditQuery->latest()
                    ->limit($auditLimit)
                    ->get()
                    ->map(function ($audit) {
                        return [
                            'id' => $audit->id,
                            'event' => $audit->event,
                            'changed_by' => $audit->user ? [
                                'id' => $audit->user->id,
                                'name' => $audit->user->full_name,
                            ] : 'System',
                            'old_values' => $audit->old_values,
                            'new_values' => $audit->new_values,
                            'ip_address' => $audit->ip_address,
                            'user_agent' => $audit->user_agent,
                            'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
                        ];
                    });
            }

            // Add audit summary
            $roleData['audit_summary'] = [
                'total_changes' => $role->audits()->count(),
                'last_modified_by' => $role->getLastModifiedBy(),
            ];
        }

        return successResponse('Role details retrieved successfully', $roleData);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve role details', [
            'role_id' => $role->id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve role details', $e->getMessage());
    }
}

    /**
     * Update the specified role
     */
    public function update(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'territory_level' => 'required|in:diocese,region,subregion,church',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check territorial access
            $user = auth()->user();
            if (!$this->canAccessRole($user, $role)) {
                return errorResponse('You do not have permission to update this role', 403);
            }

            $role->update([
                'name' => $request->name,
                'territory_level' => $request->territory_level,
                'description' => $request->description,
                'is_active' => $request->input('is_active', $role->is_active),
            ]);

            Log::info('Role updated successfully', [
                'role_id' => $role->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($role->fresh(), 'Role updated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to update role', [
                'role_id' => $role->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update role', $e->getMessage());
        }
    }

    /**
     * Remove the specified role
     */
    public function destroy(Role $role)
    {
        try {
            // Check territorial access
            $user = auth()->user();
            if (!$this->canAccessRole($user, $role)) {
                return errorResponse('You do not have permission to delete this role', 403);
            }

            // Check if role has associated users
            $userCount = $role->users()->count();
            $assignmentCount = UserTerritoryAssignment::where('role_id', $role->id)->count();

            if ($userCount > 0 || $assignmentCount > 0) {
                return errorResponse(
                    'Cannot delete role. It is assigned to ' . ($userCount + $assignmentCount) . ' user assignment(s).',
                    400
                );
            }

            // If no users are assigned, proceed with deletion
            $roleName = $role->name;
            $role->delete();

            Log::info('Role deleted successfully', [
                'role_name' => $roleName,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse('Role deleted successfully');
        } catch (\Exception $e) {
            Log::error('Error deleting role', [
                'role_id' => $role->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to delete role', $e->getMessage());
        }
    }

    /**
     * Update permissions for a role (MAIN FUNCTION - Dynamic Permission Assignment)
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'required|integer|exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check territorial access
            $user = auth()->user();
            if (!$this->canAccessRole($user, $role)) {
                return errorResponse('You do not have permission to update permissions for this role', 403);
            }

            DB::beginTransaction();

            // Use the role's updateModulePermissions method
            $role->updateModulePermissions($request->permissions);

            DB::commit();

            Log::info('Role permissions updated successfully', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permissions_count' => count($request->permissions),
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($role->load('permissions.module', 'permissions.submodule', 'permissions.subSubmodule'),
                                  'Role permissions updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update role permissions', [
                'role_id' => $role->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to update role permissions', $e->getMessage());
        }
    }

    /**
     * Get users assigned to a specific role
     */
    public function getRoleUsers(Role $role)
    {
        try {
            // Check territorial access
            $user = auth()->user();
            if (!$this->canAccessRole($user, $role)) {
                return errorResponse('You do not have permission to view users for this role', 403);
            }

            // Get users through territory assignments
            $assignments = UserTerritoryAssignment::where('role_id', $role->id)
                ->with(['user', 'territory', 'assignedByUser'])
                ->active()
                ->get();

            $userData = $assignments->map(function ($assignment) {
                return [
                    'assignment_id' => $assignment->id,
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
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'assigned_at' => $assignment->assigned_at,
                    'assigned_by' => $assignment->assignedByUser ? $assignment->assignedByUser->full_name : null,
                ];
            });

            return successResponse('Role users retrieved successfully', [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'territory_level' => $role->territory_level,
                ],
                'assignments' => $userData,
                'total_users' => $userData->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve role users', [
                'role_id' => $role->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve role users', $e->getMessage());
        }
    }

    // === HELPER METHODS ===

    /**
     * Get user's highest territorial level
     */

/**
 * Get user's highest territorial level
 */
private function getUserHighestTerritorialLevel(User $user): string
{
    $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];

    $userLevels = $user->activeAssignments()
                      ->with('role')
                      ->get()
                      ->pluck('role.territory_level')
                      ->unique()
                      ->filter();

    foreach (array_reverse($levelHierarchy) as $level) {
        if ($userLevels->contains($level)) {
            return $level;
        }
    }

    return 'church';
}

    /**
     * Check if user can manage roles at specific territorial level
     */
    private function canManageTerritorialLevel(User $user, string $targetLevel): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $userLevel = $this->getUserHighestTerritorialLevel($user);
        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];

        $userIndex = array_search($userLevel, $levelHierarchy);
        $targetIndex = array_search($targetLevel, $levelHierarchy);

        return $userIndex !== false && $targetIndex !== false && $userIndex >= $targetIndex;
    }

    /**
     * Check if user can access specific role
     */
    private function canAccessRole(User $user, Role $role): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return $this->canManageTerritorialLevel($user, $role->territory_level);
    }
}
