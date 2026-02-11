<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
  /**
 * Display a listing of permissions with territorial filtering
 * Filtered by user's territorial access level (like modules)
 *
 * Query Parameters:
 * - module_id: Filter by module
 * - submodule_id: Filter by submodule
 * - territory_scope: Filter by territory scope
 * - action: Filter by action (create, read, update, delete, etc.)
 * - search: Search in permission name
 * - include_audits: Include audit summary (default: false)
 * - per_page: Items per page (default: 15)
 * - page: Page number
 * - sort_by: Field to sort by (default: module_id)
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
            // ✅ Global admin sees diocese-level permissions
            $effectiveTerritoryLevel = 'diocese';
        } else {
            // ✅ Regular user - get their highest territorial level
            $effectiveTerritoryLevel = $this->getUserHighestTerritorialLevel($authUser);
        }

        // Get query parameters
        $moduleId = $request->query('module_id');
        $submoduleId = $request->query('submodule_id');
        $territoryScope = $request->query('territory_scope');
        $action = $request->query('action');
        $search = $request->query('search');
        $includeAudits = $request->boolean('include_audits', false);
        $perPage = $request->input('per_page', 15);
        $sortBy = $request->input('sort_by', 'module_id');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort parameters
        $allowedSortFields = ['name', 'module_id', 'submodule_id', 'action', 'territory_scope', 'created_at'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'module_id';
        }
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'asc';
        }

        // Build query with relationships
        $query = Permission::with(['module', 'submodule', 'subSubmodule']);

        // Apply territorial access control
        if (!$superAdminConfig) {
            $userTerritorialLevel = $effectiveTerritoryLevel;
            $query->forTerritoryLevel($userTerritorialLevel);
        } else {
            // Super admin sees only diocese-level permissions
            $query->where('territory_scope', 'diocese');
        }

        // Apply filters
        if ($moduleId) {
            $query->where('module_id', $moduleId);
        }

        if ($submoduleId) {
            $query->where('submodule_id', $submoduleId);
        }

        if ($territoryScope) {
            $query->where('territory_scope', $territoryScope);
        }

        if ($action) {
            $query->where('action', $action);
        }

        // Search functionality
        if ($search) {
            $query->where('name', 'LIKE', "%{$search}%");
        }

        // Apply sorting
        $query->orderBy($sortBy, $sortOrder);

        // Add secondary sorts for consistent ordering
        if ($sortBy !== 'module_id') {
            $query->orderBy('module_id', 'asc');
        }
        if ($sortBy !== 'submodule_id') {
            $query->orderBy('submodule_id', 'asc');
        }
        if ($sortBy !== 'action') {
            $query->orderBy('action', 'asc');
        }

        // Paginate results
        $permissions = $query->paginate($perPage);

        // Transform permission data
        $permissionsData = $permissions->map(function ($permission) use ($includeAudits) {
            $data = [
                'id' => $permission->id,
                'name' => $permission->name,
                'formatted_name' => $permission->formatted_name,
                'action' => $permission->action,
                'territory_scope' => $permission->territory_scope,
                'module' => $permission->module ? [
                    'id' => $permission->module->id,
                    'name' => $permission->module->name,
                ] : null,
                'submodule' => $permission->submodule ? [
                    'id' => $permission->submodule->id,
                    'title' => $permission->submodule->title,
                ] : null,
                'sub_submodule' => $permission->subSubmodule ? [
                    'id' => $permission->subSubmodule->id,
                    'title' => $permission->subSubmodule->title,
                ] : null,
                'created_at' => $permission->created_at,
            ];

            // Include audit summary if requested
            if ($includeAudits) {
                $data['audit_summary'] = [
                    'total_changes' => $permission->audits()->count(),
                    'last_modified_by' => $permission->getLastModifiedBy(),
                ];
            }

            return $data;
        });

        return successResponse('Permissions retrieved successfully', [
            'permissions' => $permissionsData,
            'pagination' => [
                'current_page' => $permissions->currentPage(),
                'last_page' => $permissions->lastPage(),
                'per_page' => $permissions->perPage(),
                'total' => $permissions->total(),
                'from' => $permissions->firstItem(),
                'to' => $permissions->lastItem(),
            ],
            'filters' => [
                'module_id' => $moduleId,
                'submodule_id' => $submoduleId,
                'territory_scope' => $territoryScope,
                'action' => $action,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'territory_level' => $effectiveTerritoryLevel,
            'is_global_admin' => $superAdminConfig ? true : false,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve permissions', [
            'user_id' => $request->user()->id,
            'filters' => $request->query(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve permissions', $e->getMessage());
    }
}

/**
 * Get user's territorial level
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
     * Store a newly created permission
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_id' => 'required|exists:modules,id',
            'submodule_id' => 'required|exists:submodules,id',
            'sub_submodule_id' => 'nullable|exists:sub_submodules,id',
            'action' => 'required|string|in:create,read,update,delete,approve,export,import',
            'territory_scope' => 'required|in:diocese,region,subregion,church',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = auth()->user();

            // Check if user can create permissions for this territorial scope
            if (!$this->canManageTerritorialScope($user, $request->territory_scope)) {
                return errorResponse('You do not have permission to create permissions for this territorial scope', 403);
            }

            // Get module and submodule details for permission name generation
            $module = Module::find($request->module_id);
            $submodule = Submodule::find($request->submodule_id);
            $subSubmodule = $request->sub_submodule_id ? SubSubmodule::find($request->sub_submodule_id) : null;

            // Generate permission name
            $permissionName = strtolower($module->name) . '.' . strtolower($submodule->title);
            if ($subSubmodule) {
                $permissionName .= '.' . strtolower($subSubmodule->title);
            }
            $permissionName .= '.' . strtolower($request->action);

            // Check if permission already exists
            $existingPermission = Permission::where([
                'module_id' => $request->module_id,
                'submodule_id' => $request->submodule_id,
                'sub_submodule_id' => $request->sub_submodule_id,
                'action' => $request->action,
                'territory_scope' => $request->territory_scope,
            ])->first();

            if ($existingPermission) {
                return errorResponse('Permission already exists', 400);
            }

            // Create permission
            $permission = Permission::create([
                'name' => $permissionName,
                'guard_name' => 'web',
                'module_id' => $request->module_id,
                'submodule_id' => $request->submodule_id,
                'sub_submodule_id' => $request->sub_submodule_id,
                'action' => $request->action,
                'territory_scope' => $request->territory_scope,
            ]);

            $permission->load(['module', 'submodule', 'subSubmodule']);

            Log::info('Permission created successfully', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Permission created successfully', 201);

        } catch (\Exception $e) {
            Log::error('Failed to create permission', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create permission', $e->getMessage());
        }
    }

   /**
 * Display the specified permission
 *
 * Query Parameters:
 * - include_audits: Include full audit trail (default: false)
 * - audit_limit: Number of audit records (default: 20)
 * - audit_type: Filter audits by type (created, updated, permission_renamed, etc.)
 * - audit_from: Filter audits from date (Y-m-d)
 * - audit_to: Filter audits to date (Y-m-d)
 * - audit_page: Page number for audit pagination
 */
