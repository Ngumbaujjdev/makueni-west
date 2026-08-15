<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetEmail;
use App\Jobs\SendSupportEmail;
use App\Jobs\SendPasswordChangedNotification;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    /**
     * Admin/Tech login with email/username + password
     *
     * ✅ AUDIT TRAIL: Automatically tracks login_attempts, status, last_login_at changes
     */
  public function login(Request $request)
{
    $validator = Validator::make($request->all(), [
        'identifier' => 'required|string',
        'password' => 'required|string',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    // Find user by email or username
    $user = User::where('email', $request->identifier)
        ->orWhere('username', $request->identifier)
        ->first();

    if (!$user) {
        return errorResponse('Invalid credentials', 401);
    }

    // Check account status
    if (!$user->isActive()) {
        return errorResponse('Account is inactive. Please contact administrator.', 403);
    }

    // Verify password
    if (!Hash::check($request->password, $user->password)) {
        // ✅ MANUALLY CREATE FAILED LOGIN AUDIT EVENT
        $user->audits()->create([
            'event' => 'login_failed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'user_type' => User::class,
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => [
                'login_attempts' => $user->login_attempts ?? 0,
            ],
            'new_values' => [
                'login_attempts' => ($user->login_attempts ?? 0) + 1,
                'login_success' => false,
                'login_method' => 'password',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return errorResponse('Invalid credentials', 401);
    }

    // Create access token
    $accessToken = $user->createToken('Api-Access')->plainTextToken;

    // Get territorial data
    $territorialRoles = $this->getUserTerritorialRoles($user);
    $defaultRole = $this->getDefaultRole($user);

    // ✅ Update last login
    $user->update(['last_login_at' => now()]);

    // ✅ MANUALLY CREATE LOGIN AUDIT EVENT
    $user->audits()->create([
        'event' => 'user_login',
        'auditable_type' => User::class,
        'auditable_id' => $user->id,
        'user_type' => User::class,
        'user_id' => $user->id,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'old_values' => [],
        'new_values' => [
            'last_login_at' => now()->toDateTimeString(),
            'login_success' => true,
            'login_method' => 'password',
        ],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return successResponse('Login successful', [
        'token' => $accessToken,
        'user' => $user->load(['activeAssignments.role', 'activeAssignments.territory']),
        'territorial_roles' => $territorialRoles,
        'current_role' => $defaultRole,
        'permissions' => $this->getUserPermissions($user, $defaultRole['assignment_id'] ?? null)
    ]);
}

    /**
     * Force password change for expired passwords
     *
     * ✅ AUDIT TRAIL: Automatically tracks password changes (NOT the password itself)
     * Tracks: password_changed_at, must_change_password, password_expires_at
     */
    public function forcePasswordChange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = User::find($request->user_id);

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return errorResponse('Current password is incorrect', 400);
            }

            // Don't allow same password
            if (Hash::check($request->new_password, $user->password)) {
                return errorResponse('New password must be different from current password', 400);
            }

            // ✅ AUDIT: Password change tracked automatically
            // Event: "password_changed" (custom event from transformAudit)
            // NOTE: The actual password hash is NOT audited (excluded in $auditExclude)
            // Only password_changed_at, must_change_password, password_expires_at are audited
            $user->update([
                'password' => Hash::make($request->new_password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'password_expires_at' => now()->addMonths(6), // Diocese users get 6 months
            ]);

            // Send password changed notification email
            SendPasswordChangedNotification::dispatch($user);

            return successResponse('Password changed successfully. You can now login with your new password.');
        } catch (\Exception $e) {
            Log::error('Force password change failed', [
                'user_id' => $request->user_id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Password change failed', $e->getMessage());
        }
    }

    /**
     * Update user profile
     *
     * ✅ AUDIT TRAIL: Automatically tracks all profile changes
     * Tracks: firstname, lastname, username, email, phone, position
     * If password changed: tracks password_changed_at, must_change_password, password_expires_at
     */
    public function profileChange(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'firstname' => 'nullable|string|max:255',
            'lastname' => 'nullable|string|max:255',
            'username' => 'nullable|string|unique:users,username,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'current_password' => 'required_with:password|string',
            'password' => 'nullable|string|min:8|confirmed',
            'password_confirmation' => 'required_with:password|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $updateData = collect($request->only([
                'firstname',
                'lastname',
                'username',
                'email',
                'position',
                'phone'
            ]))->filter()->toArray();

            // Handle password change
            if ($request->filled('password')) {
                // Verify current password if provided
                if ($request->filled('current_password')) {
                    if (!Hash::check($request->current_password, $user->password)) {
                        return errorResponse('Current password is incorrect', 400);
                    }
                }

                // Don't allow same password
                if (Hash::check($request->password, $user->password)) {
                    return errorResponse('New password must be different from current password', 400);
                }

                $updateData['password'] = Hash::make($request->password);
                $updateData['password_changed_at'] = now();
                $updateData['must_change_password'] = false;
                $updateData['password_expires_at'] = now()->addMonths(6);

                // Send password changed notification
                SendPasswordChangedNotification::dispatch($user);
            }

            // ✅ AUDIT: All profile changes tracked automatically
            // Event: "profile_updated" (if profile fields changed - from transformAudit)
            // Event: "password_changed" (if password changed - from transformAudit)
            // NOTE: Actual password hash is NOT audited (excluded in $auditExclude)
            $user->update($updateData);

            return updatedResponse($user->fresh(), 'Profile updated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to update profile', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update profile', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NEW AUDIT TRAIL ENDPOINTS
    |--------------------------------------------------------------------------
    */

    /**
     * Get user's complete audit history
     *
     * @OA\Get(
     *     path="/api/auth/user/{id}/audits",
     *     operationId="getUserAudits",
     *     tags={"Authentication"},
     *     summary="Get user audit trail",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Audit trail retrieved successfully")
     * )
     */
    /**
 * Get user's audit logs with optional filters
 * Can be used by admins to view other users' audits or by users to view their own
 */
public function getUserAudits(Request $request, $userId)
{
    // Find the target user
    $targetUser = User::find($userId);
    
    if (!$targetUser) {
        return errorResponse('User not found', 404);
    }

    // Get filter parameters
    $eventType = $request->input('event_type'); // login, password, profile, status
    $fromDate = $request->input('from_date');
    $toDate = $request->input('to_date');
    $limit = $request->input('limit', 50); // Default 50, max 100

    // Build query - Get audits where user performed the action (causer only)
    $query = \OwenIt\Auditing\Models\Audit::where('user_id', $userId)  // User performed the action
    ->where('auditable_type', 'App\\Models\\User') // Only user-related audits
    ->with(['user']); // Load the causer relationship

    // ✅ Filter by event type
    if ($eventType) {
        switch ($eventType) {
            case 'login':
                $query->whereIn('event', ['user_login', 'login_failed']);
                break;
            case 'password':
                $query->where('event', 'password_changed');
                break;
            case 'profile':
                $query->whereIn('event', ['updated', 'profile_updated']);
                break;
            case 'status':
                $query->whereIn('event', ['status_changed', 'account_activated', 'account_deactivated']);
                break;
        }
    }

    // ✅ Filter by date range
    if ($fromDate) {
        $query->where('created_at', '>=', $fromDate);
    }
    if ($toDate) {
        $query->where('created_at', '<=', $toDate);
    }

    // Apply limit (max 100)
    $limit = min($limit, 100);

    $audits = $query->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get()
        ->map(function ($audit) {
            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'ip_address' => $audit->ip_address,
                'user_agent' => $audit->user_agent,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'created_at' => $audit->created_at->toDateTimeString(),
                // User is always the causer since we filter by user_id
                'changed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->firstname . ' ' . $audit->user->lastname,
                    'email' => $audit->user->email,
                ] : null,
            ];
        });

    return successResponse('User audits retrieved successfully', [
        'user' => [
            'id' => $targetUser->id,
            'name' => $targetUser->firstname . ' ' . $targetUser->lastname,
            'email' => $targetUser->email,
        ],
        'audits' => $audits,
        'filters_applied' => [
            'event_type' => $eventType,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'limit' => $limit,
        ],
    ]);
}

    /**
     * Get user's login history
     *
     * @OA\Get(
     *     path="/api/auth/user/{id}/audits/login-history",
     *     operationId="getUserLoginHistory",
     *     tags={"Authentication"},
     *     summary="Get user login history",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Login history retrieved successfully")
     * )
     */
    public function getLoginHistory(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $loginHistory = $user->getLoginAuditHistory();

            return successResponse('Login history retrieved successfully', $loginHistory);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve login history', $e->getMessage());
        }
    }

    /**
     * Get user's password change history
     *
     * @OA\Get(
     *     path="/api/auth/user/{id}/audits/password-changes",
     *     operationId="getUserPasswordChangeHistory",
     *     tags={"Authentication"},
     *     summary="Get user password change history",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Password change history retrieved successfully")
     * )
     */
    public function getPasswordChangeHistory(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $passwordHistory = $user->getPasswordChangeHistory();

            return successResponse('Password change history retrieved successfully', $passwordHistory);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve password change history', $e->getMessage());
        }
    }

    /**
     * Get user's profile change history
     *
     * @OA\Get(
     *     path="/api/auth/user/{id}/audits/profile-changes",
     *     operationId="getUserProfileChangeHistory",
     *     tags={"Authentication"},
     *     summary="Get user profile change history",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Profile change history retrieved successfully")
     * )
     */
    public function getProfileChangeHistory(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $profileHistory = $user->getProfileChangeHistory();

            return successResponse('Profile change history retrieved successfully', $profileHistory);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve profile change history', $e->getMessage());
        }
    }

    /**
     * Get user's status change history
     *
     * @OA\Get(
     *     path="/api/auth/user/{id}/audits/status-changes",
     *     operationId="getUserStatusChangeHistory",
     *     tags={"Authentication"},
     *     summary="Get user status change history",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Status change history retrieved successfully")
     * )
     */
    public function getStatusChangeHistory(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $statusHistory = $user->getStatusChangeHistory();

            return successResponse('Status change history retrieved successfully', $statusHistory);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve status change history', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | OTHER AUTHENTICATION FUNCTIONS (No audit needed - read-only or external)
    |--------------------------------------------------------------------------
    */

    /**
     * Church user login with employee code only (no password needed)
     * ✅ AUDIT: Updates last_login_at
     */
  public function loginWithCode(Request $request)
{
    $validator = Validator::make($request->all(), [
        'employee_code' => 'required|string|size:6',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    $user = User::where('employee_code', $request->employee_code)->first();

    if (!$user) {
        return errorResponse('Invalid employee code', 401);
    }

    // Check account status
    if (!$user->isActive()) {
        return errorResponse('Account is inactive. Please contact administrator.', 403);
    }

    // Create access token directly (no password or OTP needed)
    $accessToken = $user->createToken('Api-Access')->plainTextToken;

    // Get territorial data
    $territorialRoles = $this->getUserTerritorialRoles($user);
    $defaultRole = $this->getDefaultRole($user);

    // ✅ Update last login
    $user->update(['last_login_at' => now()]);

    // ✅ MANUALLY CREATE LOGIN AUDIT EVENT (Employee Code Login)
    $user->audits()->create([
        'event' => 'user_login',
        'auditable_type' => User::class,
        'auditable_id' => $user->id,
        'user_type' => User::class,
        'user_id' => $user->id,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'old_values' => [],
        'new_values' => [
            'last_login_at' => now()->toDateTimeString(),
            'login_success' => true,
            'login_method' => 'employee_code',
        ],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return successResponse('Login successful', [
        'token' => $accessToken,
        'user' => $user->load(['activeAssignments.role', 'activeAssignments.territory']),
        'territorial_roles' => $territorialRoles,
        'current_role' => $defaultRole,
        'permissions' => $this->getUserPermissions($user, $defaultRole['assignment_id'] ?? null)
    ]);
}
    /**
     * Switch between territorial roles (No audit needed - read-only)
     */
    public function switchRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'assignment_id' => 'required|exists:user_territory_assignments,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();
            $assignmentId = $request->input('assignment_id');

            // Verify user owns this assignment
            $assignment = $user->activeAssignments()
                              ->where('id', $assignmentId)
                              ->with(['role', 'territory'])
                              ->first();

            if (!$assignment) {
                return errorResponse('Invalid role assignment', 403);
            }

            // Get permissions for this role/territory combination
            $permissions = $this->getUserPermissions($user, $assignmentId);

            // Get accessible territories for this assignment
            $accessibleTerritories = $assignment->getAccessibleTerritories();

            $roleData = [
                'assignment_id' => $assignment->id,
                'role' => $assignment->role,
                'territory' => $assignment->territory,
                'assignment_type' => $assignment->assignment_type,
                'permissions' => $permissions,
                'accessible_territories' => $accessibleTerritories,
                'territory_scope' => $assignment->role->territory_level,
            ];

            return successResponse('Role switched successfully', $roleData);
        } catch (\Exception $e) {
            Log::error('Role switching failed', [
                'user_id' => $request->user()->id,
                'assignment_id' => $request->input('assignment_id'),
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to switch role', $e->getMessage());
        }
    }

    /**
     * Submit support request (No audit needed - just sends email)
     */
    public function submitSupportRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'subject' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();
            $message = $request->message;
            $subject = $request->input('subject', 'Support Request from ' . $user->full_name);

            SendSupportEmail::dispatch($user, $message, $subject);

            return successResponse('Support request sent successfully. We will get back to you soon.');
        } catch (\Exception $e) {
            Log::error('Failed to send support request', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to send support request', $e->getMessage());
        }
    }

    /**
     * Logout user (No audit needed - just deletes token)
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return successResponse('Successfully logged out');
        } catch (\Exception $e) {
            Log::error('Failed to logout', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to logout', $e->getMessage());
        }
    }

    // === HELPER METHODS ===

    /**
     * Get user permissions for a specific assignment (their active "hat"), or
     * their PRIMARY assignment if no assignment_id is given (e.g. on login,
     * before any role has been switched to).
     *
     * Previously this always resolved from the PRIMARY assignment regardless
     * of $assignmentId, including when called from switchRole() — so switching
     * to a secondary assignment (e.g. a pastor's Regional Committee Member or
     * Diocese Council Member seat) changed what the UI displayed but not what
     * the user was actually permitted to do.
     */
    public function getUserPermissions(User $user, $assignmentId = null)
    {
        $assignment = $assignmentId
            ? $user->activeAssignments()->where('id', $assignmentId)->with(['role.permissions', 'territory'])->first()
            : null;

        // Fall back to PRIMARY if no assignment_id was given, or it didn't
        // resolve to one of this user's active assignments.
        if (!$assignment) {
            $assignment = $user->activeAssignments()
                                 ->where('assignment_type', 'primary')
                                 ->with(['role.permissions', 'territory'])
                                 ->first();
        }

        if (!$assignment) {
            Log::error('No resolvable assignment found for user', [
                'user_id' => $user->id,
                'username' => $user->username,
                'requested_assignment_id' => $assignmentId,
            ]);
            return [];
        }

        // Get the territory type of the resolved assignment
        $territoryType = $assignment->territory->territory_type;

        // Get all permissions from the role, but FILTER by territory_scope
        $permissions = $assignment->role->permissions()
                                               ->where('territory_scope', $territoryType)
                                               ->pluck('name')
                                               ->toArray();

        Log::info('Permissions loaded from assignment with territory filtering', [
            'user_id' => $user->id,
            'username' => $user->username,
            'assignment_id' => $assignment->id,
            'assignment_type' => $assignment->assignment_type,
            'territory_name' => $assignment->territory->name,
            'territory_type' => $territoryType,
            'role_name' => $assignment->role->name,
            'total_role_permissions' => $assignment->role->permissions->count(),
            'filtered_permissions_count' => count($permissions),
            'territory_scope_filter' => $territoryType,
        ]);

        return $permissions;
    }

    /**
     * Get user's territorial role assignments
     */
    private function getUserTerritorialRoles(User $user): array
    {
        return $user->activeAssignments()
                   ->with(['role', 'territory'])
                   ->get()
                   ->map(function ($assignment) {
                       return [
                           'assignment_id' => $assignment->id,
                           'role_name' => $assignment->role->name,
                           'territory_name' => $assignment->territory->name,
                           'territory_type' => $assignment->territory->territory_type,
                           'assignment_type' => $assignment->assignment_type,
                           'is_primary' => $assignment->is_primary,
                       ];
                   })
                   ->toArray();
    }

    /**
     * Get user's default/primary role
     */
   private function getDefaultRole(User $user): ?array
{
    $primaryAssignment = $user->getPrimaryAssignment();

    if ($primaryAssignment) {
        return [
            'assignment_id' => $primaryAssignment->id,
            'role_name' => $primaryAssignment->role->name,
            'territory_name' => $primaryAssignment->territory->name,
            'territory_type' => $primaryAssignment->territory->territory_type->value,  // ← ADD ->value
            'territory_scope' => $primaryAssignment->territory->territory_type->value,  // ← FIX: Use territory type, not role level
        ];
    }

    // If no primary assignment, get first active assignment
    $firstAssignment = $user->activeAssignments()->with(['role', 'territory'])->first();

    if ($firstAssignment) {
        return [
            'assignment_id' => $firstAssignment->id,
            'role_name' => $firstAssignment->role->name,
            'territory_name' => $firstAssignment->territory->name,
            'territory_type' => $firstAssignment->territory->territory_type->value,    // ← ADD ->value
            'territory_scope' => $firstAssignment->territory->territory_type->value,    // ← FIX: Use territory type, not role level
        ];
    }

    return null;
}
}