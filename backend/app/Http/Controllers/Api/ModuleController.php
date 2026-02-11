<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\User;
use App\Models\SubSubmodule;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    // =========================================
    // MODULE MANAGEMENT
    // =========================================

 public function index(Request $request)
{
    try {
       $user = $request->user();

        // Check if territory_level is provided as query parameter
        $requestedTerritoryLevel = $request->query('territory_level');
        
        // Initialize variables that will be used in response
        $superAdminConfig = \App\Models\SuperAdminConfig::where('user_id', $user->id)
                                                        ->where('global_access', true)
                                                        ->first();
        
        $hasGlobalTerritory = $user->activeAssignments()
                                  ->whereHas('territory', function($query) {
                                      $query->where('territory_type', 'global');
                                  })
                                  ->exists();
        
        // Determine effective territory level
        if ($requestedTerritoryLevel) {
            // Use the requested territory level from query parameter
            // Validate it's a valid territory type
            $validTerritoryLevels = ['diocese', 'region', 'subregion', 'church'];
            
            if (!in_array($requestedTerritoryLevel, $validTerritoryLevels)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid territory level. Must be one of: diocese, region, subregion, church'
                ], 400);
            }
            
            $effectiveTerritoryLevel = $requestedTerritoryLevel;
            
            Log::info('Using requested territory level from query parameter', [
                'user_id' => $user->id,
                'requested_level' => $requestedTerritoryLevel,
            ]);
        } else {
            // Use existing logic to determine from user assignments
            if ($superAdminConfig || $hasGlobalTerritory) {
                // Global admin ONLY sees diocese-level modules
                $effectiveTerritoryLevel = 'diocese';
            } else {
                // Regular user - get their highest territorial level from assignments
                $effectiveTerritoryLevel = $this->getUserHighestTerritorialLevel($user);
            }
            
            Log::info('Using user-based territory level', [
                'user_id' => $user->id,
                'effective_level' => $effectiveTerritoryLevel,
                'is_global_admin' => $superAdminConfig || $hasGlobalTerritory,
            ]);
        }

        // Get module groups for this territory with their modules
        $moduleGroups = \App\Models\ModuleGroup::where('territory_scope', $effectiveTerritoryLevel)
            ->where('is_active', true)
            ->orderBy('order')
            ->with([
                'modules' => function($query) {
                    $query->active()
                          ->orderBy('number')
                          ->orderBy('name')
                          ->with([
                              'submodules' => function($subQuery) {
                                  $subQuery->active()
                                           ->orderBy('title')
                                           ->with(['subSubmodules' => function($subSubQuery) {
                                               $subSubQuery->active()
                                                           ->orderBy('title');
                                           }]);
                              }
                          ]);
                }
            ])
            ->get();

        // Note: We no longer filter out modules without submodules
        // This allows newly created modules to appear immediately
        // Only filter out groups that have no modules at all
        $moduleGroups = $moduleGroups->filter(function($group) {
            return $group->modules->count() > 0;
        });

        // Format the response
        $groupsData = $moduleGroups->map(function($group) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'icon' => $group->icon,
                'order' => $group->order,
                'description' => $group->description,
                'modules_count' => $group->modules->count(),

                // Modules within this group
                'modules' => $group->modules->map(function($module) {
                    return [
                        'id' => $module->id,
                        'name' => $module->name,
                        'icon' => $module->icon,
                        'number' => $module->number,
                        'description' => $module->description,
                        'is_active' => $module->is_active,
                        'submodules_count' => $module->submodules->count(),

                        // Submodules with full nested structure
                        'submodules' => $module->submodules->map(function($submodule) {
                            return [
                                'id' => $submodule->id,
                                'module_id' => $submodule->module_id,
                                'title' => $submodule->title,
                                'path' => $submodule->path,
                                'description' => $submodule->description,
                                'is_active' => $submodule->is_active,
                                'sub_submodules_count' => $submodule->subSubmodules->count(),

                                // Sub-submodules
                                'sub_submodules' => $submodule->subSubmodules->map(function($subSubmodule) {
                                    return [
                                        'id' => $subSubmodule->id,
                                        'submodule_id' => $subSubmodule->submodule_id,
                                        'title' => $subSubmodule->title,
                                        'path' => $subSubmodule->path,
                                        'description' => $subSubmodule->description,
                                        'is_active' => $subSubmodule->is_active,
                                        'full_path' => $subSubmodule->full_path,
                                    ];
                                })
                            ];
                        })
                    ];
                })
            ];
        })->values();

        return successResponse('Modules retrieved successfully', [
            'module_groups' => $groupsData,
            'territory_scope' => $effectiveTerritoryLevel,
            'is_global_admin' => $superAdminConfig ? true : false,
            'total_groups' => $groupsData->count(),
            'total_modules' => $groupsData->sum('modules_count')
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve modules', [
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

    }
}

    /**
     * Get modules filtered by authenticated user's role permissions
     * Returns ONLY modules/submodules/sub-submodules that the user's current role has permissions for
     * Uses the user's PRIMARY assignment to determine the role
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getModulesForRole(Request $request)
    {
        try {
            $user = $request->user();
            
            // Get the user's PRIMARY assignment
            $primaryAssignment = $user->activeAssignments()
                ->where('assignment_type', 'primary')
                ->with(['territory', 'role'])
                ->first();
            
            if (!$primaryAssignment) {
                return errorResponse('No primary assignment found for user. Please contact your administrator.', 404);
            }
            
            $role = $primaryAssignment->role;
            $territory = $primaryAssignment->territory;
            
            Log::info('Loading modules for user primary assignment', [
                'user_id' => $user->id,
                'employee_code' => $user->employee_code,
                'assignment_id' => $primaryAssignment->id,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'territory_id' => $territory->id,
                'territory_name' => $territory->name,
            ]);
            
            // Get the role's territory level
            $territoryLevel = $role->territory_level;
            
            // Get all permissions for this role
            $rolePermissions = $role->permissions;
            
            // Extract unique module_ids, submodule_ids, and sub_submodule_ids from permissions
            $permittedModuleIds = $rolePermissions->pluck('module_id')->unique()->filter();
            $permittedSubmoduleIds = $rolePermissions->pluck('submodule_id')->unique()->filter();
            $permittedSubSubmoduleIds = $rolePermissions->pluck('sub_submodule_id')->unique()->filter();
            
            Log::info('Filtering modules by role permissions', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'territory_level' => $territoryLevel,
                'permitted_modules' => $permittedModuleIds->toArray(),
                'permitted_submodules' => $permittedSubmoduleIds->toArray(),
                'permitted_sub_submodules' => $permittedSubSubmoduleIds->toArray(),
            ]);
            
            // Get module groups for this territory with their modules
            $moduleGroups = \App\Models\ModuleGroup::where('territory_scope', $territoryLevel)
                ->where('is_active', true)
                ->orderBy('order')
                ->with([
                    'modules' => function($query) use ($permittedModuleIds, $permittedSubmoduleIds, $permittedSubSubmoduleIds) {
                        $query->active()
                              ->whereIn('id', $permittedModuleIds)
                              ->orderBy('number')
                              ->orderBy('name')
                              ->with([
                                  'submodules' => function($subQuery) use ($permittedSubmoduleIds, $permittedSubSubmoduleIds) {
                                      $subQuery->active()
                                               ->whereIn('id', $permittedSubmoduleIds)
                                               ->orderBy('title')
                                               ->with(['subSubmodules' => function($subSubQuery) use ($permittedSubSubmoduleIds) {
                                                   $subSubQuery->active()
                                                               ->whereIn('id', $permittedSubSubmoduleIds)
                                                               ->orderBy('title');
                                               }]);
                                  }
                              ]);
                    }
                ])
                ->get();
            
            // Note: Keep all modules the user has permissions for, even without submodules
            // Filter submodules within each module
            $moduleGroups = $moduleGroups->filter(function($group) {
                $group->modules = $group->modules->filter(function($module) {
                    // Filter sub-submodules within submodules
                    $module->submodules = $module->submodules->filter(function($submodule) {
                        // Keep all submodules - they're already filtered by permissions
                        return true;
                    });

                    // Keep module if user has permission, regardless of submodule count
                    return true;
                });

                return $group->modules->count() > 0;
            });
            
            // Format the response (same structure as index method)
            $groupsData = $moduleGroups->map(function($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'icon' => $group->icon,
                    'order' => $group->order,
                    'description' => $group->description,
                    'modules_count' => $group->modules->count(),
                    
                    // Modules within this group
                    'modules' => $group->modules->map(function($module) {
                        return [
                            'id' => $module->id,
                            'name' => $module->name,
                            'icon' => $module->icon,
                            'number' => $module->number,
                            'description' => $module->description,
                            'is_active' => $module->is_active,
                            'submodules_count' => $module->submodules->count(),
                            
                            // Submodules with full nested structure
                            'submodules' => $module->submodules->map(function($submodule) {
                                return [
                                    'id' => $submodule->id,
                                    'module_id' => $submodule->module_id,
                                    'title' => $submodule->title,
                                    'path' => $submodule->path,
                                    'description' => $submodule->description,
                                    'is_active' => $submodule->is_active,
                                    'sub_submodules_count' => $submodule->subSubmodules->count(),
                                    
                                    // Sub-submodules
                                    'sub_submodules' => $submodule->subSubmodules->map(function($subSubmodule) {
                                        return [
                                            'id' => $subSubmodule->id,
                                            'submodule_id' => $subSubmodule->submodule_id,
                                            'title' => $subSubmodule->title,
                                            'path' => $subSubmodule->path,
                                            'description' => $subSubmodule->description,
                                            'is_active' => $subSubmodule->is_active,
                                            'full_path' => $subSubmodule->full_path,
                                        ];
                                    })
                                ];
                            })
                        ];
                    })
                ];
            })->values();
            
            return successResponse('Modules retrieved successfully for user role', [
                'module_groups' => $groupsData,
                'user_assignment' => [
                    'assignment_id' => $primaryAssignment->id,
                    'territory' => [
                        'id' => $territory->id,
                        'name' => $territory->name,
                        'type' => $territory->territory_type,
                    ],
                    'role' => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'territory_level' => $role->territory_level,
                    ],
                ],
                'territory_scope' => $territoryLevel,
                'total_groups' => $groupsData->count(),
                'total_modules' => $groupsData->sum('modules_count'),
                'filtered_by_permissions' => true,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve modules for user role', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return serverErrorResponse('Failed to retrieve modules for user role', $e->getMessage());
        }
    }


private function getUserHighestTerritorialLevel(User $user): string
{
    // Global admins always get diocese level
    if ($user->hasRole('Global Administrator')) {
        Log::info('User is Global Administrator, returning diocese level', [
            'user_id' => $user->id,
            'employee_code' => $user->employee_code
        ]);
        return 'diocese';
    }

    // Get the user's PRIMARY assignment
    $primaryAssignment = $user->activeAssignments()
        ->where('assignment_type', 'primary')
        ->with(['territory', 'role'])
        ->first();

    if (!$primaryAssignment) {
        Log::warning('No primary assignment found for user', [
            'user_id' => $user->id,
            'employee_code' => $user->employee_code
        ]);

        // Fallback: get highest territory type from all assignments
        $userLevels = $user->activeAssignments()
            ->with('territory')
            ->get()
            ->pluck('territory.territory_type')
            ->filter()
            ->toArray();

        if (empty($userLevels)) {
            Log::warning('No active assignments found, defaulting to church level', [
                'user_id' => $user->id
            ]);
            return 'church';
        }

        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];
        $highestLevel = 'church';

        foreach ($levelHierarchy as $level) {
            if (in_array($level, $userLevels)) {
                $highestLevel = $level;
            }
        }

        Log::info('Using highest level from all assignments (no primary)', [
            'user_id' => $user->id,
            'all_levels' => $userLevels,
            'selected_level' => $highestLevel
        ]);

        return $highestLevel;
    }

    // Get territory type from PRIMARY assignment
    $primaryTerritoryType = $primaryAssignment->territory->territory_type->value;

    // Validate it's a valid territory level
    $validLevels = ['church', 'subregion', 'region', 'diocese'];
    if (!in_array($primaryTerritoryType, $validLevels)) {
        Log::warning('Invalid primary territory type, defaulting to church', [
            'user_id' => $user->id,
            'primary_territory_type' => $primaryTerritoryType,
            'assignment_id' => $primaryAssignment->id
        ]);
        return 'church';
    }

    Log::info('Using PRIMARY assignment territory type', [
        'user_id' => $user->id,
        'employee_code' => $user->employee_code,
        'primary_assignment_id' => $primaryAssignment->id,
        'territory_name' => $primaryAssignment->territory->name,
        'territory_type' => $primaryTerritoryType,
        'role_name' => $primaryAssignment->role->name
    ]);

    return $primaryTerritoryType;
}
/**
     * Store a newly created module
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_group_id' => 'nullable|exists:module_groups,id',
            'name' => 'required|string|max:255|unique:modules',
            'icon' => 'nullable|string|max:100',
            'number' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*.action' => 'required|string|in:create,read,update,delete,approve,export',
            'permissions.*.territory_scope' => 'required|string|in:diocese,region,subregion,church',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Determine the module number
            $moduleNumber = $request->number ?? $this->getNextModuleNumber();

            // Check if the number is already taken
            $existingModule = Module::where('number', $moduleNumber)->first();

            if ($existingModule) {
                // Shift all modules with number >= requested number down by 1
                Module::where('number', '>=', $moduleNumber)
                      ->orderBy('number', 'desc')
                      ->get()
                      ->each(function ($module) {
                          $module->number = $module->number + 1;
                          $module->save();
                      });

                Log::info('Shifted module numbers down', [
                    'starting_from' => $moduleNumber,
                    'affected_modules' => Module::where('number', '>', $moduleNumber)->count(),
                ]);
            }

            // Create the new module
            $module = Module::create([
                'module_group_id' => $request->module_group_id,
                'name' => $request->name,
                'icon' => $request->icon,
                'number' => $moduleNumber,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            // Auto-generate permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                foreach ($request->permissions as $permissionData) {
                    $permissionName = $permissionData['territory_scope'] . '.' .
                                    strtolower(str_replace(' ', '_', $module->name)) . '.' .
                                    $permissionData['action'];

                    Permission::create([
                        'name' => $permissionName,
                        'guard_name' => 'web',
                        'module_id' => $module->id,
                        'action' => $permissionData['action'],
                        'territory_scope' => $permissionData['territory_scope'],
                    ]);
                }

                Log::info('Auto-generated permissions for module', [
                    'module_id' => $module->id,
                    'permissions_count' => count($request->permissions),
                ]);
            }

            DB::commit();

            Log::info('Module created successfully', [
                'module_id' => $module->id,
                'module_name' => $module->name,
                'module_number' => $module->number,
                'created_by' => auth()->id()
            ]);

            return createdResponse([
                'module' => [
                    'id' => $module->id,
                    'module_group_id' => $module->module_group_id,
                    'name' => $module->name,
                    'icon' => $module->icon,
                    'number' => $module->number,
                    'description' => $module->description,
                    'is_active' => $module->is_active,
                ]
            ], 'Module created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create module', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to create module', $e->getMessage());
        }
    }

   /**
 * Display the specified module with full hierarchy
 *
 * Query Parameters:
 * - include_audits: Include full audit trail (default: false)
 * - audit_limit: Number of audit records (default: 20)
 * - audit_type: Filter audits by type (created, updated, module_renamed, module_activated, etc.)
 * - audit_from: Filter audits from date (Y-m-d)
 * - audit_to: Filter audits to date (Y-m-d)
 * - audit_page: Page number for audit pagination
 */
