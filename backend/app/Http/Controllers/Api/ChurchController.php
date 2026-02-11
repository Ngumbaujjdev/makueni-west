<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Enums\TerritoryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChurchController extends Controller
{
    /**
     * Display a listing of churches
     */
    public function index(Request $request)
    {
        try {
            $query = Territory::where('territory_type', TerritoryType::CHURCH)
                ->with(['parent:id,name,territory_type', 'creator:id,firstname,lastname']);

            // Filter by subregion
            if ($request->filled('subregion_id')) {
                $query->where('parent_territory_id', $request->subregion_id);
            }

            // Filter by region (get all churches in subregions of this region)
            if ($request->filled('region_id')) {
                $region = Territory::find($request->region_id);
                if ($region) {
                    $subregionIds = $region->children()
                        ->where('territory_type', TerritoryType::SUBREGION)
                        ->pluck('id');
                    $query->whereIn('parent_territory_id', $subregionIds);
                }
            }

            // Filter by diocese (get all churches in this diocese)
            if ($request->filled('diocese_id')) {
                $query->where('full_path', 'like', '%' . Territory::find($request->diocese_id)?->name . '%');
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
                // Return all churches without pagination
                $churches = $query->get();
                
                return successResponse('Churches retrieved successfully', [
                    'churches' => $churches,
                    'total' => $churches->count()
                ]);
            }
            
            // Regular pagination
            $churches = $query->paginate($perPage);

            return successResponse('Churches retrieved successfully', [
                'churches' => $churches->items(),
                'pagination' => [
                    'current_page' => $churches->currentPage(),
                    'last_page' => $churches->lastPage(),
                    'per_page' => $churches->perPage(),
                    'total' => $churches->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve churches', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to retrieve churches', $e->getMessage());
        }
    }

    /**
     * Store a newly created church
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
            // Validate parent is a SubRegion
            $parent = Territory::find($request->parent_id);
            if ($parent->territory_type !== TerritoryType::SUBREGION) {
                return errorResponse('Parent must be a SubRegion', 400);
            }

            $authUser = $request->user();
            DB::beginTransaction();

            // Generate code if not provided
            $code = $request->code ?? $parent->generateChildCode(TerritoryType::CHURCH);

            $church = Territory::create([
                'name' => $request->name,
                'code' => $code,
                'territory_type' => TerritoryType::CHURCH,
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

            Log::info('Church created successfully', [
                'church_id' => $church->id,
                'church_name' => $church->name,
                'parent_subregion' => $parent->name,
                'created_by' => $authUser->id
            ]);

            return createdResponse($church, 'Church created successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create church', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to create church', $e->getMessage());
        }
    }

    /**
     * Display the specified church
     */
    public function show(Territory $church)
    {
        try {
            if ($church->territory_type !== TerritoryType::CHURCH) {
                return errorResponse('Territory is not a church', 400);
            }

            $church->load([
                'parent:id,name,territory_type',
                'creator:id,firstname,lastname',
                'updater:id,firstname,lastname'
            ]);

            // Get full ancestry
            $church->ancestry = $church->getAncestry()->map(function ($ancestor) {
                return [
                    'id' => $ancestor->id,
                    'name' => $ancestor->name,
                    'type' => $ancestor->territory_type->value
                ];
            });

            return successResponse('Church retrieved successfully', $church);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve church', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to retrieve church', $e->getMessage());
        }
    }

    /**
     * Update the specified church
     */
    public function update(Request $request, Territory $church)
    {
        if ($church->territory_type !== TerritoryType::CHURCH) {
            return errorResponse('Territory is not a church', 400);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:20|unique:territories,code,' . $church->id,
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
            // Validate new parent is SubRegion if changing parent
            if ($request->filled('parent_id')) {
                $newParent = Territory::find($request->parent_id);
                if ($newParent->territory_type !== TerritoryType::SUBREGION) {
                    return errorResponse('Parent must be a SubRegion', 400);
                }
            }

            $authUser = $request->user();
            $updateData = $request->only([
                'name', 'code', 'parent_id', 'address', 'phone', 'email',
                'postal_code', 'town', 'county', 'latitude', 'longitude',
                'established_date', 'description', 'is_active'
            ]);
            $updateData['updated_by'] = $authUser->id;

            $church->update($updateData);

            Log::info('Church updated successfully', [
                'church_id' => $church->id,
                'updated_by' => $authUser->id
            ]);

            return updatedResponse($church->fresh(), 'Church updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update church', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to update church', $e->getMessage());
        }
    }

    /**
     * Remove the specified church
     */
    public function destroy(Territory $church)
    {
        if ($church->territory_type !== TerritoryType::CHURCH) {
            return errorResponse('Territory is not a church', 400);
        }

        try {
            // Churches cannot have children, so safe to delete
            $churchName = $church->name;
            $church->delete();

            Log::info('Church deleted successfully', ['church_name' => $churchName]);
            return deleteResponse('Church deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete church', ['error' => $e->getMessage()]);
            return serverErrorResponse('Failed to delete church', $e->getMessage());
        }
    }
}
