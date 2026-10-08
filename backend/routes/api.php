<?php

use App\Http\Controllers\Api\Activities\ActivitiesController;
use App\Http\Controllers\Api\AppearanceController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetPeriodController;
use App\Http\Controllers\Api\BudgetTypeController;
use App\Http\Controllers\Api\Calendar\CalendarController;
use App\Http\Controllers\Api\Calendar\CciCalendarController;
use App\Http\Controllers\Api\DemographicsController;
use App\Http\Controllers\Api\DemographicsReportController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\GatheringCategoryController;
use App\Http\Controllers\Api\GatheringTypeController;
use App\Http\Controllers\Api\Messages\MessagesController;
use App\Http\Controllers\Api\Messages\TemplatesController as MessageTemplatesController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ModuleGroupController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\People\PeopleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Reports\MonthlyReportsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\Settings\AuditController as SettingsAuditController;
use App\Http\Controllers\Api\Settings\CommunicationController as SettingsCommunicationController;
use App\Http\Controllers\Api\Settings\HubController as SettingsHubController;
use App\Http\Controllers\Api\Settings\MessagesController as SettingsMessagesController;
use App\Http\Controllers\Api\Settings\ProfileController as SettingsProfileController;
use App\Http\Controllers\Api\Settings\SectionController as SettingsSectionController;
use App\Http\Controllers\Api\Settings\ServiceTimesController as SettingsServiceTimesController;
use App\Http\Controllers\Api\Settings\SystemController as SettingsSystemController;
use App\Http\Controllers\Api\Settings\TeamController as SettingsTeamController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TerritoryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserTerritoryAssignmentController;
use App\Http\Middleware\RequireAdminPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/user', function (Request $request) {
    return $request->user()->load(['activeAssignments.role', 'activeAssignments.territory']);
})->middleware('auth:sanctum');

// === PUBLIC ROUTES (No Authentication Required) ===
// Public: the Verify page behind every report PDF's QR code. Says whether a
// report is genuine and who made it - never any figures.
Route::get('reports/verify/{code}', [ReportController::class, 'verify'])->middleware('throttle:30,1');

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login-code', [AuthController::class, 'loginWithCode'])->middleware('throttle:10,1');
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
// A place's logo is public - it shows on its pages and documents (Settings > Profile).
Route::get('settings/logo/{territory}', [SettingsProfileController::class, 'logo']);

