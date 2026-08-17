<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TerritoryController extends Controller
{
    /**
     * Display a listing of territories with hierarchical filtering
     */
     /**
 * Display a listing of territories with hierarchical structure
 * Filtered by user's territorial access level (like modules)
 */
public function index(Request $request)
{
    try {
       $user = $request->user();

        // Determine effective territory level (SAME LOGIC AS MODULES!)
        $superAdminConfig = \App\Models\SuperAdminConfig::where('user_id', $user->id)
                                                        ->where('global_access', true)
                                                        ->first();

        if ($superAdminConfig) {
            // ✅ Global admin sees ALL dioceses (root level)
            $effectiveTerritoryLevel = 'diocese';
            $rootTerritories = Territory::where('territory_type', 'diocese')
                ->where('is_active', true)
                ->orderBy('name')
                ->with([
                    'children' => function($query) {
                        $query->active()->orderBy('name')
                            ->with([
                                'children' => function($subQuery) {
                                    $subQuery->active()->orderBy('name')
                                        ->with(['children' => function($churchQuery) {
                                            $churchQuery->active()->orderBy('name');
                                        }]);
                                }
                            ]);
                    }
                ])
                ->get();
        } else {
            // ✅ Regular user - get their highest territorial level
            $effectiveTerritoryLevel = $this->getUserHighestTerritorialLevel($user);

            // Get accessible territories based on user's assignments
            $accessibleTerritories = $this->getUserAccessibleTerritories($user);
            $accessibleIds = $accessibleTerritories->pluck('id');

            // Get root territories for this user (their assigned territories)
            $rootTerritories = Territory::whereIn('id', $accessibleIds)
                ->where(function($query) use ($accessibleIds) {
                    // Include territories where parent is not in accessible list (these are roots for this user)
                    $query->whereNull('parent_territory_id')
                          ->orWhereNotIn('parent_territory_id', $accessibleIds);
                })
                ->where('is_active', true)
                ->orderBy('territory_type')
                ->orderBy('name')
                ->with([
                    'children' => function($query) use ($accessibleIds) {
                        $query->whereIn('id', $accessibleIds)
                            ->active()
                            ->orderBy('name')
                            ->with([
                                'children' => function($subQuery) use ($accessibleIds) {
                                    $subQuery->whereIn('id', $accessibleIds)
                                        ->active()
                                        ->orderBy('name')
                                        ->with(['children' => function($churchQuery) use ($accessibleIds) {
                                            $churchQuery->whereIn('id', $accessibleIds)
                                                ->active()
                                                ->orderBy('name');
                                        }]);
                                }
                            ]);
                    }
                ])
                ->get();
        }

        // Format the hierarchical response
        $territoriesData = $rootTerritories->map(function($territory) {
            return $this->buildTerritoryTree($territory);
        });

        return successResponse('Territories retrieved successfully', [
            'territories' => $territoriesData,
            'territory_level' => $effectiveTerritoryLevel,
            'is_global_admin' => $superAdminConfig ? true : false,
            'total_territories' => $territoriesData->count(),
            'note' => 'Hierarchical structure filtered by user territorial access'
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve territories', [
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return serverErrorResponse('Failed to retrieve territories', $e->getMessage());
    }
}

/**
 * Get user's highest territorial level from their active assignments
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

    // Return highest level user has access to
    foreach (array_reverse($levelHierarchy) as $level) {
        if ($userLevels->contains($level)) {
            return $level;
        }
    }

    return 'church'; // Default to lowest level
}
    /**
     * Store a newly created territory
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'territory_type' => 'required|in:diocese,region,subregion,church',
            'parent_territory_id' => 'nullable|exists:territories,id',
            'code' => 'nullable|string|max:50|unique:territories',
            'description' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
           $user = $request->user();

            // Validate territorial hierarchy (FLEXIBLE - churches can be under diocese/region/subregion)
            if (!$this->validateTerritorialHierarchy($request->territory_type, $request->parent_territory_id)) {
                return errorResponse('Invalid territorial hierarchy. Check parent-child relationship.', 400);
            }

            // Check if user can create territories at this level
            if (!$this->canManageTerritorialLevel($user, $request->territory_type)) {
                return errorResponse('You do not have permission to create territories at this level', 403);
            }

            // If parent specified, check if user can manage parent territory
            if ($request->parent_territory_id) {
                $parentTerritory = Territory::find($request->parent_territory_id);
                if (!$this->canAccessTerritory($user, $parentTerritory)) {
                    return errorResponse('You do not have permission to create territories under this parent', 403);
                }
            }

            $territory = Territory::create([
                'name' => $request->name,
                'territory_type' => $request->territory_type,
                'parent_territory_id' => $request->parent_territory_id,
                'code' => $request->code ?? $this->generateTerritoryCode($request->territory_type),
                'description' => $request->description,
                'location' => $request->location,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'is_active' => $request->input('is_active', true),
            ]);

            Log::info('Territory created successfully', [
                'territory_id' => $territory->id,
                'territory_name' => $territory->name,
                'territory_type' => $territory->territory_type,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Territory created successfully', 201);

        } catch (\Exception $e) {
            Log::error('Failed to create territory', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create territory', $e->getMessage());
        }
    }

    /**
     * Display the specified territory with detailed information
     */
   /**
 * Display the specified territory with detailed information
 *
 * Query Parameters:
 * - include_audits: Include full audit trail (default: false)
 * - audit_limit: Number of audit records (default: 20)
 * - audit_type: Filter audits by type (created, updated, territory_renamed, etc.)
 * - audit_from: Filter audits from date (Y-m-d)
 * - audit_to: Filter audits to date (Y-m-d)
 * - audit_page: Page number for audit pagination
 */
public function show(Request $request, Territory $territory)
{
    try {
       $user = $request->user();

        // Check territorial access
        if (!$this->canAccessTerritory($user, $territory)) {
            return errorResponse('You do not have permission to view this territory', 403);
        }

        $territory->load(['parent', 'children.children', 'userAssignments.user', 'userAssignments.role']);

        $territoryData = [
            'id' => $territory->id,
            'name' => $territory->name,
            'territory_type' => $territory->territory_type,
            'territory_type_name' => $territory->territory_type_name,
            'code' => $territory->code,
            'description' => $territory->description,
            'location' => $territory->location,
            'contact_person' => $territory->contact_person,
            'contact_phone' => $territory->contact_phone,
            'contact_email' => $territory->contact_email,
            'is_active' => $territory->is_active,
            'hierarchy_path' => $territory->hierarchy_path,
            'parent' => $territory->parent ? [
                'id' => $territory->parent->id,
                'name' => $territory->parent->name,
                'type' => $territory->parent->territory_type,
                'code' => $territory->parent->code,
            ] : null,
            'children' => $territory->children->map(function($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'territory_type' => $child->territory_type,
                    'code' => $child->code,
                    'is_active' => $child->is_active,
                    'children_count' => $child->children->count(),
                ];
            }),
            'user_assignments' => $territory->userAssignments->map(function($assignment) {
                return [
                    'id' => $assignment->id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->full_name,
                        'email' => $assignment->user->email,
                        'employee_code' => $assignment->user->employee_code,
                    ],
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'assigned_at' => $assignment->assigned_at,
                ];
            }),
            'statistics' => [
                'total_children' => $territory->children->count(),
                'active_children' => $territory->children->where('is_active', true)->count(),
                'total_assignments' => $territory->userAssignments->count(),
                'active_assignments' => $territory->userAssignments->where('is_active', true)->count(),
            ],
            'created_at' => $territory->created_at,
            'updated_at' => $territory->updated_at,
        ];

        // Include audit trails if requested
        if ($request->boolean('include_audits', false)) {
            $auditLimit = $request->input('audit_limit', 20);

            // Build audit query
            $auditQuery = $territory->audits()
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

                $territoryData['audit_trail'] = [
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
                $territoryData['audit_trail'] = $auditQuery->latest()
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
            $territoryData['audit_summary'] = [
                'total_changes' => $territory->audits()->count(),
                'last_modified_by' => $territory->getLastModifiedBy(),
            ];
        }

        return successResponse('Territory retrieved successfully', $territoryData);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve territory', [
            'territory_id' => $territory->id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve territory', $e->getMessage());
    }
}
    /**
     * Update the specified territory
     */
    public function update(Request $request, Territory $territory)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'territory_type' => 'required|in:diocese,region,subregion,church',
            'parent_territory_id' => 'nullable|exists:territories,id',
            'code' => 'nullable|string|max:50|unique:territories,code,' . $territory->id,
            'description' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
           $user = $request->user();

            // Check territorial access
            if (!$this->canAccessTerritory($user, $territory)) {
                return errorResponse('You do not have permission to update this territory', 403);
            }

            // Validate territorial hierarchy (excluding current territory for parent check)
            if (!$this->validateTerritorialHierarchy($request->territory_type, $request->parent_territory_id, $territory->id)) {
                return errorResponse('Invalid territorial hierarchy. Cannot set territory as its own descendant.', 400);
            }

            $territory->update([
                'name' => $request->name,
                'territory_type' => $request->territory_type,
                'parent_territory_id' => $request->parent_territory_id,
                'code' => $request->code ?? $territory->code,
                'description' => $request->description,
                'location' => $request->location,
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'contact_email' => $request->contact_email,
                'is_active' => $request->input('is_active', $territory->is_active),
            ]);

            Log::info('Territory updated successfully', [
                'territory_id' => $territory->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($territory->fresh(['parent', 'children']), 'Territory updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update territory', [
                'territory_id' => $territory->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update territory', $e->getMessage());
        }
    }

    /**
     * Remove the specified territory
     */
    public function destroy(Territory $territory)
    {
        try {
           $user = $request->user();

            // Check territorial access
            if (!$this->canAccessTerritory($user, $territory)) {
                return errorResponse('You do not have permission to delete this territory', 403);
            }

            // Check if territory has children
            $childrenCount = $territory->children()->count();
            if ($childrenCount > 0) {
                return errorResponse(
                    "Cannot delete territory. It has {$childrenCount} child territory(ies). Please delete or reassign child territories first.",
                    400
                );
            }

            // Check if territory has user assignments
            $assignmentsCount = $territory->userAssignments()->count();
            if ($assignmentsCount > 0) {
                return errorResponse(
                    "Cannot delete territory. It has {$assignmentsCount} user assignment(s). Please remove user assignments first.",
                    400
                );
            }

            $territoryName = $territory->name;
            $territory->delete();

            Log::info('Territory deleted successfully', [
                'territory_name' => $territoryName,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse('Territory deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete territory', [
                'territory_id' => $territory->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete territory', $e->getMessage());
        }
    }

    /**
     * Get territory hierarchy (FLEXIBLE: Diocese → Region → [Optional Subregion] → Church)
     */
    public function getHierarchy(Request $request)
    {
        try {
           $user = $request->user();
            $startFromId = $request->query('territory_id'); // Start from specific territory

            // Base query - get all dioceses OR specific territory
            if ($startFromId) {
                $territory = Territory::find($startFromId);
                if (!$territory || !$this->canAccessTerritory($user, $territory)) {
                    return errorResponse('Territory not found or access denied', 404);
                }

                // Load full hierarchy from this territory down
                $territories = collect([$territory->load(['children.children.children.children'])]);
            } else {
                $query = Territory::with(['children.children.children.children'])
                                 ->where('territory_type', 'diocese');

                // Apply territorial access control
                if (!$user->hasGlobalAccess()) {
                    $accessibleTerritories = $this->getUserAccessibleTerritories($user);
                    $query->whereIn('id', $accessibleTerritories->pluck('id'));
                }

                $territories = $query->orderBy('name')->get();
            }

            $hierarchyData = $territories->map(function ($territory) {
                return $this->buildTerritoryTree($territory);
            });

            return successResponse('Territory hierarchy retrieved successfully', [
                'hierarchy' => $hierarchyData,
                'note' => 'Flexible hierarchy: Churches can be directly under Diocese, Region, or Subregion',
                'total_root_territories' => $hierarchyData->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve territory hierarchy', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve territory hierarchy', $e->getMessage());
        }
    }

    /**
     * Get territories by type with optional parent filtering
     */
    public function getByType(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:diocese,region,subregion,church',
                'parent_id' => 'nullable|exists:territories,id',
                'active_only' => 'boolean',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

           $user = $request->user();
            $type = $request->query('type');
            $parentId = $request->query('parent_id');
            $activeOnly = $request->query('active_only', true);

            $query = Territory::where('territory_type', $type);

            if ($parentId) {
                $query->where('parent_territory_id', $parentId);
            }

            if ($activeOnly) {
                $query->active();
            }

            // Apply territorial access control
            if (!$user->hasGlobalAccess()) {
                $accessibleTerritories = $this->getUserAccessibleTerritories($user);
                $query->whereIn('id', $accessibleTerritories->pluck('id'));
            }

            $territories = $query->orderBy('name')->get();

            $territoriesData = $territories->map(function ($territory) {
                return [
                    'id' => $territory->id,
                    'name' => $territory->name,
                    'code' => $territory->code,
                    'territory_type' => $territory->territory_type,
                    'parent_territory_id' => $territory->parent_territory_id,
                    'is_active' => $territory->is_active,
                ];
            });

            return successResponse('Territories retrieved successfully', [
                'territories' => $territoriesData,
                'type' => $type,
                'parent_id' => $parentId,
                'count' => $territoriesData->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve territories by type', [
                'user_id' => auth()->id(),
                'type' => $request->query('type'),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve territories by type', $e->getMessage());
        }
    }

    // =========================================
    // HELPER METHODS
    // =========================================

    /**
     * Get user's accessible territories based on assignments
     */
    private function getUserAccessibleTerritories($user)
    {
        if ($user->hasGlobalAccess()) {
            return Territory::all();
        }

        $userTerritories = collect();

        foreach ($user->activeAssignments as $assignment) {
            $territory = $assignment->territory;

            // Add the territory itself
            $userTerritories->push($territory);

            // Add all children if user has hierarchical access
            if ($assignment->role->hasHierarchicalAccess()) {
                $children = $territory->getAllChildren();
                $userTerritories = $userTerritories->merge($children);
            }
        }

        return $userTerritories->unique('id');
    }

    /**
     * Check if user can access specific territory
     */
    private function canAccessTerritory($user, Territory $territory): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $accessibleTerritories = $this->getUserAccessibleTerritories($user);
        return $accessibleTerritories->contains('id', $territory->id);
    }

    /**
     * Check if user can manage territories at specific level
     */
    private function canManageTerritorialLevel($user, string $level): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $levelHierarchy = ['church', 'subregion', 'region', 'diocese'];
        $userLevel = $this->getUserHighestTerritorialLevel($user);

        $userIndex = array_search($userLevel, $levelHierarchy);
        $targetIndex = array_search($level, $levelHierarchy);

        return $userIndex !== false && $targetIndex !== false && $userIndex >= $targetIndex;
    }

    /**
     * Validate territorial hierarchy rules (FLEXIBLE - NO STATIC SUBREGIONS)
     */
    private function validateTerritorialHierarchy($type, $parentId, $excludeId = null): bool
    {
        if (!$parentId) {
            return $type === 'diocese'; // Only diocese can have no parent
        }

        $parent = Territory::find($parentId);
        if (!$parent) {
            return false;
        }

        // Check if trying to set territory as its own descendant
        if ($excludeId) {
            $territory = Territory::find($excludeId);
            if ($territory && $parent->isDescendantOf($territory)) {
                return false;
            }
        }

        // FLEXIBLE HIERARCHY - Define valid parent-child relationships
        $validHierarchy = [
            'region' => ['diocese'],                        // Regions under diocese
            'subregion' => ['region'],                      // Subregions under region (OPTIONAL)
            'church' => ['diocese', 'region', 'subregion'], // Churches can be under ANY level (FLEXIBLE)
        ];

        return isset($validHierarchy[$type]) &&
               in_array($parent->territory_type, $validHierarchy[$type]);
    }

    /**
     * Generate territory code
     */
    private function generateTerritoryCode($type): string
    {
        $prefix = strtoupper(substr($type, 0, 3));
        $lastTerritory = Territory::where('territory_type', $type)
                                ->orderBy('id', 'desc')
                                ->first();

        $number = $lastTerritory ? ($lastTerritory->id + 1) : 1;
        return $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Build territory tree recursively
     */
    private function buildTerritoryTree($territory)
    {
        $data = [
            'id' => $territory->id,
            'name' => $territory->name,
            'territory_type' => $territory->territory_type,
            'code' => $territory->code,
            'is_active' => $territory->is_active,
            'user_assignments_count' => $territory->userAssignments()->count(),
        ];

        if ($territory->children->isNotEmpty()) {
            $data['children'] = $territory->children->map(function($child) {
                return $this->buildTerritoryTree($child);
            });
        }

        return $data;
    }
}
