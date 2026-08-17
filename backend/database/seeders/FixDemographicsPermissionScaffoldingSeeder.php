<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class FixDemographicsPermissionScaffoldingSeeder extends Seeder
{
    /**
     * The Demographics permission tree (Church "Growth" #27, Church
     * "Attendance" #28, Diocese "Demographics" #2) was already seeded in
     * detail, but two gaps block requirePermission()-gated pages from
     * working once they're built:
     *
     * 1. Every submodule path is a clean route string (e.g.
     *    "/demographics/tracking") rather than the real frontend file path
     *    (e.g. "church/demographics-growth/demographics-tracking.php") -
     *    the same path-mismatch pattern AddChurchDashboardOverviewSeeder
     *    and AddRegionDashboardOverviewSeeder already had to self-heal.
     * 2. None of the three modules has an "Overview" submodule for their
     *    landing/index page.
     *
     * Idempotent - safe to re-run.
     */

    /** submodule_id => real file path */
    private const PATH_FIXES = [
        // Module 27: Growth -> church/demographics-growth/
        125 => 'church/demographics-growth/demographics-tracking.php',
        126 => 'church/demographics-growth/spiritual-activities.php',
        127 => 'church/demographics-growth/growth-analytics.php',
        128 => 'church/demographics-growth/monthly-statistics.php',

        // Module 28: Attendance -> church/attendance/
        129 => 'church/attendance/services.php',
        130 => 'church/attendance/ministries.php',
        131 => 'church/attendance/events.php',
        132 => 'church/attendance/reports.php',

        // Module 2: Diocese Demographics -> diocese/demographics-analytics/
        7 => 'diocese/demographics-analytics/summary.php',
        8 => 'diocese/demographics-analytics/growth.php',
        9 => 'diocese/demographics-analytics/comparative.php',
        10 => 'diocese/demographics-analytics/forecasting.php',
        11 => 'diocese/demographics-analytics/heatmap.php',
    ];

    /** module_id => [path, permission_name, territory_scope, description, roles] */
    private const MISSING_OVERVIEWS = [
        27 => [
            'path' => 'church/demographics-growth/index.php',
            'permission' => 'churchdemographicsgrowth.overview.read',
            'territory_scope' => 'church',
            'description' => 'Growth & demographics overview landing page',
            'roles' => [
                'Global Administrator', 'Senior Pastor', 'Associate Pastor', 'Church Secretary',
                'Church Treasurer', 'Church Committee Member', 'Elder', 'Deacon', 'Youth Leader',
                "Women's Ministry Leader", "Men's Ministry Leader", "Children's Ministry Leader",
                'Music Director', 'Youth Pastor', 'Church Administrator', 'Worship Leader',
                'Sunday School Teacher', 'Choir Director', 'Usher Coordinator', 'Prayer Group Leader',
            ],
        ],
        28 => [
            'path' => 'church/attendance/index.php',
            'permission' => 'attendancemanagement.overview.read',
            'territory_scope' => 'church',
            'description' => 'Attendance overview landing page',
            'roles' => [
                'Global Administrator', 'Senior Pastor', 'Associate Pastor', 'Church Secretary',
                'Church Treasurer', 'Church Committee Member', 'Elder', 'Deacon', 'Youth Leader',
                "Women's Ministry Leader", "Men's Ministry Leader", "Children's Ministry Leader",
                'Music Director', 'Youth Pastor', 'Church Administrator', 'Worship Leader',
                'Sunday School Teacher', 'Choir Director', 'Usher Coordinator', 'Prayer Group Leader',
            ],
        ],
        2 => [
            'path' => 'diocese/demographics-analytics/index.php',
            'permission' => 'demographicsanalytics.overview.read',
            'territory_scope' => 'diocese',
            'description' => 'Diocese-wide demographics overview landing page',
            'roles' => [
                'Global Administrator', 'Bishop', 'Diocese Council Member', 'Diocese Secretary',
                'Diocese Treasurer', 'Diocese Administrator',
            ],
        ],
    ];

    public function run(): void
    {
        $this->command->info('🔧 FIXING DEMOGRAPHICS PERMISSION SCAFFOLDING');
        $this->command->info(str_repeat('=', 70));

        $this->fixPaths();
        $this->addMissingOverviews();

        $this->command->info('');
        $this->command->info('✅ Demographics permission scaffolding fixed.');
    }

    private function fixPaths(): void
    {
        $this->command->info('📎 Correcting submodule paths...');

        $fixed = 0;
        foreach (self::PATH_FIXES as $submoduleId => $realPath) {
            $submodule = Submodule::find($submoduleId);

            if (!$submodule) {
                $this->command->error("   ❌ Submodule ID {$submoduleId} not found - skipping");
                continue;
            }

            if ($submodule->path !== $realPath) {
                $oldPath = $submodule->path;
                $submodule->path = $realPath;
                $submodule->save();
                $fixed++;
                $this->command->info("   ✅ {$submodule->title}: {$oldPath} -> {$realPath}");
            } else {
                $this->command->warn("   ⚠️  {$submodule->title} already correct ({$realPath})");
            }
        }

        $this->command->info("   {$fixed} path(s) corrected.");
        $this->command->info('');
    }

    private function addMissingOverviews(): void
    {
        $this->command->info('➕ Adding missing Overview submodules...');

        foreach (self::MISSING_OVERVIEWS as $moduleId => $config) {
            $module = Module::find($moduleId);

            if (!$module) {
                $this->command->error("   ❌ Module ID {$moduleId} not found - skipping");
                continue;
            }

            $submodule = Submodule::where('module_id', $moduleId)
                ->where('title', 'Overview')
                ->first();

            if ($submodule) {
                $this->command->warn("   ⚠️  {$module->name} already has an Overview submodule");
            } else {
                $submodule = Submodule::create([
                    'module_id' => $moduleId,
                    'title' => 'Overview',
                    'path' => $config['path'],
                    'description' => $config['description'],
                    'is_active' => true,
                ]);
                $this->command->info("   ✅ Created Overview submodule for {$module->name} (ID: {$submodule->id})");
            }

            $permission = Permission::where('name', $config['permission'])->first();

            if ($permission) {
                $this->command->warn("      ⚠️  Permission already exists: {$config['permission']}");
            } else {
                $permission = Permission::create([
                    'name' => $config['permission'],
                    'guard_name' => 'web',
                    'module_id' => $moduleId,
                    'submodule_id' => $submodule->id,
                    'sub_submodule_id' => null,
                    'action' => 'read',
                    'territory_scope' => $config['territory_scope'],
                ]);
                $this->command->info("      ✅ Created permission: {$config['permission']}");
            }

            $roles = Role::whereIn('name', $config['roles'])->get();
            $assigned = 0;

            foreach ($roles as $role) {
                if (!$role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                    $assigned++;
                }
            }

            $this->command->info("      👥 {$assigned} role(s) newly granted {$config['permission']} ({$roles->count()} total should have it)");
        }

        $this->command->info('');
    }
}
