<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ModuleGroupController extends Controller
{
    /**
     * Get all module groups with optional territory filtering
     *
     * Query Parameters:
     * - territory_scope: Filter by territory (diocese, region, subregion, church)
     * - include_inactive: Include inactive groups (default: false)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            // Build query
            $query = ModuleGroup::query();

            // Filter by territory scope if provided
            if ($request->has('territory_scope')) {
                $territoryScope = $request->query('territory_scope');
                $validTerritoryLevels = ['diocese', 'region', 'subregion', 'church'];

                if (!in_array($territoryScope, $validTerritoryLevels)) {
                    return errorResponse('Invalid territory scope. Must be one of: diocese, region, subregion, church', 400);
                }

                $query->where('territory_scope', $territoryScope);
            }

            // Filter active/inactive
            if (!$request->boolean('include_inactive', false)) {
                $query->where('is_active', true);
            }

            // Get module groups with modules count
            $moduleGroups = $query->orderBy('order')
                                  ->orderBy('name')
                                  ->withCount('modules')
                                  ->get();

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
                    'modules_count' => $group->modules_count,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ];
            });

            return successResponse('Module groups retrieved successfully', [
                'module_groups' => $groupsData,
                'total_count' => $groupsData->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve module groups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to retrieve module groups', $e->getMessage());
        }
    }

    /**
     * Store a newly created module group
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:module_groups',
            'slug' => 'nullable|string|max:255|unique:module_groups',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'territory_scope' => 'required|string|in:diocese,region,subregion,church',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $moduleGroup = ModuleGroup::create([
                'name' => $request->name,
                'slug' => $request->slug, // Will auto-generate if null
                'icon' => $request->icon,
                'order' => $request->order ?? $this->getNextGroupOrder($request->territory_scope),
                'territory_scope' => $request->territory_scope,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            Log::info('Module group created successfully', [
                'module_group_id' => $moduleGroup->id,
                'module_group_name' => $moduleGroup->name,
                'territory_scope' => $moduleGroup->territory_scope,
                'created_by' => auth()->id()
            ]);

            return createdResponse('Module group created successfully', [
                'module_group' => [
                    'id' => $moduleGroup->id,
                    'name' => $moduleGroup->name,
                    'slug' => $moduleGroup->slug,
                    'icon' => $moduleGroup->icon,
                    'order' => $moduleGroup->order,
                    'territory_scope' => $moduleGroup->territory_scope,
                    'description' => $moduleGroup->description,
                    'is_active' => $moduleGroup->is_active,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create module group', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('Failed to create module group', $e->getMessage());
        }
    }

    /**
     * Display the specified module group
     *
     * @param ModuleGroup $moduleGroup
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(ModuleGroup $moduleGroup)
    {
        try {
            // Load modules with counts
            $moduleGroup->load([
                'modules' => function($query) {
                    $query->active()
                          ->orderBy('number')
                          ->orderBy('name')
                          ->withCount(['submodules', 'permissions']);
                }
            ]);

            $groupData = [
                'id' => $moduleGroup->id,
                'name' => $moduleGroup->name,
                'slug' => $moduleGroup->slug,
                'icon' => $moduleGroup->icon,
                'order' => $moduleGroup->order,
                'territory_scope' => $moduleGroup->territory_scope,
                'description' => $moduleGroup->description,
                'is_active' => $moduleGroup->is_active,
                'modules_count' => $moduleGroup->modules->count(),
                'modules' => $moduleGroup->modules->map(function($module) {
                    return [
                        'id' => $module->id,
                        'name' => $module->name,
                        'icon' => $module->icon,
                        'number' => $module->number,
                        'description' => $module->description,
                        'is_active' => $module->is_active,
                        'submodules_count' => $module->submodules_count,
                        'permissions_count' => $module->permissions_count,
                    ];
                }),
                'created_at' => $moduleGroup->created_at,
                'updated_at' => $moduleGroup->updated_at,
            ];

            return successResponse('Module group retrieved successfully', $groupData);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve module group', [
                'module_group_id' => $moduleGroup->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve module group', $e->getMessage());
        }
    }

    /**
     * Update the specified module group
     *
     * @param Request $request
     * @param ModuleGroup $moduleGroup
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, ModuleGroup $moduleGroup)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:module_groups,name,' . $moduleGroup->id,
            'slug' => 'nullable|string|max:255|unique:module_groups,slug,' . $moduleGroup->id,
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'territory_scope' => 'required|string|in:diocese,region,subregion,church',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $moduleGroup->update([
                'name' => $request->name,
                'slug' => $request->slug,
                'icon' => $request->icon,
                'order' => $request->order,
                'territory_scope' => $request->territory_scope,
                'description' => $request->description,
                'is_active' => $request->input('is_active', $moduleGroup->is_active),
            ]);

            Log::info('Module group updated successfully', [
                'module_group_id' => $moduleGroup->id,
                'updated_by' => auth()->id()
            ]);

            return updatedResponse($moduleGroup->fresh(), 'Module group updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update module group', [
                'module_group_id' => $moduleGroup->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update module group', $e->getMessage());
        }
    }

    /**
     * Remove the specified module group
     *
     * @param ModuleGroup $moduleGroup
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(ModuleGroup $moduleGroup)
    {
        try {
            // Check if module group has modules
            $modulesCount = $moduleGroup->modules()->count();
            if ($modulesCount > 0) {
                return errorResponse(
                    "Cannot delete module group. It has {$modulesCount} module(s). Please delete or reassign modules first.",
                    400
                );
            }

            $groupName = $moduleGroup->name;
            $moduleGroup->delete();

            Log::info('Module group deleted successfully', [
                'module_group_name' => $groupName,
                'deleted_by' => auth()->id()
            ]);

            return deleteResponse('Module group deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete module group', [
                'module_group_id' => $moduleGroup->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete module group', $e->getMessage());
        }
    }

    /**
     * Update module group order
     *
     * @param Request $request
     * @param ModuleGroup $moduleGroup
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrder(Request $request, ModuleGroup $moduleGroup)
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $newOrder = $request->order;
            $oldOrder = $moduleGroup->order;

            // If the order hasn't changed, no need to update
            if ($oldOrder == $newOrder) {
                return successResponse('Module group order is already set to this value');
            }

            // Update the order
            $moduleGroup->update(['order' => $newOrder]);

            Log::info('Module group order updated successfully', [
                'module_group_id' => $moduleGroup->id,
                'old_order' => $oldOrder,
                'new_order' => $newOrder,
                'updated_by' => auth()->id(),
            ]);

            return successResponse('Module group order updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update module group order', [
                'module_group_id' => $moduleGroup->id,
                'requested_order' => $request->order,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return serverErrorResponse('Failed to update module group order', $e->getMessage());
        }
    }

    /**
     * Get module groups by territory scope
     *
     * @param Request $request
     * @param string $territoryScope
     * @return \Illuminate\Http\JsonResponse
     */
    public function getByTerritory(Request $request, string $territoryScope)
    {
        try {
            $validTerritoryLevels = ['diocese', 'region', 'subregion', 'church'];

            if (!in_array($territoryScope, $validTerritoryLevels)) {
                return errorResponse('Invalid territory scope. Must be one of: diocese, region, subregion, church', 400);
            }

            // Get module groups for this territory
            $moduleGroups = ModuleGroup::where('territory_scope', $territoryScope)
                ->where('is_active', true)
                ->orderBy('order')
                ->orderBy('name')
                ->withCount('modules')
                ->get();

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
                    'modules_count' => $group->modules_count,
                ];
            });

            return successResponse('Module groups retrieved successfully', [
                'module_groups' => $groupsData,
                'territory_scope' => $territoryScope,
                'total_count' => $groupsData->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve module groups by territory', [
                'territory_scope' => $territoryScope,
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
     * Get next available order number for a territory scope
     *
     * @param string $territoryScope
     * @return int
     */
    private function getNextGroupOrder(string $territoryScope): int
    {
        $lastGroup = ModuleGroup::where('territory_scope', $territoryScope)
                                ->orderBy('order', 'desc')
                                ->first();

        return $lastGroup ? $lastGroup->order + 1 : 1;
    }
}
