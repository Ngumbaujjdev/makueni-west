<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One Settings hub service per request, so its per-request row cache is shared.
        $this->app->singleton(\App\Services\Settings\Settings::class);
        // The chart of accounts checks itself once per request.
        $this->app->singleton(\App\Services\Accounting\Chart::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A church's own mail server must be a real, public one (Settings > Communication, S6b).
        \Illuminate\Support\Facades\Validator::extend('public_mail_host', function ($attribute, $value, $parameters, $validator) {
            if ($value === null || $value === '') {
                return true;
            }
            $problem = \App\Services\Messaging\PlaceMessenger::hostProblem((string) $value);
            if ($problem) {
                $validator->setCustomMessages([$attribute.'.public_mail_host' => $problem]);
            }

            return $problem === null;
        });

        // Register morph map for polymorphic relationships
        Relation::enforceMorphMap([
            // Territory models
            'diocese' => 'App\Models\Diocese',
            'region' => 'App\Models\Region',
            'subregion' => 'App\Models\Subregion',
            'church' => 'App\Models\Church',
            'territory' => 'App\Models\Territory',
            'user_territory_assignment' => 'App\Models\UserTerritoryAssignment',

            // Module models
            'module' => 'App\Models\Module',
            'module_group' => 'App\Models\ModuleGroup',
            'submodule' => 'App\Models\Submodule',
            'subsubmodule' => 'App\Models\SubSubmodule',

            // User & Auth models
            'user' => 'App\Models\User',
            'permission' => 'App\Models\Permission',
            'role' => 'App\Models\Role',
            'super_admin_config' => 'App\Models\SuperAdminConfig',
            'impersonation_session' => 'App\Models\ImpersonationSession',

            // Budget models
            'budget' => 'App\Models\Budget',
            'budget_type' => 'App\Models\BudgetType',
            'budget_category' => 'App\Models\BudgetCategory',
            'budget_line' => 'App\Models\BudgetLine',
            'budget_item' => 'App\Models\BudgetLineItem',
            'budget_log' => 'App\Models\BudgetLog',
            'budget_deduction' => 'App\Models\BudgetDeduction',
            'budget_deduction_item' => 'App\Models\BudgetDeductionItem',
            'budget_period' => 'App\Models\BudgetPeriod',
            'budget_entry' => 'App\Models\BudgetEntry',

            // Accounting models (docs/specs/accounting-spec.md)
            'accounting_account' => 'App\Models\AccountingAccount',
            'accounting_fund' => 'App\Models\AccountingFund',
            'journal' => 'App\Models\Journal',
            'journal_line' => 'App\Models\JournalLine',
            'payment_voucher' => 'App\Models\PaymentVoucher',
            'payment_voucher_line' => 'App\Models\PaymentVoucherLine',
            'accounting_period' => 'App\Models\AccountingPeriod',
            'accounting_place_account' => 'App\Models\AccountingPlaceAccount',
            'cash_count' => 'App\Models\CashCount',
            'bank_reconciliation' => 'App\Models\BankReconciliation',
            'bank_statement_line' => 'App\Models\BankStatementLine',
            'collection' => 'App\Models\Collection',
            'collection_line' => 'App\Models\CollectionLine',
            'requisition' => 'App\Models\Requisition',
            'staff_advance' => 'App\Models\StaffAdvance',
            'advance_retirement' => 'App\Models\AdvanceRetirement',
            'approval_workflow' => 'App\Models\ApprovalWorkflow',
            'approval_request' => 'App\Models\ApprovalRequest',
            'approval_assignment' => 'App\Models\ApprovalAssignment',
            'approval_delegation' => 'App\Models\ApprovalDelegation',
            'supplier' => 'App\Models\Supplier',
            'quotation' => 'App\Models\Quotation',
            'purchase_order' => 'App\Models\PurchaseOrder',
            'goods_received' => 'App\Models\GoodsReceived',
            'supplier_invoice' => 'App\Models\SupplierInvoice',

            // Fiscal period models
            'fiscal_year' => 'App\Models\FiscalYear',
            'fiscal_month' => 'App\Models\FiscalMonth',
            'fiscal_quarter' => 'App\Models\FiscalQuarter',
            'fiscal_semi_annual' => 'App\Models\FiscalSemiAnnual',

            // Status models
            'status' => 'App\Models\Status',
            'status_category' => 'App\Models\StatusCategory',

            // Demographics models
            'church_demographic' => 'App\Models\ChurchDemographic',
            'church_attendance_record' => 'App\Models\ChurchAttendanceRecord',
            'gathering_category' => 'App\Models\GatheringCategory',
            'gathering_type' => 'App\Models\GatheringType',

            // Support
            'support_ticket' => 'App\Models\SupportTicket',

            // Settings hub - system-level setting changes are audited on "setting" 0
            'setting' => 'App\Models\Setting',
            'calendar_event' => 'App\Models\CalendarEvent',
            'activity' => 'App\Models\Activity',
            'activity_registration' => 'App\Models\ActivityRegistration',
            'activity_session' => 'App\Models\ActivitySession',
            'monthly_report' => 'App\Models\MonthlyReport',
            'message_batch' => 'App\Models\MessageBatch',
            // People & care (docs/specs/people-and-care-spec.md)
            'person' => 'App\Models\Person',
            'person_transfer' => 'App\Models\PersonTransfer',
            'visitor_visit' => 'App\Models\VisitorVisit',
            'visitor_followup' => 'App\Models\VisitorFollowup',
            'care_record' => 'App\Models\CareRecord',
            'care_contact' => 'App\Models\CareContact',
            'ministry' => 'App\Models\Ministry',
            'ministry_leader' => 'App\Models\MinistryLeader',
            'ministry_member' => 'App\Models\MinistryMember',
            'room' => 'App\Models\Room',
            'room_booking' => 'App\Models\RoomBooking',
            'equipment' => 'App\Models\Equipment',
            'equipment_loan' => 'App\Models\EquipmentLoan',
            'maintenance_job' => 'App\Models\MaintenanceJob',
            'duty_rota' => 'App\Models\DutyRota',
            'duty_team_member' => 'App\Models\DutyTeamMember',
        ]);

        // Saved system settings (email server, ...) take over from .env -
        // Settings > Email. Never let a missing table or database stop boot
        // (migrations, composer install, config:cache).
        try {
            $settings = $this->app->make(\App\Services\Settings\Settings::class);
            $settings->applyToConfig();
            \Illuminate\Support\Facades\Queue::before(fn () => $settings->applyIfStale());
        } catch (\Throwable) {
            // .env applies
        }
    }
}