public function show(Request $request, Permission $permission)
{
    try {
        $user = auth()->user();

        // Check territorial access
        if (!$this->canAccessPermission($user, $permission)) {
            return errorResponse('You do not have permission to view this permission', 403);
        }

        $permission->load(['module', 'submodule', 'subSubmodule']);

        $permissionData = [
            'id' => $permission->id,
            'name' => $permission->name,
            'formatted_name' => $permission->formatted_name,
            'action' => $permission->action,
            'territory_scope' => $permission->territory_scope,
            'module' => $permission->module ? [
                'id' => $permission->module->id,
                'name' => $permission->module->name,
                'description' => $permission->module->description,
            ] : null,
            'submodule' => $permission->submodule ? [
                'id' => $permission->submodule->id,
                'title' => $permission->submodule->title,
                'path' => $permission->submodule->path,
                'description' => $permission->submodule->description,
            ] : null,
            'sub_submodule' => $permission->subSubmodule ? [
                'id' => $permission->subSubmodule->id,
                'title' => $permission->subSubmodule->title,
                'path' => $permission->subSubmodule->path,
                'description' => $permission->subSubmodule->description,
            ] : null,
            'roles_count' => $permission->roles()->count(),
            'created_at' => $permission->created_at,
            'updated_at' => $permission->updated_at,
        ];

        // Include audit trails if requested
        if ($request->boolean('include_audits', false)) {
            $auditLimit = $request->input('audit_limit', 20);

            // Build audit query
            $auditQuery = $permission->audits()
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

                $permissionData['audit_trail'] = [
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
                $permissionData['audit_trail'] = $auditQuery->latest()
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
            $permissionData['audit_summary'] = [
                'total_changes' => $permission->audits()->count(),
                'last_modified_by' => $permission->getLastModifiedBy(),
            ];
        }

        return successResponse('Permission retrieved successfully', $permissionData);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve permission', [
            'permission_id' => $permission->id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve permission', $e->getMessage());
    }
}

    /**
     * Update the specified permission
     */
    public function update(Request $request, Permission $permission)
    {
        $validator = Validator::make($request->all(), [
            'module_id' => 'required|exists:modules,id',
            'submodule_id' => 'required|exists:submodules,id',
            'sub_submodule_id' => 'nullable|exists:sub_submodules,id',
            'action' => 'required|string|in:create,read,update,delete,approve,export,import',
            'territory_scope' => 'required|in:diocese,region,subregion,church',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = auth()->user();

            // Check territorial access
            if (!$this->canAccessPermission($user, $permission)) {
                return errorResponse('You do not have permission to update this permission', 403);
            }

            // Check if user can manage the new territorial scope
            if (!$this->canManageTerritorialScope($user, $request->territory_scope)) {
                return errorResponse('You do not have permission to set this territorial scope', 403);
            }

            // Generate new permission name
            $module = Module::find($request->module_id);
            $submodule = Submodule::find($request->submodule_id);
            $subSubmodule = $request->sub_submodule_id ? SubSubmodule::find($request->sub_submodule_id) : null;

            $permissionName = strtolower($module->name) . '.' . strtolower($submodule->title);
            if ($subSubmodule) {
                $permissionName .= '.' . strtolower($subSubmodule->title);
            }
            $permissionName .= '.' . strtolower($request->action);

            // Check for conflicts (excluding current permission)
            $conflictingPermission = Permission::where([
                'module_id' => $request->module_id,
                'submodule_id' => $request->submodule_id,
                'sub_submodule_id' => $request->sub_submodule_id,
                'action' => $request->action,
                'territory_scope' => $request->territory_scope,
            ])->where('id', '!=', $permission->id)->first();

            if ($conflictingPermission) {
                return errorResponse('A permission with these details already exists', 400);
            }

            // Update permission
            $permission->update([
                'name' => $permissionName,
                'module_id' => $request->module_id,
                'submodule_id' => $request->submodule_id,
                'sub_submodule_id' => $request->sub_submodule_id,
                'action' => $request->action,
                'territory_scope' => $request->territory_scope,
            ]);

            $permission->load(['module', 'submodule', 'subSubmodule']);

            Log::info('Permission updated successfully', [
                'permission_id' => $permission->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($permission, 'Permission updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update permission', $e->getMessage());
        }
    }

    /**
     * Remove the specified permission
     */
    public function destroy(Permission $permission)
    {
        try {
            $user = auth()->user();

            // Check territorial access
            if (!$this->canAccessPermission($user, $permission)) {
                return errorResponse('You do not have permission to delete this permission', 403);
            }

            // Check if permission is assigned to any roles
            $rolesCount = $permission->roles()->count();
            if ($rolesCount > 0) {
                return errorResponse(
                    "Cannot delete permission. It is assigned to {$rolesCount} role(s).",
                    400
                );
            }

            $permissionName = $permission->name;
            $permission->delete();

            Log::info('Permission deleted successfully', [
                'permission_name' => $permissionName,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse('Permission deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete permission', $e->getMessage());
        }
    }

    /**
     * Get permissions grouped by module structure for role assignment
     */
    public function getGroupedPermissions(Request $request)
    {
        try {
            $user = auth()->user();
            $territoryScope = $request->query('territory_scope');

            // Build query
            $query = Permission::with(['module', 'submodule', 'subSubmodule']);

            // Filter by territory scope if provided
            if ($territoryScope) {
                $query->where('territory_scope', $territoryScope);
            }

            // Apply territorial access control
            if (!$user->hasGlobalAccess()) {
                $userTerritorialLevel = $this->getUserTerritorialLevel($user);
                $query->forTerritoryLevel($userTerritorialLevel);
            }

            $permissions = $query->get();

            // Group by module → submodule → sub-submodule structure
            $grouped = $permissions->groupBy('module.id')->map(function ($modulePermissions, $moduleId) {
                $module = $modulePermissions->first()->module;

                $submodules = $modulePermissions->groupBy('submodule.id')->map(function ($submodulePermissions, $submoduleId) {
                    $submodule = $submodulePermissions->first()->submodule;

                    // Check if we have sub-submodules
                    $hasSubSubmodules = $submodulePermissions->some('sub_submodule_id');

                    if ($hasSubSubmodules) {
                        $subSubmodules = $submodulePermissions->groupBy('sub_submodule_id')->map(function ($subSubmodulePermissions, $subSubmoduleId) {
                            $subSubmodule = $subSubmodulePermissions->first()->subSubmodule;

                            return [
                                'id' => $subSubmodule ? $subSubmodule->id : null,
                                'title' => $subSubmodule ? $subSubmodule->title : 'General',
                                'actions' => $subSubmodulePermissions->pluck('action')->unique()->values()->toArray(),
                            ];
                        });

                        return [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'sub_submodules' => $subSubmodules->values(),
                        ];
                    } else {
                        return [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'actions' => $submodulePermissions->pluck('action')->unique()->values()->toArray(),
                        ];
                    }
                });

                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'submodules' => $submodules->values(),
                ];
            });

            return successResponse('Grouped permissions retrieved successfully', [
                'modules' => $grouped->values(),
                'territory_scope' => $territoryScope,
                'total_permissions' => $permissions->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve grouped permissions', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve grouped permissions', $e->getMessage());
        }
    }

    /**
     * Get permissions for role management with full permission objects including IDs
     * This endpoint is specifically designed for role permission assignment UI
     * 
     * Query Parameters:
     * - territory_scope: Required - Filter by territory scope (diocese, region, subregion, church)
     * 
     * Returns permissions as objects with id, action, and territory_scope fields
     */
    public function getPermissionsForRoleManagement(Request $request)
    {
        try {
            // Validate required parameter
            $validator = Validator::make($request->all(), [
                'territory_scope' => 'required|in:diocese,region,subregion,church',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $user = auth()->user();
            $territoryScope = $request->query('territory_scope');

            // Build query with relationships
            $query = Permission::with(['module', 'submodule', 'subSubmodule'])
                ->where('territory_scope', $territoryScope);

            // Apply territorial access control
            if (!$user->hasGlobalAccess()) {
                $userTerritorialLevel = $this->getUserTerritorialLevel($user);
                
                // Ensure user can access this territory scope
                if (!$this->canManageTerritorialScope($user, $territoryScope)) {
                    return errorResponse('You do not have permission to view permissions for this territory scope', 403);
                }
            }

            $permissions = $query->orderBy('module_id')
                ->orderBy('submodule_id')
                ->orderBy('action')
                ->get();

            // Group by module → submodule → sub-submodule structure
            $grouped = $permissions->groupBy('module.id')->map(function ($modulePermissions) {
                $module = $modulePermissions->first()->module;

                $submodules = $modulePermissions->groupBy('submodule.id')->map(function ($submodulePermissions) {
                    $submodule = $submodulePermissions->first()->submodule;

                    // Check if we have sub-submodules
                    $hasSubSubmodules = $submodulePermissions->some('sub_submodule_id');

                    if ($hasSubSubmodules) {
                        // Group by sub-submodule
                        $subSubmodules = $submodulePermissions->groupBy('sub_submodule_id')->map(function ($subSubmodulePermissions) {
                            $subSubmodule = $subSubmodulePermissions->first()->subSubmodule;

                            // Return permission objects with IDs
                            $permissions = $subSubmodulePermissions->map(function ($permission) {
                                return [
                                    'id' => $permission->id,
                                    'action' => $permission->action,
                                    'territory_scope' => $permission->territory_scope,
                                    'name' => $permission->name,
                                ];
                            })->values();

                            return [
                                'id' => $subSubmodule ? $subSubmodule->id : null,
                                'title' => $subSubmodule ? $subSubmodule->title : 'General',
                                'permissions' => $permissions,
                            ];
                        });

                        return [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'sub_submodules' => $subSubmodules->values(),
                        ];
                    } else {
                        // Return permission objects with IDs
                        $permissions = $submodulePermissions->map(function ($permission) {
                            return [
                                'id' => $permission->id,
                                'action' => $permission->action,
                                'territory_scope' => $permission->territory_scope,
                                'name' => $permission->name,
                            ];
                        })->values();

                        return [
                            'id' => $submodule->id,
                            'title' => $submodule->title,
                            'permissions' => $permissions,
                        ];
                    }
                });

                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'icon' => $module->icon ?? null,
                    'submodules' => $submodules->values(),
                ];
            });

            return successResponse('Permissions for role management retrieved successfully', [
                'territory_scope' => $territoryScope,
                'modules' => $grouped->values(),
                'total_permissions' => $permissions->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve permissions for role management', [
                'user_id' => auth()->id(),
                'territory_scope' => $request->query('territory_scope'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to retrieve permissions for role management', $e->getMessage());
        }
    }

    // === HELPER METHODS ===

    /**
     * Get user's territorial level
     */
    private function getUserTerritorialLevel($user): string
    {
        if ($user->hasGlobalAccess()) {
            return 'diocese';
        }

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
     * Check if user can manage specific territorial scope
     */
    private function canManageTerritorialScope($user, string $scope): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $userLevel = $this->getUserTerritorialLevel($user);
        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];

        $userIndex = array_search($userLevel, $levelHierarchy);
        $scopeIndex = array_search($scope, $levelHierarchy);

        return $userIndex !== false && $scopeIndex !== false && $userIndex >= $scopeIndex;
    }

    /**
     * Check if user can access specific permission
     */
    private function canAccessPermission($user, Permission $permission): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return $this->canManageTerritorialScope($user, $permission->territory_scope);
    }
}