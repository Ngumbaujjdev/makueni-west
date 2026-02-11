<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Territory;
use App\Models\UserTerritoryAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
 * Display a listing of users with territorial filtering
 * Filtered by user's territorial access level
 *
 * Query Parameters:
 * - territory_id: Filter by specific territory
 * - role_id: Filter by role
 * - status: Filter by status (active/inactive/suspended)
 * - search: Search in name, email, username, employee_code
 * - with_assignments: Include assignments (default: true)
 * - include_audits: Include audit summary (default: false)
 * - per_page: Items per page (default: 15)
 * - page: Page number
 * - sort_by: Field to sort by (default: firstname)
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
            // ✅ Global admin sees ALL users (no territory filtering!)
            $effectiveTerritoryLevel = 'diocese';
            $isGlobalAdmin = true;
        } else {
            // ✅ Regular user - get their highest territorial level
            $effectiveTerritoryLevel = $this->getUserHighestTerritorialLevel($authUser);
            $isGlobalAdmin = false;
        }

        // Get query parameters
        $territoryId = $request->query('territory_id');
        $roleId = $request->query('role_id');
        $status = $request->query('status');
        $search = $request->query('search');
        $withAssignments = $request->boolean('with_assignments', true);
        $includeAudits = $request->boolean('include_audits', false);
        $perPage = $request->input('per_page', 15);
        $sortBy = $request->input('sort_by', 'firstname');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort parameters
        $allowedSortFields = ['firstname', 'lastname', 'email', 'username', 'employee_code', 'status', 'created_at', 'last_login_at'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'firstname';
        }
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'asc';
        }

        // Base query with relationships
        $query = User::with(['activeAssignments.role', 'activeAssignments.territory']);

        // ============================================================
        // ✅ FIXED: TERRITORIAL ACCESS CONTROL
        // ============================================================
        if ($isGlobalAdmin) {
            // ✅ Global admin sees ALL users - NO FILTERING!
            Log::info('Global admin fetching all users', [
                'user_id' => $authUser->id,
                'employee_code' => $authUser->employee_code
            ]);
        } else {
            // ✅ Regular user - filter by accessible territories
            $accessibleTerritories = $this->getUserAccessibleTerritories($authUser);
            $territoryIds = $accessibleTerritories->pluck('id');

            Log::info('Regular user fetching users in accessible territories', [
                'user_id' => $authUser->id,
                'territory_level' => $effectiveTerritoryLevel,
                'accessible_territory_count' => $territoryIds->count()
            ]);

            // Filter users who have assignments in accessible territories
            $query->whereHas('activeAssignments', function($q) use ($territoryIds) {
                $q->whereIn('territory_id', $territoryIds);
            });
        }

        // ============================================================
        // ADDITIONAL FILTERS
        // ============================================================

        // Filter by specific territory (if provided)
        if ($territoryId) {
            $query->whereHas('activeAssignments', function($q) use ($territoryId) {
                $q->where('territory_id', $territoryId);
            });
        }

        // Filter by role (if provided)
        if ($roleId) {
            $query->whereHas('activeAssignments', function($q) use ($roleId) {
                $q->where('role_id', $roleId);
            });
        }

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        // Search functionality (searches across multiple fields)
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('firstname', 'LIKE', "%{$search}%")
                  ->orWhere('lastname', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('username', 'LIKE', "%{$search}%")
                  ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        // Apply sorting
        $query->orderBy($sortBy, $sortOrder);

        // Add secondary sort for consistent ordering
        if ($sortBy !== 'firstname') {
            $query->orderBy('firstname', 'asc');
        }
        if ($sortBy !== 'lastname') {
            $query->orderBy('lastname', 'asc');
        }

        // Paginate results
        $users = $query->paginate($perPage);

        // Transform user data
        $usersData = $users->map(function ($user) use ($withAssignments, $includeAudits) {
            $data = [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'username' => $user->username,
                'employee_code' => $user->employee_code,
                'phone' => $user->phone,
                'position' => $user->position,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'password_expires_at' => $user->password_expires_at,
                'must_change_password' => $user->must_change_password,
                'created_at' => $user->created_at,
            ];

            if ($withAssignments) {
                $data['territorial_assignments'] = $user->activeAssignments->map(function($assignment) {
                    return [
                        'id' => $assignment->id,
                        'role' => [
                            'id' => $assignment->role->id,
                            'name' => $assignment->role->name,
                            'territory_level' => $assignment->role->territory_level,
                        ],
                        'territory' => [
                            'id' => $assignment->territory->id,
                            'name' => $assignment->territory->name,
                            'type' => $assignment->territory->territory_type,
                            'hierarchy_path' => $assignment->territory->hierarchy_path,
                        ],
                        'assignment_type' => $assignment->assignment_type,
                        'is_primary' => $assignment->is_primary,
                        'assigned_at' => $assignment->assigned_at,
                    ];
                });
                $data['assignments_count'] = $user->activeAssignments->count();
            }

            // Include audit summary if requested
            if ($includeAudits) {
                $data['audit_summary'] = [
                    'total_changes' => $user->audits()->count(),
                    'last_modified_by' => $user->getLastModifiedBy(),
                    'last_password_change' => $user->password_changed_at?->format('Y-m-d H:i:s'),
                    'login_attempts' => $user->login_attempts,
                    'last_login' => $user->last_login_at?->format('Y-m-d H:i:s'),
                ];
            }

            return $data;
        });

        return successResponse('Users retrieved successfully', [
            'users' => $usersData,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'filters' => [
                'territory_id' => $territoryId,
                'role_id' => $roleId,
                'status' => $status,
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'territory_level' => $effectiveTerritoryLevel,
            'is_global_admin' => $isGlobalAdmin,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve users', [
            'user_id' => $request->user()->id,
            'filters' => $request->query(),
            'error' => $e->getMessage()
        ]);

        return serverErrorResponse('Failed to retrieve users', $e->getMessage());
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
             * Display the specified user with detailed information
             *
             * Query Parameters:
             * - include_audits: Include full audit trail (default: false)
             * - audit_limit: Number of audit records (default: 20)
             * - audit_type: Filter audits by type (created, updated, password_changed, profile_updated, status_changed, etc.)
             * - audit_from: Filter audits from date (Y-m-d)
             * - audit_to: Filter audits to date (Y-m-d)
             * - audit_page: Page number for audit pagination
             */
          public function show(Request $request, User $user)
{
    try {
        $authUser = $request->user();

        // Check if auth user can view this user
        if (!$this->canAccessUser($authUser, $user)) {
            return errorResponse('You do not have permission to view this user', 403);
        }

        $user->load([
            'activeAssignments.role',
            'activeAssignments.territory.parent',
            'allAssignments.role',
            'allAssignments.territory',
            'allAssignments.assignedByUser',
            'allAssignments.removedByUser:id,firstname,lastname',
            'superAdminConfig',
        ]);

        $userData = [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'phone' => $user->phone,
            'position' => $user->position,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at,
            'login_attempts' => $user->login_attempts,
            'password_expires_at' => $user->password_expires_at,
            'password_changed_at' => $user->password_changed_at,
            'must_change_password' => $user->must_change_password,

            // Active assignments
            'active_assignments' => $user->activeAssignments->map(function($assignment) {
                return [
                    'id' => $assignment->id,
                    'role' => [
                        'id' => $assignment->role->id,
                        'name' => $assignment->role->name,
                        'territory_level' => $assignment->role->territory_level,
                    ],
                    'territory' => [
                        'id' => $assignment->territory->id,
                        'name' => $assignment->territory->name,
                        'type' => $assignment->territory->territory_type,
                        'hierarchy_path' => $assignment->territory->hierarchy_path,
                    ],
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'assigned_at' => $assignment->assigned_at,
                ];
            }),

            // Assignment history
            'assignment_history' => $user->allAssignments->map(function($assignment) {
                return [
                    'id' => $assignment->id,
                    'role_name' => $assignment->role->name,
                    'territory_name' => $assignment->territory->name,
                    'assignment_type' => $assignment->assignment_type,
                    'is_primary' => $assignment->is_primary,
                    'assigned_by' => $assignment->assignedByUser ? $assignment->assignedByUser->full_name : null,
                    'assigned_at' => $assignment->assigned_at,
                    'removed_at' => $assignment->removed_at,
                    'is_active' => $assignment->is_active,
                ];
            }),

            // Permissions
            'permissions' => $this->getUserPermissions($user),

            // Account status
            'account_status' => [
                'is_active' => $user->isActive(),
                'is_suspended' => $user->isSuspended(),
                'is_inactive' => $user->isInactive(),
                'status' => $user->status,
            ],

            // Password status
            'password_status' => [
                'is_expired' => $user->isPasswordExpired(),
                'is_expiring_soon' => $user->isPasswordExpiringSoon(),
                'days_until_expiry' => $user->getDaysUntilPasswordExpiry(),
                'must_change' => $user->must_change_password,
                'last_changed' => $user->password_changed_at?->format('Y-m-d H:i:s'),
                'expires_at' => $user->password_expires_at?->format('Y-m-d H:i:s'),
            ],

            // Super admin config
            'super_admin' => [
                'has_global_access' => $user->hasGlobalAccess(),
                'is_super_admin' => $user->isSuperAdmin(),
            ],

            // Timestamps
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        // ============================================================
        // ENHANCED AUDIT TRAIL SUPPORT
        // ============================================================

        if ($request->boolean('include_audits', false)) {
            $auditType = $request->input('audit_type', 'all');
            $auditLimit = $request->input('audit_limit', 20);

            // Validate audit type
            $validAuditTypes = ['all', 'login', 'password', 'profile', 'status'];
            if (!in_array($auditType, $validAuditTypes)) {
                $auditType = 'all';
            }

            // Get audit data based on type
            switch ($auditType) {
                case 'login':
                    // Login history only
                    $userData['audit_trail'] = [
                        'type' => 'login',
                        'data' => $this->getFilteredLoginHistory($user, $request, $auditLimit),
                    ];
                    break;

                case 'password':
                    // Password changes only
                    $userData['audit_trail'] = [
                        'type' => 'password',
                        'data' => $this->getFilteredPasswordHistory($user, $request, $auditLimit),
                    ];
                    break;

                case 'profile':
                    // Profile changes only
                    $userData['audit_trail'] = [
                        'type' => 'profile',
                        'data' => $this->getFilteredProfileHistory($user, $request, $auditLimit),
                    ];
                    break;

                case 'status':
                    // Status changes only
                    $userData['audit_trail'] = [
                        'type' => 'status',
                        'data' => $this->getFilteredStatusHistory($user, $request, $auditLimit),
                    ];
                    break;

                case 'all':
                default:
                    // All audit history (with pagination if requested)
                    if ($request->has('audit_page')) {
                        // Paginated audit trail
                        $userData['audit_trail'] = [
                            'type' => 'all_paginated',
                            'data' => $this->getPaginatedAuditHistory($user, $request, $auditLimit),
                        ];
                    } else {
                        // Multiple audit types (non-paginated)
                        $userData['audit_trail'] = [
                            'type' => 'all',
                            'full_history' => $this->getFilteredAuditHistory($user, $request, $auditLimit),
                            'login_history' => $user->getLoginAuditHistory(min($auditLimit, 20)),
                            'password_changes' => $user->getPasswordChangeHistory(min($auditLimit, 10)),
                            'profile_changes' => $user->getProfileChangeHistory(min($auditLimit, 20)),
                            'status_changes' => $user->getStatusChangeHistory(min($auditLimit, 20)),
                        ];
                    }
                    break;
            }

            // Add audit summary
            $userData['audit_summary'] = [
                'total_changes' => $user->audits()->count(),
                'last_modified_by' => $user->getLastModifiedBy(),
                'last_password_change' => $user->password_changed_at?->format('Y-m-d H:i:s'),
                'login_attempts' => $user->login_attempts,
                'last_login' => $user->last_login_at?->format('Y-m-d H:i:s'),
                'filters_applied' => [
                    'type' => $auditType,
                    'limit' => $auditLimit,
                    'from_date' => $request->input('audit_from'),
                    'to_date' => $request->input('audit_to'),
                    'event' => $request->input('audit_event'),
                ],
            ];
        }

        return successResponse('User retrieved successfully', $userData);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve user', [
            'user_id' => $user->id,
            'auth_user_id' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return serverErrorResponse('Failed to retrieve user', $e->getMessage());
    }
}

/**
 * ============================================================================
 * HELPER METHODS FOR AUDIT FILTERING
 * ============================================================================
 */

/**
 * Get filtered audit history with date range support
 */
private function getFilteredAuditHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->with('user:id,firstname,lastname,username');

    // Filter by specific event if provided
    if ($request->filled('audit_event')) {
        $query->where('event', $request->audit_event);
    }

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    return $query->latest()
        ->limit($limit)
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

/**
 * Get paginated audit history
 */
private function getPaginatedAuditHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->with('user:id,firstname,lastname,username');

    // Filter by specific event if provided
    if ($request->filled('audit_event')) {
        $query->where('event', $request->audit_event);
    }

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    $audits = $query->latest()->paginate($limit, ['*'], 'audit_page');

    return [
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
            'from' => $audits->firstItem(),
            'to' => $audits->lastItem(),
        ]
    ];
}

/**
 * Get filtered login history
 */
private function getFilteredLoginHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->where(function ($q) {
            $q->where('event', 'like', '%login%')
              ->orWhere('event', 'account_locked')
              ->orWhereJsonContains('new_values->last_login_at', true)
              ->orWhereJsonContains('new_values->login_attempts', true);
        })
        ->with('user:id,firstname,lastname,username');

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    return $query->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'login_attempts' => $audit->new_values['login_attempts'] ?? null,
                'last_login_at' => $audit->new_values['last_login_at'] ?? null,
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

/**
 * Get filtered password change history
 */
private function getFilteredPasswordHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->where(function ($q) {
            $q->where('event', 'password_changed')
              ->orWhereJsonContains('new_values->password_changed_at', true);
        })
        ->with('user:id,firstname,lastname,username');

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    return $query->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'System',
                'password_changed_at' => $audit->new_values['password_changed_at'] ?? null,
                'must_change_password' => $audit->new_values['must_change_password'] ?? null,
                'password_expires_at' => $audit->new_values['password_expires_at'] ?? null,
                'ip_address' => $audit->ip_address,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

/**
 * Get filtered profile change history
 */
private function getFilteredProfileHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->where(function ($q) {
            $q->where('event', 'profile_updated')
              ->orWhereJsonContains('new_values->firstname', true)
              ->orWhereJsonContains('new_values->lastname', true)
              ->orWhereJsonContains('new_values->email', true)
              ->orWhereJsonContains('new_values->phone', true)
              ->orWhereJsonContains('new_values->position', true)
              ->orWhereJsonContains('new_values->username', true);
        })
        ->with('user:id,firstname,lastname,username');

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    return $query->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'Self',
                'changes' => [
                    'old' => $audit->old_values,
                    'new' => $audit->new_values,
                ],
                'ip_address' => $audit->ip_address,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}

