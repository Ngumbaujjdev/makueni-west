<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetCategory;
use App\Models\BudgetLine;
use App\Support\BudgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Budget lines are either shared (set by the diocese, no owner) or belong to
 * one church (territory_type/territory_id). A church user sees the shared
 * lines that apply to churches plus their own, and may only add, change or
 * delete their own; everyone else works with the shared lines.
 */
class BudgetLineController extends Controller
{
    private const CHURCH_PERMISSION_PREFIX = 'church.settings.budgetsettings.budgetlines';

    /** Scope a lines query to what the user may see. */
    private function visibleTo($query, Request $request)
    {
        $churchId = BudgetAccess::churchId($request->user());
        if ($churchId !== null) {
            return $query->forChurch($churchId);
        }
        // Diocese users see the shared lines, or one church's lines with ?church_id=.
        if ($request->filled('church_id')) {
            return $query->forChurch((int) $request->church_id);
        }

        return $query->shared();
    }

    /** 403 unless a church user holds the action's permission and (for an existing line) owns it. */
    private function denyChurch(Request $request, string $action, ?BudgetLine $line = null): ?JsonResponse
    {
        $churchId = BudgetAccess::churchId($request->user());
        if ($churchId === null) {
            return null;
        }
        if (! BudgetAccess::has($request->user(), self::CHURCH_PERMISSION_PREFIX.'.'.$action)) {
            return response()->json(['success' => false, 'status' => 403, 'message' => 'You do not have permission to change budget lines.'], 403);
        }
        if ($line && ! $line->isOwnedBy($churchId)) {
            return response()->json(['success' => false, 'status' => 403, 'message' => 'Diocese budget lines can only be changed by the diocese.'], 403);
        }

        return null;
    }

    /** A slug not yet used by the same owner (shared lines, or one church's lines). */
    private function uniqueSlug(string $name, ?int $churchId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'line';
        $slug = $base;
        for ($i = 2; ; $i++) {
            $taken = BudgetLine::withTrashed()
                ->where('slug', $slug)
                ->when($churchId, fn ($q) => $q->where('territory_type', 'church')->where('territory_id', $churchId), fn ($q) => $q->whereNull('territory_id'))
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();
            if (! $taken) {
                return $slug;
            }
            $slug = "{$base}-{$i}";
        }
    }

