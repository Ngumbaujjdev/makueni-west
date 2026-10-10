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
use App\Http\Controllers\Api\Chat\ChatController;
use App\Http\Controllers\Api\DemographicsController;
use App\Http\Controllers\Api\DemographicsReportController;
use App\Http\Controllers\Api\Facilities\BookingsController;
use App\Http\Controllers\Api\Facilities\EquipmentController;
use App\Http\Controllers\Api\Facilities\FacilitiesController;
use App\Http\Controllers\Api\Facilities\RepairsController;
use App\Http\Controllers\Api\Facilities\RotaController;
use App\Http\Controllers\Api\Facilities\TeamsController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\GatheringCategoryController;
use App\Http\Controllers\Api\GatheringTypeController;
use App\Http\Controllers\Api\Messages\LogController as MessageLogController;
use App\Http\Controllers\Api\Messages\MessagesController;
use App\Http\Controllers\Api\Messages\TemplatesController as MessageTemplatesController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ModuleGroupController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\People\CareController;
use App\Http\Controllers\Api\People\MinistryController;
use App\Http\Controllers\Api\People\PeopleController;
use App\Http\Controllers\Api\People\VisitorsController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Reports\MonthlyReportsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\Settings\AuditController as SettingsAuditController;
use App\Http\Controllers\Api\Settings\CommunicationController as SettingsCommunicationController;
use App\Http\Controllers\Api\Settings\GalleryController as SettingsGalleryController;
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
// Public: the giving page and Paystack's webhook (docs/specs/accounting-spec.md, A10a).
Route::prefix('give')->group(function () {
    $give = \App\Http\Controllers\Api\Payments\GiveController::class;
    Route::get('callback', [$give, 'callback'])->middleware('throttle:60,1');
    Route::get('status/{reference}', [$give, 'status'])->middleware('throttle:60,1');
    Route::get('claim/{id}', [$give, 'claimStatus'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('{code}/claim', [$give, 'claim'])->middleware('throttle:5,1');
    Route::get('{code}', [$give, 'show'])->middleware('throttle:60,1');
    Route::post('{code}', [$give, 'store'])->middleware('throttle:10,1');
});
Route::post('payments/paystack/webhook', [\App\Http\Controllers\Api\Payments\PaystackWebhookController::class, 'handle'])->middleware('throttle:600,1');
// Public: Safaricom's callbacks for the diocese paybill (docs/specs/accounting-spec.md, A8), and a church's own Daraja app (A10b).
// The callback key in the address is the guard; a wrong key is a 404.
// A church's own paybill through PayHero (A10b) - unsigned, so it is checked with PayHero before anything posts.
Route::post('payments/payhero/{key}', [\App\Http\Controllers\Api\Payments\PayHeroController::class, 'handle'])->middleware('throttle:600,1');
Route::prefix('payments/daraja/{key}')->middleware('throttle:600,1')->group(function () {
    $daraja = \App\Http\Controllers\Api\Payments\DarajaController::class;
    Route::post('validation', [$daraja, 'validation']);
    Route::post('confirmation', [$daraja, 'confirmation']);
    Route::post('stk', [$daraja, 'stk']);
    Route::post('status-result', [$daraja, 'statusResult']);
    Route::post('status-timeout', [$daraja, 'statusTimeout']);
    Route::post('pull', [$daraja, 'pull']);
});

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
// A place's photos and its gallery are public too - they are meant for the church's own page.
Route::get('places/{territory}/photos/{photo}/{size?}', [SettingsGalleryController::class, 'file'])->whereNumber(['territory', 'photo'])->whereIn('size', ['thumb']);
Route::get('places/{territory}/gallery', [SettingsGalleryController::class, 'gallery'])->whereNumber('territory');
// A person's photo is public the same way - it shows beside their name (My Profile).
Route::get('users/{user}/photo', [ProfilePhotoController::class, 'show'])->whereNumber('user');
// An equipment photo (P5 round 2), through the signed link EquipmentPhoto::present gives the item's page.
Route::get('equipment-photos/{photo}/{size?}', [EquipmentController::class, 'photo'])->whereNumber('photo')->whereIn('size', ['thumb'])->middleware('signed')->name('equipment.photo');
// A chat group's photo is public the same way.
Route::get('chat/groups/{id}/photo', [ChatController::class, 'photo'])->whereNumber('id');

Route::middleware(['auth:sanctum'])->group(function () {

    // Events (and initiatives) of church, region and diocese (docs/specs/events-initiatives-spec.md)
    // Chat - contacts, one-to-one chats and groups (docs/specs/messages-spec.md, L6)
    Route::prefix('chat')->group(function () {
        Route::get('realtime', [ChatController::class, 'realtime']);
        Route::get('contacts', [ChatController::class, 'contacts']);
        Route::get('chats', [ChatController::class, 'index']);
        Route::get('chats/{id}', [ChatController::class, 'show'])->whereNumber('id');
        Route::get('chats/{id}/messages', [ChatController::class, 'messages'])->whereNumber('id');
        Route::post('chats/{id}/messages', [ChatController::class, 'send'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('chats/{id}/read', [ChatController::class, 'read'])->whereNumber('id');
        Route::post('direct', [ChatController::class, 'direct']);
        Route::post('groups', [ChatController::class, 'storeGroup'])->middleware('throttle:20,1');
        Route::patch('groups/{id}', [ChatController::class, 'updateGroup'])->whereNumber('id');
        Route::post('groups/{id}/photo', [ChatController::class, 'groupPhoto'])->whereNumber('id');
        Route::post('groups/{id}/members', [ChatController::class, 'addMembers'])->whereNumber('id');
        Route::delete('groups/{id}/members/{user}', [ChatController::class, 'removeMember'])->whereNumber(['id', 'user']);
    });

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
    // Communication > Messages > Message log: every email and SMS sent (the Settings log, for Messages users)
    Route::get('messages/log', [MessageLogController::class, 'index']);
    Route::get('messages/log/{id}', [MessageLogController::class, 'show'])->whereNumber('id');
    Route::post('messages/log/{id}/resend', [MessageLogController::class, 'resend'])->whereNumber('id')->middleware('throttle:10,1');
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
    Route::post('people/bulk', [PeopleController::class, 'bulk']);
    Route::get('people/search', [PeopleController::class, 'search']);
    Route::get('people', [PeopleController::class, 'index']);
    Route::post('people', [PeopleController::class, 'store']);
    Route::get('people/{id}', [PeopleController::class, 'show'])->whereNumber('id');
    Route::put('people/{id}', [PeopleController::class, 'update'])->whereNumber('id');
    Route::get('people/{id}/history', [PeopleController::class, 'history'])->whereNumber('id');
    Route::post('people/{id}/archive', [PeopleController::class, 'archive'])->whereNumber('id');
    Route::post('people/{id}/restore', [PeopleController::class, 'restore'])->whereNumber('id');
    Route::post('people/{id}/anonymise', [PeopleController::class, 'anonymise'])->whereNumber('id');
    Route::post('people/{id}/transfer-out', [PeopleController::class, 'transferOut'])->whereNumber('id');

    // People & care P3 - pastoral care; /care/totals is the region's and diocese's (counts only)
    Route::get('care/options', [CareController::class, 'options']);
    Route::get('care/overview', [CareController::class, 'overview']);
    Route::get('care/hospital', [CareController::class, 'hospital']);
    Route::get('care/prayer', [CareController::class, 'prayer']);
    Route::get('care/totals', [CareController::class, 'totals']);
    Route::post('care/bulk', [CareController::class, 'bulk']);
    Route::get('care', [CareController::class, 'index']);
    Route::post('care', [CareController::class, 'store']);
    Route::get('care/{id}', [CareController::class, 'show'])->whereNumber('id');
    Route::put('care/{id}', [CareController::class, 'update'])->whereNumber('id');
    Route::get('care/{id}/history', [CareController::class, 'history'])->whereNumber('id');
    Route::post('care/{id}/contacts', [CareController::class, 'contact'])->whereNumber('id');
    Route::post('care/{id}/close', [CareController::class, 'close'])->whereNumber('id');
    Route::post('care/{id}/discharge', [CareController::class, 'discharge'])->whereNumber('id');
    Route::get('people/{id}/care', [CareController::class, 'person'])->whereNumber('id');

    // Ministries (P4) - each church's own; a ministry's leader looks after its members. Region/diocese: totals only.
    Route::get('ministries/options', [MinistryController::class, 'options']);
    Route::get('ministries/overview', [MinistryController::class, 'overview']);
    Route::get('ministries/insights', [MinistryController::class, 'insights']);
    Route::get('ministries/totals', [MinistryController::class, 'totals']);
    Route::post('ministries', [MinistryController::class, 'store']);
    Route::get('ministries/{id}', [MinistryController::class, 'show'])->whereNumber('id');
    Route::put('ministries/{id}', [MinistryController::class, 'update'])->whereNumber('id');
    Route::delete('ministries/{id}', [MinistryController::class, 'destroy'])->whereNumber('id');
    Route::put('ministries/{id}/leaders', [MinistryController::class, 'leaders'])->whereNumber('id');
    Route::get('ministries/{id}/members', [MinistryController::class, 'members'])->whereNumber('id');
    Route::post('ministries/{id}/members', [MinistryController::class, 'addMembers'])->whereNumber('id');
    Route::get('ministries/{id}/candidates', [MinistryController::class, 'candidates'])->whereNumber('id');
    Route::post('ministries/{id}/members/remove', [MinistryController::class, 'removeMembers'])->whereNumber('id');
    Route::get('ministries/{id}/gatherings', [MinistryController::class, 'gatherings'])->whereNumber('id');
    Route::get('ministries/{id}/activities', [MinistryController::class, 'activities'])->whereNumber('id');
    Route::get('ministries/{id}/history', [MinistryController::class, 'history'])->whereNumber('id');

    // Facilities (P5) - each church's own rooms, bookings, equipment, repairs and duty rota.
    Route::get('facilities/overview', [FacilitiesController::class, 'overview']);
    Route::get('facilities/options', [FacilitiesController::class, 'options']);
    Route::get('facilities/people', [FacilitiesController::class, 'people']);
    Route::post('rooms', [BookingsController::class, 'saveRoom']);
    Route::put('rooms/{id}', [BookingsController::class, 'saveRoom'])->whereNumber('id');
    Route::delete('rooms/{id}', [BookingsController::class, 'destroyRoom'])->whereNumber('id');
    Route::get('bookings', [BookingsController::class, 'index']);
    Route::get('bookings/check', [BookingsController::class, 'check']);
    Route::post('bookings', [BookingsController::class, 'store']);
    Route::put('bookings/{id}', [BookingsController::class, 'update'])->whereNumber('id');
    Route::delete('bookings/{id}', [BookingsController::class, 'destroy'])->whereNumber('id');
    Route::get('equipment', [EquipmentController::class, 'index']);
    Route::post('equipment', [EquipmentController::class, 'store']);
    Route::post('equipment/bulk', [EquipmentController::class, 'bulk']);
    Route::get('equipment/expenses', [EquipmentController::class, 'expenses']);
    Route::get('facilities/assets', [FacilitiesController::class, 'assets']);
    Route::post('equipment/{id}/photos', [EquipmentController::class, 'addPhotos'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('equipment/{id}/photos/order', [EquipmentController::class, 'orderPhotos'])->whereNumber('id');
    Route::delete('equipment/{id}/photos/{photo}', [EquipmentController::class, 'removePhoto'])->whereNumber(['id', 'photo']);
    Route::post('equipment/{id}/receipts', [EquipmentController::class, 'addReceipt'])->whereNumber('id');
    Route::get('equipment/{id}/receipts/{media}', [EquipmentController::class, 'showReceipt'])->whereNumber(['id', 'media']);
    Route::delete('equipment/{id}/receipts/{media}', [EquipmentController::class, 'removeReceipt'])->whereNumber(['id', 'media']);
    Route::post('equipment/{id}/expense', [EquipmentController::class, 'linkExpense'])->whereNumber('id');
    Route::delete('equipment/{id}/expense', [EquipmentController::class, 'unlinkExpense'])->whereNumber('id');
    Route::get('equipment/{id}', [EquipmentController::class, 'show'])->whereNumber('id');
    Route::put('equipment/{id}', [EquipmentController::class, 'update'])->whereNumber('id');
    Route::delete('equipment/{id}', [EquipmentController::class, 'destroy'])->whereNumber('id');
    Route::post('equipment/{id}/loans', [EquipmentController::class, 'lend'])->whereNumber('id');
    Route::get('loans', [EquipmentController::class, 'loans']);
    Route::post('loans/{id}/return', [EquipmentController::class, 'giveBack'])->whereNumber('id');
    Route::post('loans/{id}/approve', [EquipmentController::class, 'approve'])->whereNumber('id');
    Route::post('loans/{id}/decline', [EquipmentController::class, 'decline'])->whereNumber('id');
    Route::delete('loans/{id}', [EquipmentController::class, 'cancel'])->whereNumber('id');
    Route::get('repairs', [RepairsController::class, 'index']);
    Route::post('repairs', [RepairsController::class, 'store']);
    Route::put('repairs/{id}', [RepairsController::class, 'update'])->whereNumber('id');
    Route::post('repairs/{id}/expense', [RepairsController::class, 'expense'])->whereNumber('id');
    Route::get('rota', [RotaController::class, 'index']);
    Route::put('rota', [RotaController::class, 'update']);
    Route::post('rota/copy', [RotaController::class, 'copy']);
    Route::post('rota/fill', [RotaController::class, 'fill']);
    Route::get('facilities/teams', [TeamsController::class, 'index']);
    Route::get('facilities/teams/person/{person}', [TeamsController::class, 'person'])->whereNumber('person');
    Route::post('facilities/teams/{duty}/members', [TeamsController::class, 'store']);
    Route::put('facilities/teams/{duty}/order', [TeamsController::class, 'order']);
    Route::delete('facilities/teams/members/{member}', [TeamsController::class, 'destroy'])->whereNumber('member');

    // People & care P2 - visitors and their follow-up; /visitors/totals is the
    // region's and diocese's (counts only)
    Route::get('visitors/options', [VisitorsController::class, 'options']);
    Route::get('visitors/overview', [VisitorsController::class, 'overview']);
    Route::get('visitors/check', [VisitorsController::class, 'check']);
    Route::get('visitors/insights', [VisitorsController::class, 'insights']);
    Route::get('visitors/totals', [VisitorsController::class, 'totals']);
    Route::post('visitors/batch', [VisitorsController::class, 'batch']);
    Route::post('visitors/bulk', [VisitorsController::class, 'bulk']);
    Route::get('visitors', [VisitorsController::class, 'index']);
    Route::get('visitors/{id}', [VisitorsController::class, 'show'])->whereNumber('id');
    Route::put('visitors/{id}', [VisitorsController::class, 'update'])->whereNumber('id');
    Route::get('visitors/{id}/history', [VisitorsController::class, 'history'])->whereNumber('id');
    Route::post('visitors/{id}/stage', [VisitorsController::class, 'stage'])->whereNumber('id');
    Route::post('visitors/{id}/assign', [VisitorsController::class, 'assign'])->whereNumber('id');
    Route::post('visitors/{id}/visits', [VisitorsController::class, 'visit'])->whereNumber('id');
    Route::post('visitors/{id}/followups', [VisitorsController::class, 'followup'])->whereNumber('id');
    Route::post('visitors/{id}/sms', [VisitorsController::class, 'sms'])->whereNumber('id');
    Route::post('visitors/{id}/become-member', [VisitorsController::class, 'becomeMember'])->whereNumber('id');
    Route::post('visitors/{id}/archive', [VisitorsController::class, 'archive'])->whereNumber('id');
    Route::post('visitors/{id}/restore', [VisitorsController::class, 'restore'])->whereNumber('id');
    Route::post('visitors/{id}/anonymise', [VisitorsController::class, 'anonymise'])->whereNumber('id');

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
        Route::get('view', [\App\Http\Controllers\Api\Settings\ProfileViewController::class, 'show']);
        Route::get('profile', [SettingsProfileController::class, 'show']);
        Route::put('profile', [SettingsProfileController::class, 'update']);
        Route::post('profile/logo', [SettingsProfileController::class, 'uploadLogo']);
        Route::delete('profile/logo', [SettingsProfileController::class, 'removeLogo']);
        Route::get('profile/photos', [SettingsGalleryController::class, 'index']);
        Route::post('profile/photos', [SettingsGalleryController::class, 'store'])->middleware('throttle:20,1');
        Route::post('profile/photos/order', [SettingsGalleryController::class, 'order']);
        Route::patch('profile/photos/{id}', [SettingsGalleryController::class, 'update'])->whereNumber('id');
        Route::delete('profile/photos/{id}', [SettingsGalleryController::class, 'destroy'])->whereNumber('id');
        Route::get('service-times', [SettingsServiceTimesController::class, 'show']);
        Route::put('service-times', [SettingsServiceTimesController::class, 'update']);
        Route::get('facilities-setup', [\App\Http\Controllers\Api\Settings\FacilitiesSetupController::class, 'show']);
        Route::put('facilities-setup', [\App\Http\Controllers\Api\Settings\FacilitiesSetupController::class, 'update']);
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
        Route::post('profile/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('profile/photo', [ProfilePhotoController::class, 'destroy']);
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

    // Accounting (docs/specs/accounting-spec.md) - the real money of every church, region and diocese:
    // one engine for every level; the place is the acting role's (or one below, read-only, ?territory_id=).
    Route::prefix('accounting')->group(function () {
        $books = \App\Http\Controllers\Api\Accounting\BooksController::class;
        $pvs = \App\Http\Controllers\Api\Accounting\PaymentVoucherController::class;
        Route::get('overview', [$books, 'overview']);
        Route::get('places', [$books, 'places']);
        Route::get('options', [$books, 'options']);
        Route::get('trail/{type}/{id}', [\App\Http\Controllers\Api\Accounting\TrailController::class, 'show'])->whereIn('type', ['voucher', 'requisition', 'payroll', 'order', 'remittance', 'collection'])->whereNumber('id');
        Route::get('accounts', [$books, 'accounts']);
        Route::get('accounts/{id}', [$books, 'account'])->whereNumber('id');
        Route::post('accounts', [$books, 'storeAccount']);
        Route::put('accounts/{id}', [$books, 'updateAccount'])->whereNumber('id');
        Route::post('chart', [$books, 'storeChart']);
        Route::put('chart/{id}', [$books, 'updateChart'])->whereNumber('id');
        Route::get('cashbook', [$books, 'cashbook']);
        Route::get('trial-balance', [$books, 'trialBalance']);
        Route::get('journals', [$books, 'journals']);
        Route::get('journals/{id}', [$books, 'show'])->whereNumber('id');
        Route::post('journals/{id}/reverse', [$books, 'reverse'])->whereNumber('id');
        Route::post('journals/{id}/attachments', [$books, 'addAttachment'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('journals/{id}/attachments/{media}', [$books, 'showAttachment'])->whereNumber(['id', 'media']);
        Route::delete('journals/{id}/attachments/{media}', [$books, 'removeAttachment'])->whereNumber(['id', 'media']);
        Route::post('receipts', [$books, 'receipt']);
        Route::post('transfers', [$books, 'transfer']);
        Route::post('journal-vouchers', [$books, 'journalVoucher']);
        Route::get('payment-vouchers', [$pvs, 'index']);
        Route::post('payment-vouchers', [$pvs, 'store']);
        Route::get('payment-vouchers/{id}', [$pvs, 'show'])->whereNumber('id');
        Route::put('payment-vouchers/{id}', [$pvs, 'update'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/authorise', [$pvs, 'authorise'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/reject', [$pvs, 'reject'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/pay', [$pvs, 'pay'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/reverse', [$pvs, 'reversePayment'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/cancel', [$pvs, 'cancel'])->whereNumber('id');
        Route::post('payment-vouchers/{id}/attachments', [$pvs, 'addAttachment'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('payment-vouchers/{id}/attachments/{media}', [$pvs, 'showAttachment'])->whereNumber(['id', 'media']);
        Route::delete('payment-vouchers/{id}/attachments/{media}', [$pvs, 'removeAttachment'])->whereNumber(['id', 'media']);
        // A2 - proving the books right: counts, reconciliations, petty cash, the board, month-end close.
        $rec = \App\Http\Controllers\Api\Accounting\ReconciliationController::class;
        $per = \App\Http\Controllers\Api\Accounting\PeriodController::class;
        Route::get('reconciliation', [$rec, 'index']);
        Route::get('reconciliation-board', [$rec, 'board']);
        Route::post('cash-counts', [$rec, 'count']);
        Route::post('cash-counts/{id}/approve', [$rec, 'approveCount'])->whereNumber('id');
        Route::post('cash-counts/{id}/reject', [$rec, 'rejectCount'])->whereNumber('id');
        Route::post('reconciliations', [$rec, 'start']);
        Route::get('reconciliations/{id}', [$rec, 'show'])->whereNumber('id');
        Route::put('reconciliations/{id}', [$rec, 'update'])->whereNumber('id');
        Route::delete('reconciliations/{id}', [$rec, 'discard'])->whereNumber('id');
        Route::post('reconciliations/{id}/tick', [$rec, 'tick'])->whereNumber('id');
        Route::post('reconciliations/{id}/statement', [$rec, 'import'])->whereNumber('id');
        Route::post('reconciliations/{id}/statement/{line}/{action}', [$rec, 'statementLine'])->whereNumber(['id', 'line'])->whereIn('action', ['match', 'unmatch', 'ignore', 'add']);
        Route::post('reconciliations/{id}/submit', [$rec, 'submit'])->whereNumber('id');
        Route::post('reconciliations/{id}/approve', [$rec, 'approve'])->whereNumber('id');
        Route::post('reconciliations/{id}/return', [$rec, 'sendBack'])->whereNumber('id');
        Route::get('petty-cash', [$rec, 'petty']);
        Route::put('petty-cash', [$rec, 'setFloat']);
        Route::post('petty-cash/spend', [$rec, 'spend']);
        Route::post('petty-cash/top-up', [$rec, 'topUp']);
        Route::get('periods', [$per, 'index']);
        Route::post('periods/close', [$per, 'close']);
        Route::post('periods/reopen', [$per, 'reopen']);
        // A3 - Sunday collections (churches).
        $col = \App\Http\Controllers\Api\Accounting\CollectionController::class;
        Route::get('collections', [$col, 'index']);
        Route::get('collections/options', [$col, 'options']);
        Route::post('collections', [$col, 'store']);
        Route::get('collections/{id}', [$col, 'show'])->whereNumber('id');
        Route::put('collections/{id}', [$col, 'update'])->whereNumber('id');
        Route::delete('collections/{id}', [$col, 'destroy'])->whereNumber('id');
        Route::post('collections/{id}/confirm', [$col, 'confirm'])->whereNumber('id');
        Route::post('collections/{id}/return', [$col, 'sendBack'])->whereNumber('id');
        Route::post('collections/{id}/bank', [$col, 'bank'])->whereNumber('id');
        Route::post('collections/{id}/reverse', [$col, 'reverse'])->whereNumber('id');
        // A4 - requisitions and staff advances.
        $req = \App\Http\Controllers\Api\Accounting\RequisitionController::class;
        Route::get('requisitions', [$req, 'index']);
        Route::get('requisitions/options', [$req, 'options']);
        Route::post('requisitions', [$req, 'store']);
        Route::get('requisitions/{id}', [$req, 'show'])->whereNumber('id');
        Route::put('requisitions/{id}', [$req, 'update'])->whereNumber('id');
        Route::post('requisitions/{id}/cancel', [$req, 'cancel'])->whereNumber('id');
        Route::post('requisitions/{id}/pay', [$req, 'pay'])->whereNumber('id');
        Route::post('requisitions/{id}/{decision}', [$req, 'decide'])->whereNumber('id')->whereIn('decision', ['approve', 'reject', 'return']);
        Route::post('requisitions/{id}/attachments', [$req, 'addAttachment'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('requisitions/{id}/attachments/{media}', [$req, 'showAttachment'])->whereNumber(['id', 'media']);
        Route::delete('requisitions/{id}/attachments/{media}', [$req, 'removeAttachment'])->whereNumber(['id', 'media']);
        Route::post('advances/{id}/retire', [$req, 'retire'])->whereNumber('id');
        // A5 - procurement: suppliers, quotations, the order, goods received, the bill.
        $pro = \App\Http\Controllers\Api\Accounting\ProcurementController::class;
        Route::get('procurement', [$pro, 'index']);
        Route::get('procurement/options', [$pro, 'options']);
        Route::get('procurement/suppliers', [$pro, 'suppliersIndex']);
        Route::post('procurement/suppliers', [$pro, 'saveSupplier']);
        Route::put('procurement/suppliers/{id}', [$pro, 'saveSupplier'])->whereNumber('id');
        Route::delete('procurement/suppliers/{id}', [$pro, 'removeSupplier'])->whereNumber('id');
        Route::post('requisitions/{id}/quotes', [$pro, 'addQuote'])->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('requisitions/{id}/quotes/{quote}', [$pro, 'removeQuote'])->whereNumber(['id', 'quote']);
        Route::post('requisitions/{id}/quotes/{quote}/choose', [$pro, 'chooseQuote'])->whereNumber(['id', 'quote']);
        Route::get('requisitions/{id}/quotes/{quote}/file', [$pro, 'quoteFile'])->whereNumber(['id', 'quote']);
        Route::post('requisitions/{id}/order', [$pro, 'raise'])->whereNumber('id');
        Route::get('procurement/orders/{id}', [$pro, 'showOrder'])->whereNumber('id');
        Route::post('procurement/orders/{id}/receive', [$pro, 'receive'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('procurement/orders/{id}/bill', [$pro, 'bill'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('procurement/orders/{id}/close', [$pro, 'close'])->whereNumber('id');
        Route::post('procurement/orders/{id}/cancel', [$pro, 'cancel'])->whereNumber('id');
        Route::post('procurement/deliveries/{id}/undo', [$pro, 'undoDelivery'])->whereNumber('id');
        Route::get('procurement/deliveries/{id}/files/{media}', [$pro, 'deliveryFile'])->whereNumber(['id', 'media']);
        Route::post('procurement/bills/{id}/pay', [$pro, 'payBill'])->whereNumber('id');
        Route::post('procurement/bills/{id}/reverse', [$pro, 'reverseBill'])->whereNumber('id');
        Route::get('procurement/bills/{id}/file', [$pro, 'billFile'])->whereNumber('id');
        // A6 - remittances between levels.
        $rem = \App\Http\Controllers\Api\Accounting\RemittanceController::class;
        Route::get('remittances', [$rem, 'index']);
        Route::get('remittances/options', [$rem, 'options']);
        Route::get('remittances/board', [$rem, 'board']);
        Route::get('remittances/statement', [$rem, 'statement']);
        Route::post('remittances', [$rem, 'store']);
        Route::get('remittances/{id}', [$rem, 'show'])->whereNumber('id');
        Route::post('remittances/{id}/confirm', [$rem, 'confirm'])->whereNumber('id');
        Route::post('remittances/{id}/mpesa', [$rem, 'payMpesa'])->whereNumber('id');
        Route::post('remittances/{id}/unconfirm', [$rem, 'unconfirm'])->whereNumber('id');
        Route::post('remittances/{id}/query', [$rem, 'query'])->whereNumber('id');
        Route::post('remittances/{id}/answer', [$rem, 'answer'])->whereNumber('id');
        // A8 - the diocese paybill.
        $pb = \App\Http\Controllers\Api\Accounting\PaybillController::class;
        Route::get('paybill', [$pb, 'index']);
        Route::post('paybill/payments/{id}/sort', [$pb, 'sort'])->whereNumber('id');
        Route::post('paybill/ask', [$pb, 'ask'])->middleware('throttle:20,1');
        Route::get('paybill/requests/{id}', [$pb, 'request'])->whereNumber('id');
        Route::get('paybill/settlements', [$pb, 'settlements']);
        Route::post('paybill/settlements', [$pb, 'settle']);
        Route::post('paybill/settlements/{id}/cancel', [$pb, 'cancelSettlement'])->whereNumber('id');
        Route::post('paybill/setup/register', [$pb, 'register']);
        Route::post('paybill/setup/simulate', [$pb, 'simulate'])->middleware('throttle:10,1');
        // A10a - online giving: the place's gifts; the diocese's gateways.
        $gv = \App\Http\Controllers\Api\Accounting\GivingController::class;
        Route::get('giving', [$gv, 'index']);
        // A10c - getting paid: payouts, a place asking for its own Paystack, the diocese's check.
        $po = \App\Http\Controllers\Api\Accounting\PayoutController::class;
        // A10e - transactions: every attempt to pay, failed ones included.
        $tx = \App\Http\Controllers\Api\Accounting\TransactionController::class;
        Route::get('transactions', [$tx, 'index']);
        Route::post('transactions/check-waiting', [$tx, 'checkWaiting']);
        Route::post('transactions/check-code', [$tx, 'checkCode']);
        Route::get('transactions/{source}/{id}', [$tx, 'show'])->whereIn('source', ['gift', 'prompt', 'paybill', 'claim'])->whereNumber('id');
        Route::post('transactions/{source}/{id}/check', [$tx, 'check'])->whereIn('source', ['gift', 'prompt', 'paybill', 'claim'])->whereNumber('id');
        Route::get('giving/payouts', [$po, 'index']);
        Route::get('giving/payouts/{id}', [$po, 'show'])->whereNumber('id');
        Route::get('giving/payout-options', [$po, 'options']);
        Route::post('giving/payout-request', [$po, 'ask']);
        Route::delete('giving/payout-request', [$po, 'withdraw']);
        Route::get('gateways/payouts', [$po, 'overview']);
        Route::post('gateways/channels/{id}/review', [$po, 'review'])->whereNumber('id');
        Route::get('gateways', [$gv, 'gateways']);
        Route::get('gateways/banks', [$gv, 'banks']);
        Route::post('gateways/channels', [$gv, 'storeChannel']);
        Route::put('gateways/channels/{id}', [$gv, 'updateChannel'])->whereNumber('id');
        Route::post('gateways/channels/{id}/register', [$gv, 'registerChannel'])->whereNumber('id');
        // A7 - payroll.
        $pay = \App\Http\Controllers\Api\Accounting\PayrollController::class;
        Route::get('payroll', [$pay, 'index']);
        Route::post('payroll/employees', [$pay, 'saveEmployee']);
        Route::put('payroll/employees/{id}', [$pay, 'saveEmployee'])->whereNumber('id');
        Route::post('payroll/runs', [$pay, 'start']);
        Route::get('payroll/runs/{id}', [$pay, 'show'])->whereNumber('id');
        Route::put('payroll/runs/{id}/payslips/{slip}', [$pay, 'updateSlip'])->whereNumber(['id', 'slip']);
        Route::post('payroll/runs/{id}/payslips', [$pay, 'addSlip'])->whereNumber('id');
        Route::delete('payroll/runs/{id}/payslips/{slip}', [$pay, 'removeSlip'])->whereNumber(['id', 'slip']);
        Route::post('payroll/runs/{id}/pay', [$pay, 'pay'])->whereNumber('id');
        Route::put('payroll/runs/{id}/references', [$pay, 'references'])->whereNumber('id');
        Route::post('payroll/runs/{id}/{act}', [$pay, 'act'])->whereNumber('id')->whereIn('act', ['recalculate', 'submit', 'cancel']);
        Route::post('payroll/runs/{id}/{decision}', [$pay, 'decide'])->whereNumber('id')->whereIn('decision', ['approve', 'reject', 'return']);
    });

    // Approvals (docs/specs/accounting-spec.md, A4) - my inbox, decisions, delegations, the rules.
    Route::prefix('approvals')->group(function () {
        $ap = \App\Http\Controllers\Api\Accounting\ApprovalController::class;
        Route::get('/', [$ap, 'index']);
        Route::get('board', [$ap, 'board']);
        Route::get('requests/{id}', [$ap, 'show'])->whereNumber('id');
        Route::post('requests/{id}/cancel', [$ap, 'cancel'])->whereNumber('id');
        Route::post('requests/{id}/retry', [$ap, 'retry'])->whereNumber('id');
        Route::post('requests/{id}/{decision}', [$ap, 'decide'])->whereNumber('id')->whereIn('decision', ['approve', 'reject', 'return']);
        Route::get('requests/{id}/files/{media}', [$ap, 'file'])->whereNumber(['id', 'media']);
        Route::get('delegations', [$ap, 'delegations']);
        Route::post('delegations', [$ap, 'delegate']);
        Route::delete('delegations/{id}', [$ap, 'undelegate'])->whereNumber('id');
        Route::get('workflows', [$ap, 'workflows']);
        Route::get('people', [$ap, 'people']);
        Route::post('workflows', [$ap, 'saveWorkflow']);
        Route::put('workflows/{id}', [$ap, 'saveWorkflow'])->whereNumber('id');
        Route::delete('workflows/{id}', [$ap, 'deleteWorkflow'])->whereNumber('id');
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
