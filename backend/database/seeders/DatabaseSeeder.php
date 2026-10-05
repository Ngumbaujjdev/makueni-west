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
            // SampleUsersSeeder retired (see docs/ROADMAP.md -> Seeder bug
            // fixes): its users are fully superseded by DioceseLeadershipSeeder
            // + RegionalLeadershipSeeder, and firstOrCreate()'ing a Bishop at
            // the same email as DioceseLeadershipSeeder - while running first
            // in this list - was the source of the Bishop identity bug fixed
            // in Phase 18 below. The seeder file itself is left in place for
            // history but is no longer called.
            SuperAdminSeeder::class,

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
            // PHASE 4.6: Module Group Backfill (must run after Phase 4 — the
            // three SystemSeeders above never set module_group_id, which
            // silently makes a module invisible in the sidebar regardless of
            // permissions; see the seeder's own docblock)
            // ==========================================
            BackfillModuleGroupAssignmentsSeeder::class,

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

            // ==========================================
            // PHASE 16: Territory Data Gap Fixes (Kikumini, Kavuthu,
            // Ndutoni, duplicate CCI MAKINDU)
            // ==========================================
            FixTerritoryDataGapsSeeder::class,

            // ==========================================
            // PHASE 17: Fix & Seed Real Church Leadership (pastors, associates, spouses)
            // ==========================================
            FixAndSeedRealChurchLeadershipSeeder::class,

            // ==========================================
            // PHASE 18: Fix Bishop Identity & Retire Sample Accounts
            // ==========================================
            FixBishopIdentityAndRetireSampleAccountsSeeder::class,

            // ==========================================
            // PHASE 19: Subregional Leadership (test account - every
            // territory level now has a documented, working test login)
            // ==========================================
            SubregionalLeadershipSeeder::class,

            // ==========================================
            // PHASE 20: Grant Demographics Entry Permissions (Senior Pastor,
            // Associate Pastor, Church Secretary, Church Administrator)
            // ==========================================
            GrantDemographicsEntryPermissionsSeeder::class,

            // ==========================================
            // PHASE 21: Fix Demographics Module Naming & Ordering
            // ==========================================
            FixDemographicsModuleNamingAndOrderingSeeder::class,

            // ==========================================
            // PHASE 22: Fix Demographics Submodule Path Slashes (sidebar
            // links were concatenating without a separator)
            // ==========================================
            FixDemographicsSubmodulePathSlashesSeeder::class,

            // ==========================================
            // PHASE 23: Configurable Attendance Gathering Types (church-owned
            // gathering types under the global Sunday Service/Ministry
            // Gathering/Special Event categories, replacing the old
            // service_type enum) - see gathering-types-config plan.
            // Categories themselves are seeded directly in their migration,
            // not here, so the church_attendance_records backfill migration
            // can rely on them existing regardless of seeder order.
            // ==========================================
            AddGatheringTypesSubmoduleSeeder::class,
            GatheringTypeStarterSeeder::class,

            // ==========================================
            // PHASE 24: Relocate Gathering Types config to Church Settings
            // (one-time cleanup for databases that ran the first version of
            // AddGatheringTypesSubmoduleSeeder, which nested it under
            // Attendance instead of the church-level Settings module group)
            // ==========================================
            RelocateGatheringTypesToChurchSettingsSeeder::class,

            // ==========================================
            // PHASE 25: Demographics Settings > Recording Cadence submodule
            // (moved off the Growth Overview dashboard onto its own Settings
            // page, mirroring Attendance Settings > Gathering Types)
            // ==========================================
            AddDemographicsSettingsSubmoduleSeeder::class,

            // ==========================================
            // PHASE 26: Role Management / Permissions / Modules / Module
            // Groups pages on the System Administration menu (built but
            // never seeded), each behind its own admin-only permission
            // ==========================================
            AddSystemAdministrationRolePagesSeeder::class,

            // ==========================================
            // PHASE 27: Budgets for church, region and diocese - the Finance
            // → Budgets and Settings → Budget Settings menus, the
            // {level}.budgets.* permissions and who holds them, and the old
            // budget permission names retired (docs/specs/budgets-spec.md).
            // Last, so it builds on every menu group and role above.
            // ==========================================
            BudgetsAccessSeeder::class,

            // ==========================================
            // PHASE 28: the standard deductions - "Diocese share: 10% of
            // Tithes received" for every church (only created when missing)
            // ==========================================
            StandardDeductionsSeeder::class,

            // ==========================================
            // PHASE 29: the Settings hub - one Settings page per church,
            // region and diocese, its section pages and the
            // {level}.settings.hub.* permissions (docs/specs/settings-spec.md)
            // ==========================================
            SettingsHubSeeder::class,

            // ==========================================
            // PHASE 30: the Calendar - one per church, region and diocese,
            // the CCI national calendar on top; {level}.calendar.events.*
            // (docs/specs/calendar-spec.md)
            // ==========================================
            CalendarAccessSeeder::class,

            // ==========================================
            // PHASE 31: Events (and, from L2, initiatives) - one page per
            // church, region and diocese; {level}.events.* permissions
            // (docs/specs/events-initiatives-spec.md)
            // ==========================================
            ActivitiesAccessSeeder::class,

            // ==========================================
            // PHASE 32: Monthly reports - churches and regions send one a
            // month, those above see and comment; {level}.reports.*
            // (docs/specs/monthly-reports-spec.md)
            // ==========================================
            MonthlyReportsAccessSeeder::class,

            // ==========================================
            // PHASE 33: the record pages in the church menu - Record
            // Attendance for whoever can record attendance, Demographics
            // Tracking named Record Demographics, and the demographics entry
            // grants put back where a role lost them.
            // ==========================================
            AttendanceRecordMenuSeeder::class,
        ]);
    }
}
