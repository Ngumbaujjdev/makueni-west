<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Enums\TerritoryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DioceseController extends Controller
{
    /**
     * Display a listing of dioceses
     */
    public function index(Request $request)
    {
        try {
            $query = Territory::where('territory_type', TerritoryType::DIOCESE)
                ->with(['creator:id,firstname,lastname', 'updater:id,firstname,lastname']);

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
                // Return all dioceses without pagination
                $dioceses = $query->get();
                
                // Add regions count to each diocese
                $dioceses->transform(function ($diocese) {
                    $diocese->regions_count = $diocese->children()
                        ->where('territory_type', TerritoryType::REGION)
                        ->count();
                    return $diocese;
                });
                
                return successResponse('Dioceses retrieved successfully', [
                    'dioceses' => $dioceses,
                    'total' => $dioceses->count()
                ]);
            }
            
            // Regular pagination
            $dioceses = $query->paginate($perPage);

            // Add regions count to each diocese
            $dioceses->getCollection()->transform(function ($diocese) {
                $diocese->regions_count = $diocese->children()
                    ->where('territory_type', TerritoryType::REGION)
                    ->count();
                return $diocese;
            });

            return successResponse('Dioceses retrieved successfully', [
                'dioceses' => $dioceses->items(),
                'pagination' => [
                    'current_page' => $dioceses->currentPage(),
                    'last_page' => $dioceses->lastPage(),
                    'per_page' => $dioceses->perPage(),
                    'total' => $dioceses->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve dioceses', [
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve dioceses', $e->getMessage());
        }
    }

    /**
     * Store a newly created diocese
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:territories,code',
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
            $authUser = $request->user();

            DB::beginTransaction();

            $diocese = Territory::create([
                'name' => $request->name,
                'code' => $request->code,
                'territory_type' => TerritoryType::DIOCESE,
                'parent_territory_id' => null, // Dioceses have no parent
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

            Log::info('Diocese created successfully', [
                'diocese_id' => $diocese->id,
                'diocese_name' => $diocese->name,
                'created_by' => $authUser->id
            ]);

            return createdResponse($diocese, 'Diocese created successfully');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create diocese', [
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create diocese', $e->getMessage());
        }
    }

    /**
     * Display the specified diocese
     */
    public function show(Request $request, Territory $diocese)
    {
        try {
            // Verify it's actually a diocese
            if ($diocese->territory_type !== TerritoryType::DIOCESE) {
                return errorResponse('Territory is not a diocese', 400);
            }

            $diocese->load([
                'creator:id,firstname,lastname',
                'updater:id,firstname,lastname'
            ]);

            // Add statistics
            $diocese->regions_count = $diocese->children()
                ->where('territory_type', TerritoryType::REGION)
                ->count();

            $diocese->total_churches = Territory::where('full_path', 'like', $diocese->name . '%')
                ->where('territory_type', TerritoryType::CHURCH)
                ->count();

            return successResponse('Diocese retrieved successfully', $diocese);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve diocese', [
                'diocese_id' => $diocese->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve diocese', $e->getMessage());
        }
    }

    /**
     * Update the specified diocese
     */
    public function update(Request $request, Territory $diocese)
    {
        // Verify it's actually a diocese
        if ($diocese->territory_type !== TerritoryType::DIOCESE) {
            return errorResponse('Territory is not a diocese', 400);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:20|unique:territories,code,' . $diocese->id,
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
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $authUser = $request->user();

            $updateData = $request->only([
                'name', 'code', 'address', 'phone', 'email',
                'postal_code', 'town', 'county', 'latitude', 'longitude',
                'established_date', 'description', 'is_active'
            ]);

            $updateData['updated_by'] = $authUser->id;

            $diocese->update($updateData);

            Log::info('Diocese updated successfully', [
                'diocese_id' => $diocese->id,
                'updated_by' => $authUser->id,
                'updated_fields' => array_keys($updateData)
            ]);

            return updatedResponse($diocese->fresh(), 'Diocese updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update diocese', [
                'diocese_id' => $diocese->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update diocese', $e->getMessage());
        }
    }

    /**
     * Remove the specified diocese
     */
    public function destroy(Territory $diocese)
    {
        // Verify it's actually a diocese
        if ($diocese->territory_type !== TerritoryType::DIOCESE) {
            return errorResponse('Territory is not a diocese', 400);
        }

        try {
            // Check if diocese has children
            $hasChildren = $diocese->children()->exists();
            if ($hasChildren) {
                return errorResponse('Cannot delete diocese with existing regions. Please delete all regions first.', 400);
            }

            $dioceseName = $diocese->name;
            $diocese->delete();

            Log::info('Diocese deleted successfully', [
                'diocese_name' => $dioceseName
            ]);

            return deleteResponse('Diocese deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete diocese', [
                'diocese_id' => $diocese->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete diocese', $e->getMessage());
        }
    }

    /**
     * Get all regions under a diocese
     */
    public function getRegions(Request $request, Territory $diocese)
    {
        try {
            // Verify it's actually a diocese
            if ($diocese->territory_type !== TerritoryType::DIOCESE) {
                return errorResponse('Territory is not a diocese', 400);
            }

            $query = $diocese->children()
                ->where('territory_type', TerritoryType::REGION)
                ->with(['creator:id,firstname,lastname']);

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $regions = $query->orderBy('name')->get();

            // Add subregions count to each region
            $regions->transform(function ($region) {
                $region->subregions_count = $region->children()
                    ->where('territory_type', TerritoryType::SUBREGION)
                    ->count();
                return $region;
            });

            return successResponse('Regions retrieved successfully', [
                'diocese' => [
                    'id' => $diocese->id,
                    'name' => $diocese->name,
                ],
                'regions' => $regions
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve regions', [
                'diocese_id' => $diocese->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve regions', $e->getMessage());
        }
    }

    /**
     * Get full hierarchy tree for a diocese
     */
    public function getHierarchy(Territory $diocese)
    {
        try {
            // Verify it's actually a diocese
            if ($diocese->territory_type !== TerritoryType::DIOCESE) {
                return errorResponse('Territory is not a diocese', 400);
            }

            // Load full hierarchy: Diocese > Regions > SubRegions > Churches
            $diocese->load([
                'children' => function ($query) {
                    $query->where('territory_type', TerritoryType::REGION)
                        ->with([
                            'children' => function ($q) {
                                $q->where('territory_type', TerritoryType::SUBREGION)
                                    ->with([
                                        'children' => function ($sq) {
                                            $sq->where('territory_type', TerritoryType::CHURCH);
                                        }
                                    ]);
                            }
                        ]);
                }
            ]);

            return successResponse('Diocese hierarchy retrieved successfully', $diocese);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve diocese hierarchy', [
                'diocese_id' => $diocese->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to retrieve diocese hierarchy', $e->getMessage());
        }
    }
}
