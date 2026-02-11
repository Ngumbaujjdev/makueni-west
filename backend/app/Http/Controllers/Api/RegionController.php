<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Enums\TerritoryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RegionController extends Controller
{
    /**
     * Display a listing of regions
     */
    public function index(Request $request)
    {
        try {
            $query = Territory::where('territory_type', TerritoryType::REGION)
                ->with(['parent:id,name', 'creator:id,firstname,lastname']);

            // Filter by diocese
            if ($request->filled('diocese_id')) {
                $query->where('parent_territory_id', $request->diocese_id);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by name
            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->input('per_page', 15);
            
            // ============================================================
            // ✅ SUPPORT FOR ?all=true (for dropdowns)
            // ============================================================
            if ($request->has('all') && $request->boolean('all')) {
                // Return all regions without pagination
                $regions = $query->get();
                
                // Add subregions count
                $regions->transform(function ($region) {
                    $region->subregions_count = $region->children()
                        ->where('territory_type', TerritoryType::SUBREGION)
                        ->count();
                    return $region;
                });
                
                return successResponse('Regions retrieved successfully', [
                    'regions' => $regions,
                    'total' => $regions->count()
                ]);
            }
            
            // Regular pagination
            $regions = $query->paginate($perPage);

            // Add subregions count
            $regions->getCollection()->transform(function ($region) {
                $region->subregions_count = $region->children()
                    ->where('territory_type', TerritoryType::SUBREGION)
                    ->count();
                return $region;
            });

            return successResponse('Regions retrieved successfully', [
                'regions' => $regions->items(),
                'pagination' => [
                    'current_page' => $regions->currentPage(),
                    'last_page' => $regions->lastPage(),
                    'per_page' => $regions->perPage(),
                    'total' => $regions->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve regions', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to retrieve regions', $e->getMessage());
        }
    }

    /**
     * Store a newly created region
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'parent_id' => 'required|exists:territories,id',
            'code' => 'nullable|string|max:20|unique:territories,code',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'postal_code' => 'nullable|string|max:10',
            'town' => 'nullable|string|max:255',
            'county' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'established_date' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Validate parent is a Diocese
            $parent = Territory::find($request->parent_id);
            if ($parent->territory_type !== TerritoryType::DIOCESE) {
                return errorResponse('Parent must be a Diocese', 400);
            }

            $authUser = $request->user();
            DB::beginTransaction();

            // Generate code if not provided
            $code = $request->code ?? $parent->generateChildCode(TerritoryType::REGION);

            $region = Territory::create([
                'name' => $request->name,
                'code' => $code,
                'territory_type' => TerritoryType::REGION,
                'parent_territory_id' => $request->parent_id,
                'is_active' => true,
                'address' => $request->address,
                'phone' => $request->phone,
                'email' => $request->email,
                'postal_code' => $request->postal_code,
                'town' => $request->town,
                'county' => $request->county,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'established_date' => $request->established_date,
                'description' => $request->description,
                'created_by' => $authUser->id,
            ]);

            DB::commit();

            Log::info('Region created successfully', [
                'region_id' => $region->id,
                'region_name' => $region->name,
                'parent_diocese' => $parent->name,
                'created_by' => $authUser->id
            ]);

            return createdResponse($region, 'Region created successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create region', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to create region', $e->getMessage());
        }
    }

    /**
     * Display the specified region
     */
    public function show(Territory $region)
    {
        try {
            if ($region->territory_type !== TerritoryType::REGION) {
                return errorResponse('Territory is not a region', 400);
            }

            $region->load(['parent:id,name', 'creator:id,firstname,lastname', 'updater:id,firstname,lastname']);

            // Add statistics
            $region->subregions_count = $region->children()
                ->where('territory_type', TerritoryType::SUBREGION)
                ->count();

            $region->total_churches = Territory::where('full_path', 'like', '%' . $region->name . '%')
                ->where('territory_type', TerritoryType::CHURCH)
                ->count();

            return successResponse('Region retrieved successfully', $region);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve region', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to retrieve region', $e->getMessage());
        }
    }

    /**
     * Update the specified region
     */
    public function update(Request $request, Territory $region)
    {
        if ($region->territory_type !== TerritoryType::REGION) {
            return errorResponse('Territory is not a region', 400);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:20|unique:territories,code,' . $region->id,
            'parent_id' => 'sometimes|required|exists:territories,id',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Validate new parent is Diocese if changing parent
            if ($request->filled('parent_id')) {
                $newParent = Territory::find($request->parent_id);
                if ($newParent->territory_type !== TerritoryType::DIOCESE) {
                    return errorResponse('Parent must be a Diocese', 400);
                }
            }

            $authUser = $request->user();
            $updateData = $request->only([
                'name', 'code', 'parent_id', 'address', 'phone', 'email',
                'postal_code', 'town', 'county', 'latitude', 'longitude',
                'established_date', 'description', 'is_active'
            ]);
            $updateData['updated_by'] = $authUser->id;

            $region->update($updateData);

            Log::info('Region updated successfully', [
                'region_id' => $region->id,
                'updated_by' => $authUser->id
            ]);

            return updatedResponse($region->fresh(), 'Region updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update region', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to update region', $e->getMessage());
        }
    }

    /**
     * Remove the specified region
     */
    public function destroy(Territory $region)
    {
        if ($region->territory_type !== TerritoryType::REGION) {
            return errorResponse('Territory is not a region', 400);
        }

        try {
            if ($region->children()->exists()) {
                return errorResponse('Cannot delete region with existing subregions. Please delete all subregions first.', 400);
            }

            $regionName = $region->name;
            $region->delete();

            Log::info('Region deleted successfully', ['region_name' => $regionName]);
            return deleteResponse('Region deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete region', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to delete region', $e->getMessage());
        }
    }

    /**
     * Get all subregions under a region
     */
    public function getSubRegions(Request $request, Territory $region)
    {
        try {
            if ($region->territory_type !== TerritoryType::REGION) {
                return errorResponse('Territory is not a region', 400);
            }

            $query = $region->children()
                ->where('territory_type', TerritoryType::SUBREGION)
                ->with(['creator:id,firstname,lastname']);

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $subregions = $query->orderBy('name')->get();

            // Add churches count
            $subregions->transform(function ($subregion) {
                $subregion->churches_count = $subregion->children()
                    ->where('territory_type', TerritoryType::CHURCH)
                    ->count();
                return $subregion;
            });

            return successResponse('SubRegions retrieved successfully', [
                'region' => ['id' => $region->id, 'name' => $region->name],
                'subregions' => $subregions
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve subregions', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to retrieve subregions', $e->getMessage());
        }
    }
}