Route::middleware(['auth:sanctum'])->group(function () {

    // Events (and initiatives) of church, region and diocese (docs/specs/events-initiatives-spec.md)
    // Messages (docs/specs/messages-spec.md)
    Route::get('messages/options', [MessagesController::class, 'options']);
    Route::post('messages/preview', [MessagesController::class, 'preview']);
    Route::post('messages', [MessagesController::class, 'store'])->middleware('throttle:20,1');
    Route::get('messages/sent', [MessagesController::class, 'sent']);
    Route::get('messages/inbox', [MessagesController::class, 'inbox']);
    Route::post('messages/inbox/{recipient}/read', [MessagesController::class, 'read'])->whereNumber('recipient');
    Route::post('messages/inbox/{recipient}/reply', [MessagesController::class, 'reply'])->whereNumber('recipient')->middleware('throttle:30,1');
    Route::get('messages/templates', [MessageTemplatesController::class, 'index']);
    Route::post('messages/templates', [MessageTemplatesController::class, 'store']);
    Route::post('messages/templates/preview', [MessageTemplatesController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('messages/templates/test', [MessageTemplatesController::class, 'test'])->middleware('throttle:5,1');
    Route::put('messages/templates/{id}', [MessageTemplatesController::class, 'update'])->whereNumber('id');
    Route::delete('messages/templates/{id}', [MessageTemplatesController::class, 'destroy'])->whereNumber('id');
    Route::post('messages/templates/{id}/copy', [MessageTemplatesController::class, 'copy'])->whereNumber('id');
    Route::post('messages/templates/{id}/reset', [MessageTemplatesController::class, 'reset'])->whereNumber('id');
    Route::get('messages/{id}', [MessagesController::class, 'show'])->whereNumber('id');
    Route::post('messages/{id}/cancel', [MessagesController::class, 'cancel'])->whereNumber('id');
    Route::post('messages/{id}/retry', [MessagesController::class, 'retry'])->whereNumber('id');

    // Monthly reports (docs/specs/monthly-reports-spec.md)
    Route::get('monthly-reports', [MonthlyReportsController::class, 'index']);
    Route::get('monthly-reports/below', [MonthlyReportsController::class, 'below']);
    Route::get('monthly-reports/{id}', [MonthlyReportsController::class, 'show'])->whereNumber('id');
    Route::post('monthly-reports/{id}/seen', [MonthlyReportsController::class, 'seen'])->whereNumber('id');
    Route::post('monthly-reports/{id}/comments', [MonthlyReportsController::class, 'comment'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('monthly-reports/{id}/attachments', [MonthlyReportsController::class, 'attach'])->whereNumber('id');
    Route::delete('monthly-reports/{id}/attachments/{media}', [MonthlyReportsController::class, 'detach'])->whereNumber(['id', 'media']);
    Route::get('monthly-reports/{id}/attachments/{media}', [MonthlyReportsController::class, 'file'])->whereNumber(['id', 'media']);
    Route::get('monthly-reports/{year}/{month}', [MonthlyReportsController::class, 'month'])->whereNumber(['year', 'month']);
    Route::put('monthly-reports/{year}/{month}', [MonthlyReportsController::class, 'save'])->whereNumber(['year', 'month']);
    Route::post('monthly-reports/{year}/{month}/send', [MonthlyReportsController::class, 'send'])->whereNumber(['year', 'month']);
    Route::post('monthly-reports/{year}/{month}/reopen', [MonthlyReportsController::class, 'reopen'])->whereNumber(['year', 'month']);

    // People & care P1 - the church's private member register; /people/totals is
    // the region's and diocese's (counts only) - docs/specs/people-and-care-spec.md
    Route::get('people/overview', [PeopleController::class, 'overview']);
    Route::get('people/check', [PeopleController::class, 'check']);
    Route::get('people/insights', [PeopleController::class, 'insights']);
    Route::get('people/register-counts', [PeopleController::class, 'registerCounts']);
    Route::get('people/totals', [PeopleController::class, 'totals']);
    Route::get('people/transfers', [PeopleController::class, 'transfers']);
    Route::post('people/transfer-in', [PeopleController::class, 'transferIn']);
    Route::get('people', [PeopleController::class, 'index']);
    Route::post('people', [PeopleController::class, 'store']);
    Route::get('people/{id}', [PeopleController::class, 'show'])->whereNumber('id');
    Route::put('people/{id}', [PeopleController::class, 'update'])->whereNumber('id');
    Route::get('people/{id}/history', [PeopleController::class, 'history'])->whereNumber('id');
    Route::get('people/{id}/photo', [PeopleController::class, 'photo'])->whereNumber('id');
    Route::post('people/{id}/photo', [PeopleController::class, 'uploadPhoto'])->whereNumber('id');
    Route::delete('people/{id}/photo', [PeopleController::class, 'removePhoto'])->whereNumber('id');
    Route::post('people/{id}/archive', [PeopleController::class, 'archive'])->whereNumber('id');
    Route::post('people/{id}/restore', [PeopleController::class, 'restore'])->whereNumber('id');
    Route::post('people/{id}/anonymise', [PeopleController::class, 'anonymise'])->whereNumber('id');
    Route::post('people/{id}/transfer-out', [PeopleController::class, 'transferOut'])->whereNumber('id');

    Route::get('activities', [ActivitiesController::class, 'index']);
    Route::get('activities/overview', [ActivitiesController::class, 'overview']);
    Route::post('activities', [ActivitiesController::class, 'store']);
    Route::get('activities/{id}', [ActivitiesController::class, 'show'])->whereNumber('id');
    Route::put('activities/{id}', [ActivitiesController::class, 'update'])->whereNumber('id');
    Route::post('activities/{id}/publish', [ActivitiesController::class, 'publish'])->whereNumber('id');
    Route::post('activities/{id}/complete', [ActivitiesController::class, 'complete'])->whereNumber('id');
    Route::post('activities/{id}/cancel', [ActivitiesController::class, 'cancel'])->whereNumber('id');
    Route::get('activities/{id}/registrations', [ActivitiesController::class, 'registrations'])->whereNumber('id');
    Route::post('activities/{id}/register', [ActivitiesController::class, 'register'])->whereNumber('id');
    Route::get('activities/{id}/money', [ActivitiesController::class, 'money'])->whereNumber('id');
    Route::get('activities/{id}/history', [ActivitiesController::class, 'history'])->whereNumber('id');
    Route::put('registrations/{id}', [ActivitiesController::class, 'updateRegistration'])->whereNumber('id');
    Route::post('registrations/{id}/withdraw', [ActivitiesController::class, 'withdraw'])->whereNumber('id');
    Route::get('activities/{id}/sessions', [ActivitiesController::class, 'sessions'])->whereNumber('id');
    Route::post('activities/{id}/sessions', [ActivitiesController::class, 'addSession'])->whereNumber('id');
    Route::put('sessions/{id}', [ActivitiesController::class, 'updateSession'])->whereNumber('id');
    Route::delete('sessions/{id}', [ActivitiesController::class, 'removeSession'])->whereNumber('id');

    // In-app notifications - the header bell and the Notifications page (docs/specs/events-initiatives-spec.md)
    Route::get('notifications', [NotificationsController::class, 'index']);
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationsController::class, 'read']);

    // Calendar - one per church, region and diocese, the CCI calendar on top (docs/specs/calendar-spec.md)
    Route::prefix('calendar')->group(function () {
        Route::get('events', [CalendarController::class, 'events']);
        Route::get('overview', [CalendarController::class, 'overview']);
        Route::get('ics', [CalendarController::class, 'ics']);
        Route::post('events', [CalendarController::class, 'store']);
        Route::put('events/{id}', [CalendarController::class, 'update'])->whereNumber('id');
        Route::delete('events/{id}', [CalendarController::class, 'destroy'])->whereNumber('id');
        Route::get('cci', [CciCalendarController::class, 'index']);
        Route::get('cci/template', [CciCalendarController::class, 'template']);
        Route::post('cci/import', [CciCalendarController::class, 'import'])->middleware('throttle:20,1');
    });

    // Settings hub - one page per church, region and diocese (docs/specs/settings-spec.md)
    Route::prefix('settings')->group(function () {
        Route::get('sections', [SettingsHubController::class, 'sections']);
        Route::get('overview', [SettingsHubController::class, 'overview']);
        Route::get('reference', [SettingsHubController::class, 'reference']);
        Route::get('sections/{section}', [SettingsSectionController::class, 'show']);
        Route::put('sections/{section}', [SettingsSectionController::class, 'update']);
        Route::get('profile', [SettingsProfileController::class, 'show']);
        Route::put('profile', [SettingsProfileController::class, 'update']);
        Route::post('profile/logo', [SettingsProfileController::class, 'uploadLogo']);
        Route::delete('profile/logo', [SettingsProfileController::class, 'removeLogo']);
        Route::get('service-times', [SettingsServiceTimesController::class, 'show']);
        Route::put('service-times', [SettingsServiceTimesController::class, 'update']);
        Route::get('team', [SettingsTeamController::class, 'index']);
        Route::get('team/check', [SettingsTeamController::class, 'check'])->middleware('throttle:30,1');
        Route::post('team', [SettingsTeamController::class, 'store']);
        Route::put('team/{assignment}', [SettingsTeamController::class, 'update'])->whereNumber('assignment');
        Route::delete('team/{assignment}', [SettingsTeamController::class, 'destroy'])->whereNumber('assignment');
        Route::post('team/{assignment}/reset-access', [SettingsTeamController::class, 'resetAccess'])->whereNumber('assignment');
        Route::get('health', [SettingsSystemController::class, 'health']);
        Route::post('test/email', [SettingsSystemController::class, 'testEmail'])->middleware('throttle:5,1');
        Route::post('test/sms', [SettingsSystemController::class, 'testSms'])->middleware('throttle:5,1');
        Route::post('maintenance/retry-failed', [SettingsSystemController::class, 'retryFailed']);
        Route::post('maintenance/{tool}', [SettingsSystemController::class, 'maintenance'])->whereIn('tool', array_keys(SettingsSystemController::TOOLS));
        Route::get('notice', [SettingsSystemController::class, 'notice']);
        Route::post('communication/test', [SettingsCommunicationController::class, 'test'])->middleware('throttle:5,1');
        Route::get('messages', [SettingsMessagesController::class, 'index']);
        Route::get('messages/{id}', [SettingsMessagesController::class, 'show'])->whereNumber('id');
        Route::post('messages/{id}/resend', [SettingsMessagesController::class, 'resend'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('access', [SettingsSystemController::class, 'access']);
        Route::get('audit', [SettingsAuditController::class, 'index']);
    });

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

    // Appearance Settings - self-scoped personal preferences, no territory/permission gate
    Route::prefix('appearance')->group(function () {
        Route::get('/', [AppearanceController::class, 'show']);
        Route::put('/', [AppearanceController::class, 'update']);
        Route::delete('/', [AppearanceController::class, 'destroy']);
    });

    // Admin Password Management (require admin permissions)
    Route::prefix('admin/password-reset')->group(function () {
        Route::post('reset-user-password', [PasswordResetController::class, 'adminResetUserPassword']);     // Admin resets any user password
        Route::post('change-employee-code', [PasswordResetController::class, 'adminChangeEmployeeCode']);   // Admin changes any user employee code
    });

    // Role Management Routes
    Route::prefix('roles')->middleware(RequireAdminPermission::class.':rolemanagement,usermanagement,permissions')->group(function () {
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
    Route::prefix('permissions')->middleware(RequireAdminPermission::class.':permissions,rolemanagement')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);                        // List all permissions with filtering
        Route::post('/', [PermissionController::class, 'store']);                       // Create new permission
        Route::get('/grouped', [PermissionController::class, 'getGroupedPermissions']); // Get permissions grouped by module structure (for role assignment)
        Route::get('/for-role-management', [PermissionController::class, 'getPermissionsForRoleManagement']); // Get permissions with IDs for role management UI
        Route::get('/{permission}', [PermissionController::class, 'show']);             // Show specific permission
        Route::put('/{permission}', [PermissionController::class, 'update']);           // Update permission
        Route::delete('/{permission}', [PermissionController::class, 'destroy']);       // Delete permission
    });

    // Module Group Management Routes
    Route::prefix('module-groups')->middleware(RequireAdminPermission::class.':modulegroups,modules,rolemanagement')->group(function () {
        Route::get('/', [ModuleGroupController::class, 'index']);                                      // List all module groups (filterable by territory)
        Route::post('/', [ModuleGroupController::class, 'store']);                                     // Create module group
        Route::get('/territory/{territoryScope}', [ModuleGroupController::class, 'getByTerritory']);   // Get module groups by territory scope
        Route::get('/{moduleGroup}', [ModuleGroupController::class, 'show']);                          // Show module group with modules
        Route::put('/{moduleGroup}', [ModuleGroupController::class, 'update']);                        // Update module group
        Route::delete('/{moduleGroup}', [ModuleGroupController::class, 'destroy']);                    // Delete module group
        Route::patch('/{moduleGroup}/order', [ModuleGroupController::class, 'updateOrder']);           // Update module group order
    });

    // Module Management Routes
    // Every login builds its sidebar from this - open to any signed-in user.
    Route::get('modules/for-role', [ModuleController::class, 'getModulesForRole']);

    Route::prefix('modules')->middleware(RequireAdminPermission::class.':modules,modulegroups,rolemanagement')->group(function () {
        // Basic Module CRUD
        Route::get('/', [ModuleController::class, 'index']);                              // List all modules
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
    Route::patch('/submodules/{submodule}/move', [ModuleController::class, 'moveSubmodule'])->middleware(RequireAdminPermission::class.':modules'); // Move submodule to different module
    Route::get('/submodules/{submodule}', [ModuleController::class, 'showSubmodule'])->middleware(RequireAdminPermission::class.':modules,modulegroups,rolemanagement');         // Get single submodule details

    // Diocese Management Routes (Type-Specific)
    Route::prefix('dioceses')->middleware(RequireAdminPermission::class.':open-reads')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\DioceseController::class, 'index']);                      // List all dioceses
        Route::post('/', [App\Http\Controllers\Api\DioceseController::class, 'store']);                     // Create diocese
        Route::get('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'show']);              // Get diocese details
        Route::put('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'update']);            // Update diocese
        Route::delete('/{diocese}', [App\Http\Controllers\Api\DioceseController::class, 'destroy']);        // Delete diocese
        Route::get('/{diocese}/regions', [App\Http\Controllers\Api\DioceseController::class, 'getRegions']); // Get regions in diocese
        Route::get('/{diocese}/hierarchy', [App\Http\Controllers\Api\DioceseController::class, 'getHierarchy']); // Get full hierarchy tree
    });

    // Region Management Routes (Type-Specific)
    Route::prefix('regions')->middleware(RequireAdminPermission::class.':open-reads')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\RegionController::class, 'index']);                        // List all regions
        Route::post('/', [App\Http\Controllers\Api\RegionController::class, 'store']);                       // Create region
        Route::get('/{region}', [App\Http\Controllers\Api\RegionController::class, 'show']);                 // Get region details
        Route::put('/{region}', [App\Http\Controllers\Api\RegionController::class, 'update']);               // Update region
        Route::delete('/{region}', [App\Http\Controllers\Api\RegionController::class, 'destroy']);           // Delete region
        Route::get('/{region}/subregions', [App\Http\Controllers\Api\RegionController::class, 'getSubRegions']); // Get subregions in region
    });

    // Church Management Routes (Type-Specific)
    Route::prefix('churches')->middleware(RequireAdminPermission::class.':open-reads')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ChurchController::class, 'index']);                        // List all churches
        Route::post('/', [App\Http\Controllers\Api\ChurchController::class, 'store']);                       // Create church
        Route::get('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'show']);                 // Get church details
        Route::put('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'update']);               // Update church
        Route::delete('/{church}', [App\Http\Controllers\Api\ChurchController::class, 'destroy']);           // Delete church
    });

    // Territory Management Routes
    Route::prefix('territories')->middleware(RequireAdminPermission::class.':open-reads')->group(function () {
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
    Route::prefix('users')->middleware(RequireAdminPermission::class.':usermanagement,self')->group(function () {
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
    Route::prefix('user-assignments')->middleware(RequireAdminPermission::class.':usermanagement,self')->group(function () {
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
    Route::get('/users/{userId}/assignment-history', [UserTerritoryAssignmentController::class, 'getUserAssignmentHistory'])->middleware(RequireAdminPermission::class.':usermanagement,self'); // Get user's complete assignment audit history
    // user assignement
    // ✅ ADD THESE AUDIT ROUTES:
    Route::get('/auth/user/{id}/audits', [AuthController::class, 'getUserAudits'])->middleware(RequireAdminPermission::class.':usermanagement,self');
    Route::get('/auth/user/{id}/audits/login-history', [AuthController::class, 'getLoginHistory'])->middleware(RequireAdminPermission::class.':usermanagement,self');
    Route::get('/auth/user/{id}/audits/password-changes', [AuthController::class, 'getPasswordChangeHistory'])->middleware(RequireAdminPermission::class.':usermanagement,self');
    Route::get('/auth/user/{id}/audits/profile-changes', [AuthController::class, 'getProfileChangeHistory'])->middleware(RequireAdminPermission::class.':usermanagement,self');
    Route::get('/auth/user/{id}/audits/status-changes', [AuthController::class, 'getStatusChangeHistory'])->middleware(RequireAdminPermission::class.':usermanagement,self');
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

    // Budget types - read-only; Demographics tracking uses them to find the fiscal months
    Route::get('/budget-types', [BudgetTypeController::class, 'index']);

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

    // Budgets (Territory-Agnostic) - who may see/act on which budget: EnsureBudgetAccess
    Route::prefix('budgets')->middleware(\App\Http\Middleware\EnsureBudgetAccess::class)->group(function () {
        // A month or a whole year of one place; no approval (docs/specs/budgets-spec.md)
        Route::get('/', [BudgetController::class, 'index']);                                    // This place's budgets (or one below, read-only)
        Route::get('/dashboard', [BudgetController::class, 'dashboard']);                       // The Overview for a month or a year
        Route::get('/below', [BudgetController::class, 'below']);
        Route::get('/contributions', [BudgetController::class, 'contributions']);                // What a place sends up (and, above, its churches)                               // The places below, read-only (region / diocese)
        Route::get('/form', [BudgetController::class, 'form']);                                 // What the New budget form needs
        Route::post('/', [BudgetController::class, 'store']);                                   // Create, with all its lines
        Route::get('/{budget}', [BudgetController::class, 'show']);                             // One budget, with its lines
        Route::get('/{budget}/form', [BudgetController::class, 'formFor']);                     // The form, filled in
        Route::get('/{budget}/lines/{lineId}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'line']); // One line of a budget, for its page
        Route::put('/{budget}', [BudgetController::class, 'update']);                           // Change, with all its lines
        Route::delete('/{budget}', [BudgetController::class, 'destroy']);                       // Delete a draft
        Route::post('/{budget}/start', [BudgetController::class, 'start']);                     // Draft -> In use
        Route::post('/{budget}/close', [BudgetController::class, 'close']);                     // In use -> Closed
        Route::post('/{budget}/reopen', [BudgetController::class, 'reopen']);                   // Closed -> In use
        Route::get('/{budget}/history', [BudgetController::class, 'history']);                  // History in plain sentences
    });

    // Money in and out against a budget ("Spending") - access checked in the controller
    // Budget Settings: one place's lines (and deductions), for every level
    Route::prefix('budget-settings')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'index']);
        Route::post('/lines', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'storeLine']);
        Route::put('/lines/{line}', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'updateLine']);
        Route::delete('/lines/{line}', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'destroyLine']);
        Route::post('/deductions', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'storeDeduction']);
        Route::put('/deductions/{deduction}', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'updateDeduction']);
        Route::delete('/deductions/{deduction}', [\App\Http\Controllers\Api\BudgetSettingsController::class, 'destroyDeduction']);
    });

    Route::prefix('budget-entries')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\BudgetEntryController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\BudgetEntryController::class, 'store']);
        Route::put('/{entry}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'update']);
        Route::delete('/{entry}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'destroy']);
        Route::post('/{entryId}/restore', [\App\Http\Controllers\Api\BudgetEntryController::class, 'restore']);
        Route::get('/{entryId}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'show']);         // One entry, for its page
        Route::post('/{entry}/receipts', [\App\Http\Controllers\Api\BudgetEntryController::class, 'addReceipt']);       // Attach a receipt
        Route::get('/{entryId}/receipts/{mediaId}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'showReceipt']); // The receipt file
        Route::delete('/{entry}/receipts/{mediaId}', [\App\Http\Controllers\Api\BudgetEntryController::class, 'removeReceipt']); // Take it off
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

        // Rollup summary - Subregion/Region/Diocese, read-only (Phase 5)
        Route::get('/summary/{territory}', [DemographicsController::class, 'summary']);
    });

    // Church entry-mode setting (weekly_and_monthly vs monthly_only)
    Route::prefix('churches/{church}/entry-mode')->group(function () {
        Route::get('/', [DemographicsController::class, 'getEntryMode']);
        Route::put('/', [DemographicsController::class, 'updateEntryMode']);
    });

    // Read-only, derived live from user_territory_assignments - not part of ChurchDemographic
    Route::get('churches/{church}/clergy-summary', [DemographicsController::class, 'clergySummary']);

    // Attendance (Church-level entry - Phase 3 of the Demographics module plan)
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);                                 // List own church's attendance records
        Route::post('/', [AttendanceController::class, 'store']);                                // Record one service/event/gathering
        Route::get('/{attendance}', [AttendanceController::class, 'show']);                      // One record (record page)
        Route::get('/{attendance}/audits', [AttendanceController::class, 'audits']);             // Who recorded it and every change
        Route::put('/{attendance}', [AttendanceController::class, 'update']);                    // Update a record (counts, notes, or move its date)
        Route::delete('/{attendance}', [AttendanceController::class, 'destroy']);                // Delete a record entered by mistake (soft)
        Route::post('/{id}/restore', [AttendanceController::class, 'restore'])->whereNumber('id'); // Undo a delete
    });

    // Attendance Analytics (read-only). PDF/Excel attendance reports go through /reports.
    Route::prefix('attendance-reports')->group(function () {
        Route::get('/analytics', [AttendanceReportController::class, 'analytics']);               // Attendance Analytics page (AttendanceData)
        Route::get('/gathering', [AttendanceReportController::class, 'gathering']);               // One ministry / event over a period (gathering page)
    });

    // Demographics Reports - Spiritual Activities/Monthly Statistics/Growth Analytics pages' stat cards/charts (read-only, computed server-side)
    Route::prefix('demographics-reports')->group(function () {
        Route::get('/widgets', [DemographicsReportController::class, 'widgets']);
    });

    // Reports - PDF/Excel generated in the background (docs/specs/reports-spec.md)
    Route::prefix('reports')->group(function () {
        Route::get('/catalogue', [ReportController::class, 'catalogue']);
        Route::post('/preview', [ReportController::class, 'preview']);
        Route::post('/', [ReportController::class, 'store']);
        Route::get('/runs', [ReportController::class, 'runs']);
        Route::get('/runs/{uuid}', [ReportController::class, 'show']);
        Route::get('/runs/{uuid}/download', [ReportController::class, 'download']);
    });

    // Gathering Categories (global, read-only - Sunday Service/Ministry Gathering/Special Event)
    Route::prefix('gathering-categories')->group(function () {
        Route::get('/', [GatheringCategoryController::class, 'index']);
    });

    // Gathering Types (church-owned config - each church defines its own list, e.g. "Kesha")
    Route::prefix('gathering-types')->group(function () {
        Route::get('/', [GatheringTypeController::class, 'index']);
        Route::post('/', [GatheringTypeController::class, 'store']);
        Route::get('/{gatheringType}', [GatheringTypeController::class, 'show']);
        Route::get('/{gatheringType}/audits', [GatheringTypeController::class, 'getAudits']);
        Route::put('/{gatheringType}', [GatheringTypeController::class, 'update']);
    });

    // Fiscal Year Management
    Route::prefix('fiscal-years')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index']);                      // List all fiscal years
        Route::get('/{id}', [FiscalYearController::class, 'show']);                   // Get fiscal year with quarters/semi-annuals
    });

    // Budget periods - read-only; Demographics tracking uses them to find the fiscal months
    Route::get('/budget-periods', [BudgetPeriodController::class, 'index']);
});
