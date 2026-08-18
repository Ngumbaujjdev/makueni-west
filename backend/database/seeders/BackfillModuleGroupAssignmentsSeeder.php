<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\ModuleGroup;

class BackfillModuleGroupAssignmentsSeeder extends Seeder
{
    /**
     * ChurchSystemSeeder, DioceseSystemSeeder, and RegionSystemSeeder all
     * create their modules via Module::firstOrCreate() without ever setting
     * module_group_id. ModuleController::getModulesForRole() (the API that
     * builds the sidebar) starts from ModuleGroup and eager-loads its
     * modules - so any module with a NULL module_group_id is invisible in
     * the sidebar no matter what permissions a role holds. Confirmed: 35 of
     * 39 modules across Diocese/Region/Church tiers had this gap, discovered
     * because a Senior Pastor's sidebar only showed "Church Dashboard" (the
     * one module a dedicated seeder happened to set the group on) - not the
     * Demographics/Attendance modules this whole build depends on being
     * visible.
     *
     * This seeder runs after the three SystemSeeders on every seed pass
     * (not just once) and self-heals the assignment by module name, rather
     * than editing 37 module-creation call sites across three large files -
     * the same self-healing pattern already used by
     * FixDemographicsPermissionScaffoldingSeeder for submodule paths.
     *
     * Idempotent - safe to re-run. Only touches modules currently missing a
     * group; never overwrites an already-assigned module_group_id.
     */
    private const ASSIGNMENTS = [
        // Church tier (ChurchSystemSeeder, module numbers 1-12)
        'Member Management' => ['church', 'Members'],
        'Church Demographics & Growth' => ['church', 'Overview'],
        'Attendance Management' => ['church', 'Members'],
        'Financial Management' => ['church', 'Finance'],
        'Diocesan Contributions' => ['church', 'Finance'],
        'Ministry Coordination' => ['church', 'Programs'],
        'Service Management' => ['church', 'Programs'],
        'Facility Management' => ['church', 'Settings'],
        'Communication' => ['church', 'Programs'],
        'Visitor Management' => ['church', 'Members'],
        'Pastoral Care' => ['church', 'Programs'],
        'Church Reports' => ['church', 'Overview'],

        // Diocese tier (DioceseSystemSeeder, module numbers 19-35)
        'Diocese Dashboard' => ['diocese', 'Overview'],
        'Demographics Analytics' => ['diocese', 'Overview'],
        'Diocese Reports & Analytics' => ['diocese', 'Overview'],
        'All Churches Overview' => ['diocese', 'Churches'],
        'Individual Church Details' => ['diocese', 'Churches'],
        'Diocese Compliance Monitoring' => ['diocese', 'Churches'],
        'Tithe Management' => ['diocese', 'Finance'],
        'Diocese Budget Management' => ['diocese', 'Finance'],
        'Resource Allocation Management' => ['diocese', 'Finance'],
        'Diocese Income Tracking' => ['diocese', 'Finance'],
        'Diocese Expense Tracking' => ['diocese', 'Finance'],
        'Financial Reports' => ['diocese', 'Finance'],
        'Diocese Initiatives Management' => ['diocese', 'Programs'],
        'Diocese Events Management' => ['diocese', 'Programs'],
        'Diocese Calendar' => ['diocese', 'Programs'],
        'Diocese Communications Hub' => ['diocese', 'Programs'],
        'Diocese Settings' => ['diocese', 'Settings'],

        // Region tier (RegionSystemSeeder, module numbers 11-18)
        'Regional Oversight' => ['region', 'Overview'],
        'Church Support' => ['region', 'Churches'],
        'Pastoral Development' => ['region', 'Programs'],
        'Resource Management' => ['region', 'Finance'],
        'Regional Programs' => ['region', 'Programs'],
        'Conflict Resolution' => ['region', 'Churches'],
        'Regional Finances' => ['region', 'Finance'],
        'Regional Reporting' => ['region', 'Overview'],
    ];

    public function run(): void
    {
        $this->command->info('🔧 BACKFILLING MODULE GROUP ASSIGNMENTS');
        $this->command->info(str_repeat('=', 70));

        $fixed = 0;
        $skipped = 0;

        foreach (self::ASSIGNMENTS as $moduleName => [$scope, $groupName]) {
            $module = Module::where('name', $moduleName)->first();

            if (!$module) {
                $this->command->warn("   ⚠️  Module not found: {$moduleName}");
                continue;
            }

            if ($module->module_group_id !== null) {
                $skipped++;
                continue;
            }

            $group = ModuleGroup::where('territory_scope', $scope)
                ->where('name', $groupName)
                ->first();

            if (!$group) {
                $this->command->error("   ❌ Group not found: [{$scope}] {$groupName} (for module {$moduleName})");
                continue;
            }

            $module->update(['module_group_id' => $group->id]);
            $this->command->info("   ✅ {$moduleName} -> [{$scope}] {$groupName}");
            $fixed++;
        }

        $this->command->info('');
        $this->command->info("✅ DONE: {$fixed} module(s) linked, {$skipped} already correct.");
    }
}
