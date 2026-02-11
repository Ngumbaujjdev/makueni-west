<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ==========================================
            // PHASE 1: Territory/Organization Structure
            // ==========================================
            DioceseSeeder::class,
            RegionsSeeder::class,
            SubregionsSeeder::class,
            ChurchesSeeder::class,

            // ==========================================
            // PHASE 2: Users & Super Admin
            // ==========================================
            SuperAdminSeeder::class,
            SampleUsersSeeder::class,

            // ==========================================
            // PHASE 3: Modules & Permissions
            // ==========================================
            ModuleGroupSeeder::class,
            AdministrationModuleGroupSeeder::class,
            RoleSeeder::class,
            DioceseRolePermissionsSeeder::class,
            RegionRolePermissionsSeeder::class,
            GlobalAdministratorPermissionsSeeder::class,

            // ==========================================
            // PHASE 4: System Seeders (Modules per level)
            // ==========================================
            DioceseSystemSeeder::class,
            RegionSystemSeeder::class,
            ChurchSystemSeeder::class,

            // ==========================================
            // PHASE 5: Dashboard Overview Seeders
            // ==========================================
            AddDioceseDashboardOverviewSeeder::class,
            AddRegionDashboardOverviewSeeder::class,
            AddChurchDashboardOverviewSeeder::class,

            // ==========================================
            // PHASE 6: Leadership Seeders
            // ==========================================
            DioceseLeadershipSeeder::class,
            RegionalLeadershipSeeder::class,

            // ==========================================
            // PHASE 7: Status System
            // ==========================================
            StatusCategorySeeder::class,
            StatusSeeder::class,

            // ==========================================
            // PHASE 8: Budget Infrastructure
            // ==========================================
            BudgetTypeSeeder::class,
            BudgetCategorySeeder::class,
            BudgetLineSeeder::class,
            BudgetDeductionSeeder::class,

            // ==========================================
            // PHASE 9: Fiscal Periods
            // ==========================================
            FiscalMonthSeeder::class,
            FiscalYearSeeder::class,
            BudgetPeriodSeeder::class,

            // ==========================================
            // PHASE 10: Budgets (Last - depends on all above)
            // ==========================================
            BudgetSeeder::class,
        ]);
    }
}