public function show(Request $request, Module $module)
{
    try {
        $module->load([
            'submodules.subSubmodules' => function($query) {
                $query->active()->orderBy('title');
            },
            'submodules' => function($query) {
                $query->active()->orderBy('title');
            }
        ]);

        $moduleData = [
            'id' => $module->id,
            'name' => $module->name,
            'icon' => $module->icon,
            'number' => $module->number,
            'description' => $module->description,
            'is_active' => $module->is_active,
            'submodules' => $module->submodules->map(function($submodule) {
                return [
                    'id' => $submodule->id,
                    'title' => $submodule->title,
                    'path' => $submodule->path,
                    'description' => $submodule->description,
                    'is_active' => $submodule->is_active,
                    'sub_submodules_count' => $submodule->subSubmodules->count(),
                    'sub_submodules' => $submodule->subSubmodules->map(function($subSubmodule) {
                        return [
                            'id' => $subSubmodule->id,
                            'title' => $subSubmodule->title,
                            'path' => $subSubmodule->path,
                            'description' => $subSubmodule->description,
                            'is_active' => $subSubmodule->is_active,
                        ];
                    })
                ];
            }),
            'permissions_count' => $module->permissions()->count(),
            'created_at' => $module->created_at,
            'updated_at' => $module->updated_at,
        ];

        // Include audit trails if requested
        if ($request->boolean('include_audits', false)) {
            $auditLimit = $request->input('audit_limit', 20);

            // Build audit query
            $auditQuery = $module->audits()
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

                $moduleData['audit_trail'] = [
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
                $moduleData['audit_trail'] = $auditQuery->latest()
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
            $moduleData['audit_summary'] = [
                'total_changes' => $module->audits()->count(),
                'last_modified_by' => $module->getLastModifiedBy(),
            ];
        }

        return successResponse('Module retrieved successfully', $moduleData);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve module', [
            'module_id' => $module->id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve module', $e->getMessage());
    }
}
    /**
     * Update the specified module
     */
    public function update(Request $request, Module $module)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:modules,name,' . $module->id,
            'icon' => 'required|string|max:100',
            'number' => 'required|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Check if number is being changed and handle conflicts
            if ($request->number != $module->number) {
                $conflictingModule = Module::where('number', $request->number)
                    ->where('id', '!=', $module->id)
                    ->first();

                if ($conflictingModule) {
                    // Swap numbers
                    $tempNumber = -1 * $conflictingModule->id;
                    $conflictingModule->update(['number' => $tempNumber]);
                    $module->update(['number' => $request->number]);
                    $conflictingModule->update(['number' => $module->getOriginal('number')]);
                } else {
                    $module->update(['number' => $request->number]);
                }
            }

            // Update other fields (module_group_id is NOT updated - modules stay in their group)
            $module->update([
                'name' => $request->name,
                'icon' => $request->icon,
                'description' => $request->description,
                'is_active' => $request->input('is_active', $module->is_active),
            ]);

            DB::commit();

            Log::info('Module updated successfully', [
                'module_id' => $module->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($module->fresh(), 'Module updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update module', [
                'module_id' => $module->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to update module', $e->getMessage());
        }
    }

    /**
     * Remove the specified module
     */
    public function destroy(Module $module)
    {
        try {
            DB::beginTransaction();

            $moduleName = $module->name;
            $deletedSubmodules = 0;
            $deletedSubSubmodules = 0;
            $deletedPermissions = 0;

            // Delete all submodules and their children
            foreach ($module->submodules as $submodule) {
                // Delete sub-submodules and their permissions
                foreach ($submodule->subSubmodules as $subSubmodule) {
                    $deletedPermissions += $subSubmodule->permissions()->count();
                    $subSubmodule->permissions()->delete();
                    $subSubmodule->delete();
                    $deletedSubSubmodules++;
                }

                // Delete submodule permissions
                $deletedPermissions += $submodule->permissions()->count();
                $submodule->permissions()->delete();
                $submodule->delete();
                $deletedSubmodules++;
            }

            // Delete module-level permissions
            $deletedPermissions += $module->permissions()->count();
            $module->permissions()->delete();

            // Finally delete the module itself
            $module->delete();

            DB::commit();

            Log::info('Module deleted successfully with cascade', [
                'module_name' => $moduleName,
                'submodules_deleted' => $deletedSubmodules,
                'sub_submodules_deleted' => $deletedSubSubmodules,
                'permissions_deleted' => $deletedPermissions,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse("Module '{$moduleName}' and all its submodules, sub-submodules, and permissions deleted successfully");

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete module', [
                'module_id' => $module->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete module', $e->getMessage());
        }
    }

    // =========================================
    // SUBMODULE MANAGEMENT
    // =========================================

    /**
     * Get submodules for a specific module
     */
    public function getSubmodules(Module $module)
    {
        try {
            $submodules = $module->submodules()
                               ->withCount(['subSubmodules', 'permissions'])
                               ->active()
                               ->orderBy('title')
                               ->get();

            $submodulesData = $submodules->map(function ($submodule) {
                return [
                    'id' => $submodule->id,
                    'module_id' => $submodule->module_id,
                    'title' => $submodule->title,
                    'path' => $submodule->path,
                    'description' => $submodule->description,
                    'is_active' => $submodule->is_active,
                    'sub_submodules_count' => $submodule->sub_submodules_count,
                    'permissions_count' => $submodule->permissions_count,
                    'created_at' => $submodule->created_at,
                ];
            });

            return successResponse('Submodules retrieved successfully', $submodulesData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve submodules', [
                'module_id' => $module->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve submodules', $e->getMessage());
        }
    }

    /**
     * Store a newly created submodule
     */
    public function storeSubmodule(Request $request, Module $module)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'path' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*.action' => 'required|string|in:create,read,update,delete,approve,export',
            'permissions.*.territory_scope' => 'required|string|in:diocese,region,subregion,church',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check for unique title within the module
            $existingSubmodule = $module->submodules()
                                      ->where('title', $request->title)
                                      ->first();

            if ($existingSubmodule) {
                return errorResponse('A submodule with this title already exists in this module', 400);
            }

            $submodule = Submodule::create([
                'module_id' => $module->id,
                'title' => $request->title,
                'path' => $request->path,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            // Auto-generate permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                foreach ($request->permissions as $permissionData) {
                    $permissionName = $permissionData['territory_scope'] . '.' . 
                                    strtolower(str_replace(' ', '_', $module->name)) . '.' . 
                                    strtolower(str_replace(' ', '_', $submodule->title)) . '.' . 
                                    $permissionData['action'];
                    
                    Permission::create([
                        'name' => $permissionName,
                        'guard_name' => 'web',
                        'module_id' => $module->id,
                        'submodule_id' => $submodule->id,
                        'action' => $permissionData['action'],
                        'territory_scope' => $permissionData['territory_scope'],
                    ]);
                }
                
                Log::info('Auto-generated permissions for submodule', [
                    'submodule_id' => $submodule->id,
                    'module_id' => $module->id,
                    'permissions_count' => count($request->permissions),
                ]);
            }

            Log::info('Submodule created successfully', [
                'submodule_id' => $submodule->id,
                'submodule_title' => $submodule->title,
                'module_id' => $module->id,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Submodule created successfully', 201);

        } catch (\Exception $e) {
            Log::error('Failed to create submodule', [
                'module_id' => $module->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create submodule', $e->getMessage());
        }
    }

    /**
     * Update the specified submodule
     */
    public function updateSubmodule(Request $request, Module $module, Submodule $submodule)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'path' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Verify submodule belongs to module
            if ($submodule->module_id !== $module->id) {
                return errorResponse('Submodule does not belong to this module', 400);
            }

            // Check for unique title within the module (excluding current submodule)
            $existingSubmodule = $module->submodules()
                                      ->where('title', $request->title)
                                      ->where('id', '!=', $submodule->id)
                                      ->first();

            if ($existingSubmodule) {
                return errorResponse('A submodule with this title already exists in this module', 400);
            }

            $submodule->update([
                'title' => $request->title,
                'path' => $request->path,
                'description' => $request->description,
                'is_active' => $request->input('is_active', $submodule->is_active),
            ]);

            Log::info('Submodule updated successfully', [
                'submodule_id' => $submodule->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($submodule->fresh(), 'Submodule updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update submodule', [
                'submodule_id' => $submodule->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update submodule', $e->getMessage());
        }
    }

    /**
     * Remove the specified submodule
     */
    public function destroySubmodule(Module $module, Submodule $submodule)
    {
        try {
            DB::beginTransaction();

            // Verify submodule belongs to module
            if ($submodule->module_id !== $module->id) {
                DB::rollBack();
                return errorResponse('Submodule does not belong to this module', 400);
            }

            $submoduleTitle = $submodule->title;
            $deletedSubSubmodules = 0;
            $deletedPermissions = 0;

            // Delete all sub-submodules and their permissions
            foreach ($submodule->subSubmodules as $subSubmodule) {
                $deletedPermissions += $subSubmodule->permissions()->count();
                $subSubmodule->permissions()->delete();
                $subSubmodule->delete();
                $deletedSubSubmodules++;
            }

            // Delete submodule-level permissions
            $deletedPermissions += $submodule->permissions()->count();
            $submodule->permissions()->delete();

            // Finally delete the submodule itself
            $submodule->delete();

            DB::commit();

            Log::info('Submodule deleted successfully with cascade', [
                'submodule_title' => $submoduleTitle,
                'module_id' => $module->id,
                'sub_submodules_deleted' => $deletedSubSubmodules,
                'permissions_deleted' => $deletedPermissions,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse("Submodule '{$submoduleTitle}' and all its sub-submodules and permissions deleted successfully");

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete submodule', [
                'submodule_id' => $submodule->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete submodule', $e->getMessage());
        }
    }

    // =========================================
    // SUB-SUBMODULE MANAGEMENT
    // =========================================

    /**
     * Get sub-submodules for a specific submodule
     */
    public function getSubSubmodules(Module $module, Submodule $submodule)
    {
        try {
            // Verify submodule belongs to module
            if ($submodule->module_id !== $module->id) {
                return errorResponse('Submodule does not belong to this module', 400);
            }

            $subSubmodules = $submodule->subSubmodules()
                                     ->withCount(['permissions'])
                                     ->active()
                                     ->orderBy('title')
                                     ->get();

            $subSubmodulesData = $subSubmodules->map(function ($subSubmodule) {
                return [
                    'id' => $subSubmodule->id,
                    'submodule_id' => $subSubmodule->submodule_id,
                    'title' => $subSubmodule->title,
                    'path' => $subSubmodule->path,
                    'description' => $subSubmodule->description,
                    'is_active' => $subSubmodule->is_active,
                    'permissions_count' => $subSubmodule->permissions_count,
                    'full_path' => $subSubmodule->full_path,
                    'created_at' => $subSubmodule->created_at,
                ];
            });

            return successResponse('Sub-submodules retrieved successfully', $subSubmodulesData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve sub-submodules', [
                'submodule_id' => $submodule->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve sub-submodules', $e->getMessage());
        }
    }

    /**
     * Store a newly created sub-submodule
     */
    public function storeSubSubmodule(Request $request, Module $module, Submodule $submodule)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'path' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*.action' => 'required|string|in:create,read,update,delete,approve,export',
            'permissions.*.territory_scope' => 'required|string|in:diocese,region,subregion,church',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Verify submodule belongs to module
            if ($submodule->module_id !== $module->id) {
                return errorResponse('Submodule does not belong to this module', 400);
            }

            // Check for unique title within the submodule
            $existingSubSubmodule = $submodule->subSubmodules()
                                            ->where('title', $request->title)
                                            ->first();

            if ($existingSubSubmodule) {
                return errorResponse('A sub-submodule with this title already exists in this submodule', 400);
            }

            $subSubmodule = SubSubmodule::create([
                'submodule_id' => $submodule->id,
                'title' => $request->title,
                'path' => $request->path,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            // Auto-generate permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                foreach ($request->permissions as $permissionData) {
                    $permissionName = $permissionData['territory_scope'] . '.' . 
                                    strtolower(str_replace(' ', '_', $module->name)) . '.' . 
                                    strtolower(str_replace(' ', '_', $submodule->title)) . '.' . 
                                    strtolower(str_replace(' ', '_', $subSubmodule->title)) . '.' . 
                                    $permissionData['action'];
                    
                    Permission::create([
                        'name' => $permissionName,
                        'guard_name' => 'web',
                        'module_id' => $module->id,
                        'submodule_id' => $submodule->id,
                        'sub_submodule_id' => $subSubmodule->id,
                        'action' => $permissionData['action'],
                        'territory_scope' => $permissionData['territory_scope'],
                    ]);
                }
                
                Log::info('Auto-generated permissions for sub-submodule', [
                    'sub_submodule_id' => $subSubmodule->id,
                    'submodule_id' => $submodule->id,
                    'module_id' => $module->id,
                    'permissions_count' => count($request->permissions),
                ]);
            }

            Log::info('Sub-submodule created successfully', [
                'sub_submodule_id' => $subSubmodule->id,
                'sub_submodule_title' => $subSubmodule->title,
                'submodule_id' => $submodule->id,
                'module_id' => $module->id,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Sub-submodule created successfully', 201);

        } catch (\Exception $e) {
            Log::error('Failed to create sub-submodule', [
                'submodule_id' => $submodule->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create sub-submodule', $e->getMessage());
        }
    }

    /**
     * Update the specified sub-submodule
     */
    public function updateSubSubmodule(Request $request, Module $module, Submodule $submodule, SubSubmodule $subSubmodule)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'path' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Verify relationships
            if ($submodule->module_id !== $module->id || $subSubmodule->submodule_id !== $submodule->id) {
                return errorResponse('Invalid module/submodule/sub-submodule relationship', 400);
            }

            // Check for unique title within the submodule (excluding current sub-submodule)
            $existingSubSubmodule = $submodule->subSubmodules()
                                            ->where('title', $request->title)
                                            ->where('id', '!=', $subSubmodule->id)
                                            ->first();

            if ($existingSubSubmodule) {
                return errorResponse('A sub-submodule with this title already exists in this submodule', 400);
            }

            $subSubmodule->update([
                'title' => $request->title,
                'path' => $request->path,
                'description' => $request->description,
                'is_active' => $request->input('is_active', $subSubmodule->is_active),
            ]);

            Log::info('Sub-submodule updated successfully', [
                'sub_submodule_id' => $subSubmodule->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($subSubmodule->fresh(), 'Sub-submodule updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update sub-submodule', [
                'sub_submodule_id' => $subSubmodule->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update sub-submodule', $e->getMessage());
        }
    }

    /**
     * Get single sub-submodule details
     */
    public function showSubSubmodule(Module $module, Submodule $submodule, SubSubmodule $subSubmodule)
    {
        try {
            // Verify relationships
            if ($submodule->module_id !== $module->id || $subSubmodule->submodule_id !== $submodule->id) {
                return errorResponse('Invalid module/submodule/sub-submodule relationship', 400);
            }

            $subSubmoduleData = [
                'id' => $subSubmodule->id,
                'submodule_id' => $subSubmodule->submodule_id,
                'title' => $subSubmodule->title,
                'path' => $subSubmodule->path,
                'description' => $subSubmodule->description,
                'is_active' => $subSubmodule->is_active,
                'full_path' => $subSubmodule->full_path,
                'permissions_count' => $subSubmodule->permissions()->count(),
                'created_at' => $subSubmodule->created_at,
                'updated_at' => $subSubmodule->updated_at,
            ];

            Log::info('Sub-submodule retrieved', [
                'sub_submodule_id' => $subSubmodule->id,
                'requested_by' => auth()->id(),
            ]);

            return successResponse('Sub-submodule retrieved successfully', $subSubmoduleData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve sub-submodule', [
                'sub_submodule_id' => $subSubmodule->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to retrieve sub-submodule', $e->getMessage());
        }
    }

    /**
     * Remove the specified sub-submodule
     */
    public function destroySubSubmodule(Module $module, Submodule $submodule, SubSubmodule $subSubmodule)
    {
        try {
            DB::beginTransaction();

            // Verify relationships
            if ($submodule->module_id !== $module->id || $subSubmodule->submodule_id !== $submodule->id) {
                DB::rollBack();
                return errorResponse('Invalid module/submodule/sub-submodule relationship', 400);
            }

            // Get permissions count before deletion
            $permissionsCount = $subSubmodule->permissions()->count();

            // Delete associated permissions first
            if ($permissionsCount > 0) {
                $subSubmodule->permissions()->delete();

                Log::info('Deleted permissions associated with sub-submodule', [
                    'sub_submodule_id' => $subSubmodule->id,
                    'permissions_count' => $permissionsCount,
                    'deleted_by' => auth()->id()
                ]);
            }

            $subSubmoduleTitle = $subSubmodule->title;
            $subSubmodule->delete();

            DB::commit();

            Log::info('Sub-submodule deleted successfully', [
                'sub_submodule_title' => $subSubmoduleTitle,
                'submodule_id' => $submodule->id,
                'module_id' => $module->id,
                'permissions_deleted' => $permissionsCount,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse('Sub-submodule and associated permissions deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete sub-submodule', [
                'sub_submodule_id' => $subSubmodule->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete sub-submodule', $e->getMessage());
        }
    }

    // =========================================
    // MODULE ORDERING & MOVEMENT
    // =========================================

    /**
     * Update module order number
     */
    public function updateModuleOrder(Request $request, Module $module)
    {
        $validator = Validator::make($request->all(), [
            'number' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $newNumber = $request->number;
            $oldNumber = $module->number;

            // If the number hasn't changed, no need to update
            if ($oldNumber == $newNumber) {
                return successResponse('Module order is already set to this number');
            }

            DB::transaction(function () use ($module, $newNumber, $oldNumber) {
                // Find the module that currently has the target number
                $conflictingModule = Module::where('number', $newNumber)
                                          ->where('id', '!=', $module->id)
                                          ->first();

                if ($conflictingModule) {
                    // Swap: Give the conflicting module a temporary number first
                    $tempNumber = -1 * $conflictingModule->id; // Use negative ID as temp
                    $conflictingModule->update(['number' => $tempNumber]);
                    
                    // Update the current module to the new number
                    $module->update(['number' => $newNumber]);
                    
                    // Give the conflicting module the old number
                    $conflictingModule->update(['number' => $oldNumber]);
                    
                    Log::info('Swapped module order numbers', [
                        'module_1_id' => $module->id,
                        'module_1_old' => $oldNumber,
                        'module_1_new' => $newNumber,
                        'module_2_id' => $conflictingModule->id,
                        'module_2_old' => $newNumber,
                        'module_2_new' => $oldNumber,
                    ]);
                } else {
                    // No conflict, just update the number
                    $module->update(['number' => $newNumber]);
                    
                    Log::info('Module order updated (no conflict)', [
                        'module_id' => $module->id,
                        'old_number' => $oldNumber,
                        'new_number' => $newNumber,
                    ]);
                }
            });

            Log::info('Module order updated successfully', [
                'module_id' => $module->id,
                'new_number' => $newNumber,
                'updated_by' => auth()->id(),
            ]);

            return successResponse('Module order updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update module order', [
                'module_id' => $module->id,
                'requested_number' => $request->number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return serverErrorResponse('Failed to update module order', $e->getMessage());
        }
    }

    /**
     * Move submodule to different module
     */
    public function moveSubmodule(Request $request, Submodule $submodule)
    {
        $validator = Validator::make($request->all(), [
            'new_module_id' => 'required|exists:modules,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $oldModuleId = $submodule->module_id;
            $submodule->update(['module_id' => $request->new_module_id]);

            Log::info('Submodule moved to new module', [
                'submodule_id' => $submodule->id,
                'old_module_id' => $oldModuleId,
                'new_module_id' => $request->new_module_id,
                'moved_by' => auth()->id(),
            ]);

            return successResponse('Submodule moved successfully');

        } catch (\Exception $e) {
            Log::error('Failed to move submodule', [
                'submodule_id' => $submodule->id,
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to move submodule', $e->getMessage());
        }
    }

    /**
     * Get single submodule details
     */
    public function showSubmodule(Submodule $submodule)
    {
        try {
            $submodule->load(['subSubmodules' => function($query) {
                $query->active()->orderBy('title');
            }]);

            $submoduleData = [
                'id' => $submodule->id,
                'module_id' => $submodule->module_id,
                'title' => $submodule->title,
                'path' => $submodule->path,
                'description' => $submodule->description,
                'is_active' => $submodule->is_active,
                'sub_submodules_count' => $submodule->subSubmodules->count(),
                'sub_submodules' => $submodule->subSubmodules->map(function($subSubmodule) {
                    return [
                        'id' => $subSubmodule->id,
                        'submodule_id' => $subSubmodule->submodule_id,
                        'title' => $subSubmodule->title,
                        'path' => $subSubmodule->path,
                        'description' => $subSubmodule->description,
                        'is_active' => $subSubmodule->is_active,
                        'full_path' => $subSubmodule->full_path,
                    ];
                }),
                'created_at' => $submodule->created_at,
                'updated_at' => $submodule->updated_at,
            ];

            Log::info('Submodule retrieved', [
                'submodule_id' => $submodule->id,
                'requested_by' => auth()->id(),
            ]);

            return successResponse('Submodule retrieved successfully', $submoduleData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve submodule', [
                'submodule_id' => $submodule->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return serverErrorResponse('Failed to retrieve submodule', $e->getMessage());
        }
    }

    // =========================================
    // MODULE GROUPS
    // =========================================

    /**
     * Get module groups (for dropdown selection when creating modules)
     * Can be filtered by territory_level query parameter
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getModuleGroups(Request $request)
    {
        try {
            // Check if territory_level is provided as query parameter
            $territoryLevel = $request->query('territory_level');

            // Build query
            $query = \App\Models\ModuleGroup::where('is_active', true)
                                           ->orderBy('order')
                                           ->orderBy('name');

            // Filter by territory if provided
            if ($territoryLevel) {
                $validTerritoryLevels = ['diocese', 'region', 'subregion', 'church'];

                if (!in_array($territoryLevel, $validTerritoryLevels)) {
                    return errorResponse('Invalid territory level. Must be one of: diocese, region, subregion, church', 400);
                }

                $query->where('territory_scope', $territoryLevel);
            }

            $moduleGroups = $query->get();

            // Format response
            $groupsData = $moduleGroups->map(function($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'icon' => $group->icon,
                    'order' => $group->order,
                    'territory_scope' => $group->territory_scope,
                    'description' => $group->description,
                    'is_active' => $group->is_active,
                    'modules_count' => $group->modules()->count(),
                ];
            });

            return successResponse('Module groups retrieved successfully', $groupsData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve module groups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to retrieve module groups', $e->getMessage());
        }
    }

    // =========================================
    // HELPER METHODS
    // =========================================

    /**
     * Get next available module number
     */
    private function getNextModuleNumber(): int
    {
        $lastModule = Module::orderBy('number', 'desc')->first();
        return $lastModule ? $lastModule->number + 1 : 1;
    }
}