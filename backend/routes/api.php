<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ModuleGroupController;
use App\Http\Controllers\Api\TerritoryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\UserTerritoryAssignmentController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\BudgetTypeController;
use App\Http\Controllers\Api\BudgetCategoryController;
use App\Http\Controllers\Api\BudgetLineController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\BudgetLogController;
use App\Http\Controllers\Api\BudgetDeductionController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\BudgetPeriodController;
use App\Http\Controllers\Api\DemographicsController;
use App\Http\Controllers\Api\AttendanceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/user', function (Request $request) {
    return $request->user()->load(['activeAssignments.role', 'activeAssignments.territory']);
})->middleware('auth:sanctum');

// === PUBLIC ROUTES (No Authentication Required) ===
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login-code', [AuthController::class, 'loginWithCode']);
    Route::post('force-password-change', [AuthController::class, 'forcePasswordChange']);
});
// PUBLIC ROUTES (No Authentication Required)
Route::prefix('password-reset')->group(function () {
    Route::post('request', [PasswordResetController::class, 'requestPasswordReset']);                    // Request password reset via email
   Route::post('verify-token', [PasswordResetController::class, 'verifyResetToken']);                  // Verify reset token validity
   Route::post('reset-with-token', [PasswordResetController::class, 'resetPasswordWithToken']);        // Reset password using token
   Route::post('request-employee-code', [PasswordResetController::class, 'requestEmployeeCodeReset']); // Request new employee code via email
});
// support ticket submission (public)
Route::post('support-tickets', [App\Http\Controllers\Api\SupportTicketController::class, 'store']);
  Route::get('/system-admin/contact', [UserController::class, 'getSystemAdminContact']);