    /**
     * Display a listing of budget lines.
     */
    public function index(Request $request)
    {
        try {
            // budget_line_items_count: how often budgets use the line - for a church, only its own
            // budgets (the delete check in destroy() still looks at every budget)
            $churchId = BudgetAccess::churchId($request->user());
            $query = $this->visibleTo(BudgetLine::with('budgetCategory')->withCount(['budgetLineItems' => fn ($items) => $items->when(
                $churchId !== null,
                fn ($q) => $q->whereHas('budget', fn ($b) => $b->where('territory_type', 'church')->where('territory_id', $churchId)),
            )]), $request);

            // Filter by category
            if ($request->has('category_id')) {
                $query->where('budget_category_id', $request->category_id);
            }

            // Filter by territory scope (a church's list is already scoped to churches)
            if ($request->has('territory_scope') && BudgetAccess::churchId($request->user()) === null) {
                $query->byTerritoryScope($request->territory_scope);
            }

            // Filter by active status
            if ($request->has('include_inactive') && $request->include_inactive == 'false') {
                $query->active();
            }

            // Filter by system default or user-created
            if ($request->has('system_defaults_only') && $request->system_defaults_only == 'true') {
                $query->systemDefaults();
            }

            // Order by
            $budgetLines = $query->orderBy('budget_category_id', 'asc')
                ->orderBy('display_order', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget lines retrieved successfully',
                'data' => $budgetLines,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget lines: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget lines',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get budget lines grouped by category.
     */
    public function getGroupedByCategory(Request $request)
    {
        try {
            $territoryScope = $request->get('territory_scope', 'all');
            $isChurch = BudgetAccess::churchId($request->user()) !== null;

            $categories = BudgetCategory::with(['budgetLines' => function ($query) use ($territoryScope, $request, $isChurch) {
                $this->visibleTo($query->active(), $request)
                    ->when(! $isChurch, fn ($q) => $q->byTerritoryScope($territoryScope))
                    ->orderBy('display_order', 'asc');
            }])
                ->active()
                ->get();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget lines grouped by category retrieved successfully',
                'data' => $categories,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve grouped budget lines: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve grouped budget lines',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created budget line.
     */
    public function store(Request $request)
    {
        try {
            if ($denied = $this->denyChurch($request, 'create')) {
                return $denied;
            }
            $churchId = BudgetAccess::churchId($request->user());

            $validator = Validator::make($request->all(), [
                'budget_category_id' => 'required|exists:budget_categories,id',
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255',
                'territory_scope' => $churchId ? 'nullable' : 'required|in:diocese,region,subregion,church,all',
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // A church's line belongs to that church and is only ever a church line.
            if ($churchId) {
                $data['territory_scope'] = 'church';
                $data['territory_type'] = 'church';
                $data['territory_id'] = $churchId;
            }

            // Unique among the same owner's lines, generated from the name when not given
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name'], $churchId);

            // Set as user-created (not system default)
            $data['is_system_default'] = false;

            // Set created_by to authenticated user
            $data['created_by'] = auth()->id();

            // Auto-generate display_order if not provided
            if (! isset($data['display_order'])) {
                $maxOrder = BudgetLine::where('budget_category_id', $data['budget_category_id'])->max('display_order');
                $data['display_order'] = $maxOrder ? $maxOrder + 1 : 1;
            }

            $budgetLine = BudgetLine::create($data);
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 201,
                'message' => 'Budget line created successfully',
                'data' => $budgetLine,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create budget line: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to create budget line',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified budget line.
     */
    public function show(Request $request, BudgetLine $budgetLine)
    {
        try {
            $churchId = BudgetAccess::churchId($request->user());
            if ($churchId !== null && $budgetLine->territory_id !== null && ! $budgetLine->isOwnedBy($churchId)) {
                return response()->json(['success' => false, 'status' => 404, 'message' => 'Budget line not found'], 404);
            }
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line retrieved successfully',
                'data' => $budgetLine,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve budget line: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve budget line',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified budget line.
     */
    public function update(Request $request, BudgetLine $budgetLine)
    {
        try {
            if ($denied = $this->denyChurch($request, 'update', $budgetLine)) {
                return $denied;
            }
            $ownerId = $budgetLine->territory_id !== null ? (int) $budgetLine->territory_id : null;

            $validator = Validator::make($request->all(), [
                'budget_category_id' => 'sometimes|required|exists:budget_categories,id',
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'nullable|string|max:255',
                'territory_scope' => 'sometimes|required|in:diocese,region,subregion,church,all',
                'description' => 'nullable|string|max:1000',
                'is_active' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // A church's own line stays a church line
            if ($ownerId !== null) {
                $data['territory_scope'] = 'church';
            }

            // Unique among the same owner's lines, regenerated from a new name when not given
            if (isset($data['slug']) || isset($data['name'])) {
                $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name'], $ownerId, $budgetLine->id);
            }

            // Set updated_by to authenticated user
            $data['updated_by'] = auth()->id();

            $budgetLine->update($data);
            $budgetLine->load('budgetCategory');

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line updated successfully',
                'data' => $budgetLine->fresh(['budgetCategory']),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget line: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget line',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update display order of budget line.
     */
    public function updateOrder(Request $request, BudgetLine $budgetLine)
    {
        try {
            if ($denied = $this->denyChurch($request, 'update', $budgetLine)) {
                return $denied;
            }
            $validator = Validator::make($request->all(), [
                'display_order' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $budgetLine->update([
                'display_order' => $request->display_order,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line order updated successfully',
                'data' => null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update budget line order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to update budget line order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get audit trail for a specific budget line.
     */
    public function getAudits(BudgetLine $budgetLine)
    {
        try {
            $audits = $budgetLine->audits()
                ->with('user:id,firstname,lastname,email')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($audit) {
                    return [
                        'id' => $audit->id,
                        'event' => $audit->event, // created, updated, deleted
                        'user' => $audit->user ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->firstname.' '.$audit->user->lastname,
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
                'data' => $audits,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve audit trail: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to retrieve audit trail',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified budget line.
     */
    public function destroy(Request $request, BudgetLine $budgetLine)
    {
        try {
            if ($denied = $this->denyChurch($request, 'delete', $budgetLine)) {
                return $denied;
            }

            // Prevent deletion of system default lines
            if ($budgetLine->is_system_default) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Cannot delete system default budget lines.',
                    'data' => null,
                ], 400);
            }

            // A line already used in a budget can't go - deactivate it instead
            $usageCount = $budgetLine->budgetLineItems()->distinct('budget_id')->count('budget_id');
            if ($usageCount > 0) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => "This line is used in {$usageCount} ".($usageCount === 1 ? 'budget' : 'budgets').'. Switch it off instead, so it is no longer offered for new budgets.',
                    'data' => ['usage_count' => $usageCount],
                ], 422);
            }

            $budgetLine->delete();

            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Budget line deleted successfully',
                'data' => null,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to delete budget line: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to delete budget line',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