/**
 * Get filtered status change history
 */
private function getFilteredStatusHistory(User $user, Request $request, int $limit)
{
    $query = $user->audits()
        ->where(function ($q) {
            $q->where('event', 'like', 'status_changed%')
              ->orWhereJsonContains('new_values->status', true);
        })
        ->with('user:id,firstname,lastname,username');

    // Apply date filters
    if ($request->filled('audit_from')) {
        $query->whereDate('created_at', '>=', $request->audit_from);
    }
    if ($request->filled('audit_to')) {
        $query->whereDate('created_at', '<=', $request->audit_to);
    }

    return $query->latest()
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'old_status' => $audit->old_values['status'] ?? null,
                'new_status' => $audit->new_values['status'] ?? null,
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->full_name,
                ] : 'System',
                'ip_address' => $audit->ip_address,
                'created_at' => $audit->created_at->format('Y-m-d H:i:s'),
            ];
        });
}
    /**
     * Store a newly created user
     * Supports both single assignment (backward compatible) and multiple assignments
     */
    public function store(Request $request)
    {
        // ============================================================
        // VALIDATION RULES
        // ============================================================
        $validationRules = [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users',
            'username' => 'nullable|string|unique:users|max:255',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'employee_code' => 'nullable|string|size:6|unique:users',
            'status' => 'in:active,inactive',
            'must_change_password' => 'boolean',

            // Single assignment (backward compatible)
            'territory_id' => 'nullable|exists:territories,id',
            'role_id' => 'nullable|exists:roles,id',
            'assignment_type' => 'nullable|in:primary,secondary,temporary',

            // Multiple assignments (new feature)
            'assignments' => 'nullable|array|min:1',
            'assignments.*.territory_id' => 'required|exists:territories,id',
            'assignments.*.role_id' => 'required|exists:roles,id',
            'assignments.*.assignment_type' => 'required|in:primary,secondary,temporary',
        ];

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $authUser = $request->user();

            // ============================================================
            // DETERMINE ASSIGNMENT MODE
            // ============================================================
            $useMultipleAssignments = $request->has('assignments');
            $assignmentsData = [];

            if ($useMultipleAssignments) {
                // NEW: Multiple assignments mode
                $assignmentsData = $request->assignments;

                // Validate exactly one primary assignment
                $primaryCount = collect($assignmentsData)->where('assignment_type', 'primary')->count();
                
                if ($primaryCount === 0) {
                    return errorResponse('At least one assignment must be marked as primary', 422);
                }
                
                if ($primaryCount > 1) {
                    return errorResponse('Only one assignment can be marked as primary', 422);
                }

                // Check for duplicate assignments (same territory + role)
                $duplicates = collect($assignmentsData)
                    ->groupBy(function ($item) {
                        return $item['territory_id'] . '-' . $item['role_id'];
                    })
                    ->filter(function ($group) {
                        return $group->count() > 1;
                    });

                if ($duplicates->isNotEmpty()) {
                    $firstDuplicate = $duplicates->first()->first();
                    $territory = Territory::find($firstDuplicate['territory_id']);
                    $role = Role::find($firstDuplicate['role_id']);
                    return errorResponse(
                        "Duplicate assignment detected for territory '{$territory->name}' with role '{$role->name}'",
                        422
                    );
                }

            } else if ($request->territory_id && $request->role_id) {
                // OLD: Single assignment mode (backward compatible)
                $assignmentsData = [[
                    'territory_id' => $request->territory_id,
                    'role_id' => $request->role_id,
                    'assignment_type' => $request->input('assignment_type', 'primary'),
                ]];
            }

            // Generate employee code if not provided
            $employeeCode = $request->employee_code ?? $this->generateEmployeeCode();

            // Generate password if not provided
            $password = $request->password ?? $this->generateDefaultPassword();

            DB::beginTransaction();

            // ============================================================
            // CREATE USER
            // ============================================================
            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'email' => $request->email,
                'username' => $request->username,
                'phone' => $request->phone,
                'position' => $request->position,
                'password' => Hash::make($password),
                'employee_code' => $employeeCode,
                'status' => $request->input('status', 'active'),
                'must_change_password' => $request->input('must_change_password', true),
                'password_expires_at' => now()->addMonths(6),
                'password_changed_at' => now(),
            ]);

            // ============================================================
            // CREATE TERRITORIAL ASSIGNMENTS
            // ============================================================
            $createdAssignments = [];

            if (!empty($assignmentsData)) {
                foreach ($assignmentsData as $assignmentData) {
                    $territory = Territory::find($assignmentData['territory_id']);
                    $role = Role::find($assignmentData['role_id']);

                    // Check if auth user can assign to this territory
                    if (!$this->canAssignToTerritory($authUser, $territory)) {
                        DB::rollBack();
                        return errorResponse(
                            "You do not have permission to assign users to territory '{$territory->name}'",
                            403
                        );
                    }

                    $isPrimary = $assignmentData['assignment_type'] === 'primary';

                    $assignment = UserTerritoryAssignment::create([
                        'user_id' => $user->id,
                        'territory_id' => $assignmentData['territory_id'],
                        'role_id' => $assignmentData['role_id'],
                        'assignment_type' => $assignmentData['assignment_type'],
                        'is_primary' => $isPrimary,
                        'assigned_by_user_id' => $authUser->id,
                        'assigned_at' => now(),
                        'is_active' => true,
                    ]);

                    // Load relationships for response
                    $assignment->load(['territory:id,name', 'role:id,name']);
                    
                    $createdAssignments[] = [
                        'id' => $assignment->id,
                        'territory_id' => $assignment->territory_id,
                        'territory_name' => $assignment->territory->name,
                        'role_id' => $assignment->role_id,
                        'role_name' => $assignment->role->name,
                        'assignment_type' => $assignment->assignment_type,
                        'is_primary' => $assignment->is_primary,
                    ];
                }
            }

            DB::commit();

            // ============================================================
            // LOG AND RESPOND
            // ============================================================
            Log::info('User created successfully', [
                'user_id' => $user->id,
                'user_name' => $user->full_name,
                'employee_code' => $user->employee_code,
                'assignments_count' => count($createdAssignments),
                'created_by' => $authUser->id
            ]);

            $message = count($createdAssignments) > 0 
                ? "User created successfully with " . count($createdAssignments) . " assignment(s)"
                : "User created successfully";

            return createdResponse([
                'user' => $user,
                'assignments' => $createdAssignments
            ], $message, 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create user', [
                'request_data' => $request->all(),
                'auth_user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to create user', $e->getMessage());
        }
    }



    /**
     * 
     *  the specified user
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'username' => 'nullable|string|unique:users,username,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'employee_code' => 'nullable|string|size:6|unique:users,employee_code,' . $user->id,
            'status' => 'in:active,inactive',
            'must_change_password' => 'boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $authUser = $request->user();

            // Check if auth user can update this user
            if (!$this->canAccessUser($authUser, $user)) {
                return errorResponse('You do not have permission to update this user', 403);
            }

            $updateData = [
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'email' => $request->email,
                'username' => $request->username,
                'phone' => $request->phone,
                'position' => $request->position,
                'status' => $request->input('status', $user->status),
                'must_change_password' => $request->input('must_change_password', $user->must_change_password),
            ];

            // Update password if provided
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
                $updateData['password_changed_at'] = now();
                $updateData['password_expires_at'] = now()->addMonths(6);
            }

            // Update employee code if provided
            if ($request->filled('employee_code')) {
                $updateData['employee_code'] = $request->employee_code;
            }

            $user->update($updateData);

            Log::info('User updated successfully', [
                'user_id' => $user->id,
                'updated_by' => $authUser->id,
                'updated_fields' => array_keys($updateData)
            ]);

            return updatedResponse($user->fresh(), 'User updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update user', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update user', $e->getMessage());
        }
    }

    /**
     * Update user status only (activate/deactivate)
     * Quick endpoint for status changes with audit trail
     */
    public function updateStatus(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $authUser = $request->user();

            // Check if auth user can update this user
            if (!$this->canAccessUser($authUser, $user)) {
                return errorResponse('You do not have permission to update this user', 403);
            }

            $oldStatus = $user->status;
            $newStatus = $request->status;

            // Update status
            $user->update(['status' => $newStatus]);

            // Log status change
            Log::info('User status updated', [
                'user_id' => $user->id,
                'updated_by' => $authUser->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);

            return updatedResponse($user->fresh(), 'User status updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update user status', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update user status', $e->getMessage());
        }
    }

    /**
     * Remove the specified user
     */
    public function destroy(User $user)
    {
        try {
            $authUser = $request->user();

            // Check if auth user can delete this user
            if (!$this->canAccessUser($authUser, $user)) {
                return errorResponse('You do not have permission to delete this user', 403);
            }

            // Prevent deletion of current user
            if ($user->id === $authUser->id) {
                return errorResponse('You cannot delete your own account', 400);
            }

            // Check if user has active assignments
            $activeAssignments = $user->activeAssignments()->count();
            if ($activeAssignments > 0) {
                return errorResponse(
                    "Cannot delete user. They have {$activeAssignments} active territorial assignment(s). Please remove assignments first.",
                    400
                );
            }

            $userName = $user->full_name;
            $user->delete();

            Log::info('User deleted successfully', [
                'user_name' => $userName,
                'deleted_by' => $authUser->id
            ]);

            return deleteResponse('User deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete user', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to delete user', $e->getMessage());
        }
    }

    /**
     * Reset user password and send notification
     */
    public function resetPassword(User $user)
    {
        try {
            $authUser = $request->user();

            // Check if auth user can reset this user's password
            if (!$this->canAccessUser($authUser, $user)) {
                return errorResponse('You do not have permission to reset this user\'s password', 403);
            }

            $newPassword = $this->generateDefaultPassword();

            $user->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => true,
                'password_changed_at' => now(),
                'password_expires_at' => now()->addMonths(6),
                'login_attempts' => 0, // Reset failed attempts
            ]);

            // TODO: Send password reset notification with new password
            // SendPasswordResetNotification::dispatch($user, $newPassword);

            Log::info('User password reset', [
                'user_id' => $user->id,
                'reset_by' => $authUser->id
            ]);

            return successResponse('Password reset successfully. User will be notified via email.');

        } catch (\Exception $e) {
            Log::error('Failed to reset user password', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to reset user password', $e->getMessage());
        }
    }

    // === HELPER METHODS ===

    /**
     * Get user's accessible territories
     */
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

        // Check if assignment allows seeing children
        if ($assignment->can_see_children) {
            $children = $territory->getAllChildren();
            $userTerritories = $userTerritories->merge($children);
        }
    }

    return $userTerritories->unique('id');
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
     * Check if user can assign to territory
     */
    private function canAssignToTerritory($user, $territory): bool
    {
        if ($user->hasGlobalAccess()) {
            return true;
        }

        $accessibleTerritories = $this->getUserAccessibleTerritories($user);
        return $accessibleTerritories->contains('id', $territory->id);
    }

    /**
     * Generate employee code
     */
    private function generateEmployeeCode(): string
    {
        do {
            $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        } while (User::where('employee_code', $code)->exists());

        return $code;
    }

    /**
     * Generate default password
     */
    private function generateDefaultPassword(): string
    {
        return 'Diocese@' . date('Y');
    }

    /**
     * Get user permissions from all active assignments
     */
    private function getUserPermissions($user): array
    {
        $permissions = collect();

        foreach ($user->activeAssignments as $assignment) {
            $rolePermissions = $assignment->role->permissions->pluck('name');
            $permissions = $permissions->merge($rolePermissions);
        }

        return $permissions->unique()->values()->toArray();
    }
    /**
 * Get System Administrator contact details
 * Returns the global admin from SuperAdminConfig
 *
 * @return \Illuminate\Http\JsonResponse
 */
public function getSystemAdminContact()
{
    try {
        // Find global admin from SuperAdminConfig
        $superAdminConfig = \App\Models\SuperAdminConfig::where('global_access', true)
            ->with('user:id,firstname,lastname,email,phone,position')
            ->first();

        if (!$superAdminConfig || !$superAdminConfig->user) {
            return errorResponse('System administrator not found', 404);
        }

        $admin = $superAdminConfig->user;

        return successResponse('System administrator contact retrieved successfully', [
            'id' => $admin->id,
            'name' => $admin->full_name,
            'firstname' => $admin->firstname,
            'lastname' => $admin->lastname,
            'email' => $admin->email,
            'phone' => $admin->phone,
            'position' => $admin->position,
            'full_name' => $admin->full_name,
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to retrieve system admin contact', [
            'error' => $e->getMessage(),
        ]);

        return serverErrorResponse('Failed to retrieve system administrator contact', $e->getMessage());
    }
}
}