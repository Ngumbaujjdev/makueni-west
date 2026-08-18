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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
        ]);
    }
}