// === PROTECTED ROUTES (Authentication Required) ===
Route::middleware(['auth:sanctum'])->group(function () {

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('switch-role', [AuthController::class, 'switchRole']);
        Route::put('profile', [AuthController::class, 'profileChange']);
        Route::post('support', [AuthController::class, 'submitSupportRequest']);
    });
    // User Self-Service Password Management
    Route::prefix('password-reset')->group(function () {
        Route::post('change-password', [PasswordResetController::class, 'changeOwnPassword']);           // User changes own password
        Route::post('change-employee-code', [PasswordResetController::class, 'changeOwnEmployeeCode']); // User changes own employee code
    });

    // Admin Password Management (require admin permissions)
    Route::prefix('admin/password-reset')->group(function () {
        Route::post('reset-user-password', [PasswordResetController::class, 'adminResetUserPassword']);     // Admin resets any user password
        Route::post('change-employee-code', [PasswordResetController::class, 'adminChangeEmployeeCode']);   // Admin changes any user employee code
    });


    // Role Management Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);                    // List all roles with territorial filtering
        Route::post('/', [RoleController::class, 'store']);                   // Create new role
        Route::get('/{role}', [RoleController::class, 'show']);               // Show role with detailed permissions
        Route::put('/{role}', [RoleController::class, 'update']);             // Update role information
        Route::delete('/{role}', [RoleController::class, 'destroy']);         // Delete role

        // Permission Management (Main Feature)
        Route::put('/{role}/permissions', [RoleController::class, 'updatePermissions']);  // Assign module/submodule permissions

        // Role Assignment Management
        Route::get('/{role}/users', [RoleController::class, 'getRoleUsers']); // Get users with this role
    });

    // Permission Management Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);                        // List all permissions with filtering
        Route::post('/', [PermissionController::class, 'store']);                       // Create new permission
        Route::get('/grouped', [PermissionController::class, 'getGroupedPermissions']); // Get permissions grouped by module structure (for role assignment)
        Route::get('/for-role-management', [PermissionController::class, 'getPermissionsForRoleManagement']); // Get permissions with IDs for role management UI
        Route::get('/{permission}', [PermissionController::class, 'show']);             // Show specific permission
        Route::put('/{permission}', [PermissionController::class, 'update']);           // Update permission
        Route::delete('/{permission}', [PermissionController::class, 'destroy']);       // Delete permission
    });

    // Module Group Management Routes
    Route::prefix('module-groups')->group(function () {
        Route::get('/', [ModuleGroupController::class, 'index']);                                      // List all module groups (filterable by territory)
        Route::post('/', [ModuleGroupController::class, 'store']);                                     // Create module group
        Route::get('/territory/{territoryScope}', [ModuleGroupController::class, 'getByTerritory']);   // Get module groups by territory scope
        Route::get('/{moduleGroup}', [ModuleGroupController::class, 'show']);                          // Show module group with modules
        Route::put('/{moduleGroup}', [ModuleGroupController::class, 'update']);                        // Update module group
        Route::delete('/{moduleGroup}', [ModuleGroupController::class, 'destroy']);                    // Delete module group
        Route::patch('/{moduleGroup}/order', [ModuleGroupController::class, 'updateOrder']);           // Update module group order
    });

    // Module Management Routes
    Route::prefix('modules')->group(function () {
        // Basic Module CRUD
        Route::get('/', [ModuleController::class, 'index']);                              // List all modules
        Route::get('/for-role', [ModuleController::class, 'getModulesForRole']);          // Get modules filtered by user's role permissions
        Route::get('/groups', [ModuleController::class, 'getModuleGroups']);              // List module groups for selection
        Route::post('/', [ModuleController::class, 'store']);                             // Create module
        Route::get('/{module}', [ModuleController::class, 'show']);                       // Show module with full hierarchy
        Route::put('/{module}', [ModuleController::class, 'update']);                     // Update module
        Route::delete('/{module}', [ModuleController::class, 'destroy']);                 // Delete module

        // Submodule Management (nested under modules)
        Route::get('/{module}/submodules', [ModuleController::class, 'getSubmodules']);           // Get submodules for module
        Route::post('/{module}/submodules', [ModuleController::class, 'storeSubmodule']);         // Create submodule
        Route::put('/{module}/submodules/{submodule}', [ModuleController::class, 'updateSubmodule']);     // Update submodule
        Route::delete('/{module}/submodules/{submodule}', [ModuleController::class, 'destroySubmodule']); // Delete submodule

        // Sub-Submodule Management (nested under submodules)
        Route::get('/{module}/submodules/{submodule}/sub-submodules', [ModuleController::class, 'getSubSubmodules']);                    // Get sub-submodules
        Route::post('/{module}/submodules/{submodule}/sub-submodules', [ModuleController::class, 'storeSubSubmodule']);                 // Create sub-submodule
        Route::get('/{module}/submodules/{submodule}/sub-submodules/{subSubmodule}', [ModuleController::class, 'showSubSubmodule']);     // Get single sub-submodule
        Route::put('/{module}/submodules/{submodule}/sub-submodules/{subSubmodule}', [ModuleController::class, 'updateSubSubmodule']);   // Update sub-submodule
        Route::delete('/{module}/submodules/{submodule}/sub-submodules/{subSubmodule}', [ModuleController::class, 'destroySubSubmodule']); // Delete sub-submodule

        // Module Ordering & Movement
        Route::patch('/{module}/order', [ModuleController::class, 'updateModuleOrder']);      // Update module order number
    });

    // Submodule Management (outside modules prefix for cleaner routing)
    Route::patch('/submodules/{submodule}/move', [ModuleController::class, 'moveSubmodule']); // Move submodule to different module
    Route::get('/submodules/{submodule}', [ModuleController::class, 'showSubmodule']);         // Get single submodule details

    // Diocese Management Routes (Type-Specific)
    Route::prefix('dioceses')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\DioceseController::class, 'index']);                      // List all dioceses
        Route::post('/', [App\Http\Controllers\Api\DioceseController::class, 'store']);                     // Create diocese
        Route::get('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'show']);              // Get diocese details
        Route::put('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'update']);            // Update diocese
        Route::delete('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'destroy']);        // Delete diocese
        Route::get('/{diocese}/regions', [App\Http\Controllers\Api\DioceseController::class, 'getRegions']); // Get regions in diocese
        Route::get('/{diocese}/hierarchy', [App\Http\Controllers\Api\DioceseController::class, 'getHierarchy']); // Get full hierarchy tree
    });

    // Region Management Routes (Type-Specific)
    Route::prefix('regions')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\RegionController::class, 'index']);                        // List all regions
        Route::post('/', [App\Http\Controllers\Api\RegionController::class, 'store']);                       // Create region
        Route::get('/{region}', [App\Http\Controllers\Api\RegionController::class, 'show']);                 // Get region details
        Route::put('/{region}', [App\Http\Controllers\Api\RegionController::class, 'update']);               // Update region
        Route::delete('/{region}', [App\Http\Controllers\Api\RegionController::class, 'destroy']);           // Delete region
        Route::get('/{region}/subregions', [App\Http\Controllers\Api\RegionController::class, 'getSubRegions']); // Get subregions in region
    });

    // Church Management Routes (Type-Specific)
    Route::prefix('churches')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ChurchController::class, 'index']);                        // List all churches
        Route::post('/', [App\Http\Controllers\Api\ChurchController::class, 'store']);                       // Create church
        Route::get('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'show']);                 // Get church details
        Route::put('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'update']);               // Update church
        Route::delete('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'destroy']);           // Delete church
    });

    // Territory Management Routes
    Route::prefix('territories')->group(function () {
        // Basic Territory CRUD
        Route::get('/', [TerritoryController::class, 'index']);                     // List territories with filtering
        Route::post('/', [TerritoryController::class, 'store']);                    // Create territory
        Route::get('/{territory}', [TerritoryController::class, 'show']);           // Show territory with details
        Route::put('/{territory}', [TerritoryController::class, 'update']);         // Update territory
        Route::delete('/{territory}', [TerritoryController::class, 'destroy']);     // Delete territory

        // Special Territory Endpoints
        Route::get('/hierarchy/tree', [TerritoryController::class, 'getHierarchy']); // Get complete hierarchy tree
        Route::get('/by-type/list', [TerritoryController::class, 'getByType']);      // Get territories by type (diocese/region/subregion/church)
    });

    // User Management Routes
    Route::prefix('users')->group(function () {
        // Basic User CRUD
        Route::get('/', [UserController::class, 'index']);                           // List users with territorial filtering
        Route::post('/', [UserController::class, 'store']);                          // Create user (with optional territorial assignment)
        Route::get('/{user}', [UserController::class, 'show']);                      // Show user with detailed assignments
        Route::put('/{user}', [UserController::class, 'update']);                    // Update user information
        Route::patch('/{user}/status', [UserController::class, 'updateStatus']);     // Quick status update (activate/deactivate)
        Route::delete('/{user}', [UserController::class, 'destroy']);                // Delete user (with safety checks)

        // User Management Actions
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword']); // Reset user password
    });


    // User Territorial Assignment Routes
    Route::prefix('user-assignments')->group(function () {
        // Assignment CRUD
        Route::get('/', [UserTerritoryAssignmentController::class, 'index']);                    // List assignments with filtering
        Route::post('/', [UserTerritoryAssignmentController::class, 'store']);                   // Create assignment
        Route::get('/{assignment}', [UserTerritoryAssignmentController::class, 'show']);         // Show assignment details
        Route::put('/{assignment}', [UserTerritoryAssignmentController::class, 'update']);       // Update assignment
        Route::delete('/{assignment}', [UserTerritoryAssignmentController::class, 'destroy']);   // Remove assignment (soft delete)
        // user assignements
        Route::post('/user/{userId}/switch-primary', [UserTerritoryAssignmentController::class, 'switchPrimaryAssignment']);

        // Special Assignment Endpoints
        Route::get('/user/{user}', [UserTerritoryAssignmentController::class, 'getUserAssignments']);           // Get all assignments for a user
        Route::get('/territory/{territory}', [UserTerritoryAssignmentController::class, 'getTerritoryAssignments']); // Get all assignments for a territory

        //  Audit Trail Endpoints
        Route::get('/{assignment}/audits', [UserTerritoryAssignmentController::class, 'getAssignmentAudits']);              // Get complete audit trail for assignment
        Route::get('/{assignment}/audits/territory-changes', [UserTerritoryAssignmentController::class, 'getTerritoryChanges']); // Get territory change history
        Route::get('/{assignment}/audits/role-changes', [UserTerritoryAssignmentController::class, 'getRoleChanges']);          // Get role change history
    });

        // ✅ NEW: User Assignment History (placed outside user-assignments prefix for cleaner routing)
        Route::get('/users/{userId}/assignment-history', [UserTerritoryAssignmentController::class, 'getUserAssignmentHistory']); // Get user's complete assignment audit history
        // user assignement
         // ✅ ADD THESE AUDIT ROUTES:
        Route::get('/auth/user/{id}/audits', [AuthController::class, 'getUserAudits']);
        Route::get('/auth/user/{id}/audits/login-history', [AuthController::class, 'getLoginHistory']);
        Route::get('/auth/user/{id}/audits/password-changes', [AuthController::class, 'getPasswordChangeHistory']);
        Route::get('/auth/user/{id}/audits/profile-changes', [AuthController::class, 'getProfileChangeHistory']);
        Route::get('/auth/user/{id}/audits/status-changes', [AuthController::class, 'getStatusChangeHistory']);
        // support tickets
        // Protected routes (Add these inside your existing auth:sanctum middleware group)
         Route::get('/support-tickets', [SupportTicketController::class, 'index']);
         Route::get('/support-tickets/{id}', [SupportTicketController::class, 'show']);
         Route::get('/support-tickets/{id}/activity-log', [SupportTicketController::class, 'activityLog']);
         Route::get('/support-tickets/{id}/status-history', [SupportTicketController::class, 'statusHistory']);
         // Admin routes (Add to your auth:sanctum group)
         Route::put('/support-tickets/{id}/status', [SupportTicketController::class, 'updateStatus']);
         Route::put('/support-tickets/{id}/assign', [SupportTicketController::class, 'assignTicket']);
         Route::post('/support-tickets/{id}/notes', [SupportTicketController::class, 'addNotes']);
         Route::put('/support-tickets/{id}/resolve', [SupportTicketController::class, 'resolveTicket']);
         Route::put('/support-tickets/{id}/close', [SupportTicketController::class, 'closeTicket']);

    // Budget Management Routes (Settings)
    // Budget Types
    Route::prefix('budget-types')->group(function () {
        Route::get('/', [BudgetTypeController::class, 'index']);                      // List all budget types
        Route::post('/', [BudgetTypeController::class, 'store']);                     // Create budget type
        Route::get('/{budgetType}', [BudgetTypeController::class, 'show']);           // Get budget type
        Route::get('/{budgetType}/audits', [BudgetTypeController::class, 'getAudits']); // Get audit trail
        Route::put('/{budgetType}', [BudgetTypeController::class, 'update']);         // Update budget type
        Route::delete('/{budgetType}', [BudgetTypeController::class, 'destroy']);     // Delete budget type
    });

    // Budget Categories
    Route::prefix('budget-categories')->group(function () {
        Route::get('/', [BudgetCategoryController::class, 'index']);                      // List all budget categories
        Route::post('/', [BudgetCategoryController::class, 'store']);                     // Create budget category
        Route::get('/{budgetCategory}', [BudgetCategoryController::class, 'show']);       // Get budget category with lines
        Route::get('/{budgetCategory}/audits', [BudgetCategoryController::class, 'getAudits']); // Get audit trail
        Route::put('/{budgetCategory}', [BudgetCategoryController::class, 'update']);     // Update budget category
        Route::delete('/{budgetCategory}', [BudgetCategoryController::class, 'destroy']); // Delete budget category
    });

    // Budget Lines
    Route::prefix('budget-lines')->group(function () {
        Route::get('/', [BudgetLineController::class, 'index']);                                // List all budget lines (with filtering)
        Route::get('/grouped', [BudgetLineController::class, 'getGroupedByCategory']);          // Get lines grouped by category
        Route::post('/', [BudgetLineController::class, 'store']);                               // Create budget line
        Route::get('/{budgetLine}', [BudgetLineController::class, 'show']);                     // Get budget line
        Route::get('/{budgetLine}/audits', [BudgetLineController::class, 'getAudits']);         // Get audit trail
        Route::put('/{budgetLine}', [BudgetLineController::class, 'update']);                   // Update budget line
        Route::patch('/{budgetLine}/order', [BudgetLineController::class, 'updateOrder']);      // Update display order
        Route::delete('/{budgetLine}', [BudgetLineController::class, 'destroy']);               // Delete budget line
    });

    // Statuses (System-wide)
    Route::prefix('statuses')->group(function () {
        Route::get('/', [StatusController::class, 'index']);                                    // List all statuses (filter by category)
        Route::post('/', [StatusController::class, 'store']);                                   // Create status
        Route::get('/{status}', [StatusController::class, 'show']);                             // Get status details
        Route::put('/{status}', [StatusController::class, 'update']);                           // Update status
        Route::patch('/{status}/order', [StatusController::class, 'updateOrder']);              // Update display order
        Route::delete('/{status}', [StatusController::class, 'destroy']);                       // Delete status
        Route::get('/{status}/audits', [StatusController::class, 'getAudits']);                 // Get audit trail
    });

    // Budgets (Territory-Agnostic)
    Route::prefix('budgets')->group(function () {
        Route::get('/', [BudgetController::class, 'index']);                                    // List all budgets (with filtering)
        Route::get('/export', [BudgetController::class, 'export']);                             // Export budgets to Excel
        Route::post('/', [BudgetController::class, 'store']);                                   // Create budget
        Route::get('/{budget}', [BudgetController::class, 'show']);                             // Get budget details
        Route::put('/{budget}', [BudgetController::class, 'update']);                           // Update budget
        Route::delete('/{budget}', [BudgetController::class, 'destroy']);                       // Delete budget

        // Workflow Actions
        Route::post('/{budget}/submit', [BudgetController::class, 'submit']);                   // Submit for review
        Route::post('/{budget}/approve', [BudgetController::class, 'approve']);                 // Approve budget
        Route::post('/{budget}/reject', [BudgetController::class, 'reject']);                   // Reject budget
        Route::post('/{budget}/activate', [BudgetController::class, 'activate']);               // Activate budget
        Route::post('/{budget}/close', [BudgetController::class, 'close']);                     // Close budget
        Route::post('/{budget}/clone', [BudgetController::class, 'clone']);                     // Clone to new year

        // Line Items
        Route::get('/{budget}/line-items', [BudgetController::class, 'getLineItems']);          // Get all line items
        Route::put('/line-items/{lineItem}', [BudgetController::class, 'updateLineItem']);      // Update line item

        // Audit & Summary
        Route::get('/{budget}/audits', [BudgetController::class, 'getAudits']);                 // Get audit trail
        Route::get('/{budget}/summary', [BudgetController::class, 'getSummary']);               // Get financial summary

        // Deductions
        Route::get('/{budget}/deductions', [BudgetController::class, 'getDeductions']);          // Get all deductions for budget
        Route::post('/{budget}/deductions', [BudgetController::class, 'applyDeduction']);        // Apply deduction to budget
        Route::post('/{budget}/deductions/{deductionItem}/reverse', [BudgetController::class, 'reverseDeduction']); // Reverse deduction
        Route::post('/{budget}/recalculate-deductions', [BudgetController::class, 'recalculateDeductions']); // Recalculate totals

        // Budget Logs
        Route::get('/{budget}/logs', [BudgetLogController::class, 'index']);                     // Get all logs for budget
        Route::get('/{budget}/logs/{log}', [BudgetLogController::class, 'show']);                // Get specific log entry
    });

    // Budget Logs (Global)
    Route::prefix('budget-logs')->group(function () {
        Route::get('/recent', [BudgetLogController::class, 'recent']);                           // Get recent logs across all budgets
    });

    // Demographics (Church-level entry - Phase 3 of the Demographics module plan)
    Route::prefix('demographics')->group(function () {
        Route::get('/', [DemographicsController::class, 'index']);                               // List own church's submissions
        Route::post('/', [DemographicsController::class, 'store']);                              // Create this month's draft
        Route::get('/{demographic}', [DemographicsController::class, 'show']);                   // Get one submission
        Route::put('/{demographic}', [DemographicsController::class, 'update']);                 // Update a draft/changes_requested submission
        Route::post('/{demographic}/submit', [DemographicsController::class, 'submit']);         // Submit for Subregion review

        // Subregion review actions (Phase 4)
        Route::post('/{demographic}/approve', [DemographicsController::class, 'approve']);       // Approve, forward to region
        Route::post('/{demographic}/flag', [DemographicsController::class, 'flag']);              // Flag an anomaly, doesn't block forwarding
        Route::post('/{demographic}/request-changes', [DemographicsController::class, 'requestChanges']); // Send back to pastor
    });

    // Church entry-mode setting (weekly_and_monthly vs monthly_only)
    Route::prefix('churches/{church}/entry-mode')->group(function () {
        Route::get('/', [DemographicsController::class, 'getEntryMode']);
        Route::put('/', [DemographicsController::class, 'updateEntryMode']);
    });

    // Attendance (Church-level entry - Phase 3 of the Demographics module plan)
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);                                 // List own church's attendance records
        Route::post('/', [AttendanceController::class, 'store']);                                // Record one service/event/gathering
        Route::put('/{attendance}', [AttendanceController::class, 'update']);                    // Update a record
    });

    // Budget Deductions
    Route::prefix('budget-deductions')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\BudgetDeductionController::class, 'index']);                           // List all deductions
        Route::post('/', [App\Http\Controllers\Api\BudgetDeductionController::class, 'store']);                          // Create deduction
        Route::get('/{budgetDeduction}', [App\Http\Controllers\Api\BudgetDeductionController::class, 'show']);           // Get deduction details
        Route::put('/{budgetDeduction}', [App\Http\Controllers\Api\BudgetDeductionController::class, 'update']);         // Update deduction
        Route::patch('/{budgetDeduction}/order', [App\Http\Controllers\Api\BudgetDeductionController::class, 'updateOrder']); // Update display order
        Route::delete('/{budgetDeduction}', [App\Http\Controllers\Api\BudgetDeductionController::class, 'destroy']);     // Delete deduction
        Route::get('/{budgetDeduction}/audits', [App\Http\Controllers\Api\BudgetDeductionController::class, 'getAudits']); // Get audit trail
    });

    // Fiscal Year Management
    Route::prefix('fiscal-years')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index']);                      // List all fiscal years
        Route::get('/{id}', [FiscalYearController::class, 'show']);                   // Get fiscal year with quarters/semi-annuals
    });

    // Budget Periods
    Route::prefix('budget-periods')->group(function () {
        Route::get('/', [BudgetPeriodController::class, 'index']);                    // List periods (filter by fiscal_year_id, budget_type_id)
        Route::get('/by-year', [BudgetPeriodController::class, 'byYear']);            // Get periods grouped by type for a year
        Route::get('/{id}', [BudgetPeriodController::class, 'show']);                 // Get single period details
    });
});