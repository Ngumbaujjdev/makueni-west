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
            // PHASE 3: Module Groups & Roles
            // ==========================================
            ModuleGroupSeeder::class,
            AdministrationModuleGroupSeeder::class,
            RoleSeeder::class,

            // ==========================================
            // PHASE 4: System Seeders (Modules per level)
            // ==========================================
            // Must run BEFORE the role-permission seeders below — they assign
            // permissions FOR these modules, and previously ran first (Phase 3),
            // so they found 0 modules and silently assigned nothing. Confirmed on
            // the dev DB: Bishop/Subregional Overseer had 0 permissions, Regional
            // Overseer had 2 (not the intended "full access to all region
            // modules") — this ordering bug was live, not just theoretical.
            DioceseSystemSeeder::class,
            RegionSystemSeeder::class,
            ChurchSystemSeeder::class,

            // ==========================================
            // PHASE 4.5: Role Permissions (must run after Phase 4's modules exist)
            // ==========================================
            DioceseRolePermissionsSeeder::class,
            RegionRolePermissionsSeeder::class,
            ChurchRolePermissionsSeeder::class,
            AdditionalRolePermissionsSeeder::class,
            GlobalAdministratorPermissionsSeeder::class,

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

            // ==========================================
            // PHASE 11: Demographics Focus Mode (temporary - see seeder docblock)
            // ==========================================
            MuteNonDemographicsChurchModulesSeeder::class,

            // ==========================================
            // PHASE 12: Demographics Permission Scaffolding Fixes
            // ==========================================
            FixDemographicsPermissionScaffoldingSeeder::class,

            // ==========================================
            // PHASE 13: Subregion Demographics Review (built from scratch)
            // ==========================================
            AddSubregionDemographicsReviewSeeder::class,

            // ==========================================
            // PHASE 14: Region Demographics Summary (built from scratch)
            // ==========================================
            AddRegionDemographicsSummarySeeder::class,

            // ==========================================
            // PHASE 15: Real Historical Demographics (2024-2025)
            // ==========================================
            SeedRealHistoricalDemographicsSeeder::class,
        ]);
    }
}
