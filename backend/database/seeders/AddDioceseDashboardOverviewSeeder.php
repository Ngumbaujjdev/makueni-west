<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

class AddDioceseDashboardOverviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Adds "Dashboard Overview" submodule to existing Diocese Dashboard Module #19
     * and assigns permissions to all Diocese-level roles
     */
    public function run(): void
    {
        $this->command->info('🏛️  DIOCESE DASHBOARD OVERVIEW SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Adding Dashboard Overview submodule to Diocese Dashboard...');
        $this->command->info('');

        // === STEP 1: GET DIOCESE DASHBOARD MODULE ===
        $this->command->info('📊 Finding Diocese Dashboard Module...');

        $dashboardModule = Module::where('number', 19)
            ->where('name', 'Dashboard')
            ->first();

        if (!$dashboardModule) {
            $this->command->error('❌ Diocese Dashboard Module #19 not found!');
            $this->command->error('   Please run DioceseSystemSeeder first.');
            return;
        }

        $this->command->info("✅ Found Module: {$dashboardModule->name} (ID: {$dashboardModule->id})");
        $this->command->info('');

        // === STEP 2: CHECK IF DASHBOARD OVERVIEW ALREADY EXISTS ===
        $existingSubmodule = Submodule::where('module_id', $dashboardModule->id)
            ->where('title', 'Dashboard Overview')
            ->first();

        if ($existingSubmodule) {
            $this->command->warn('⚠️  Dashboard Overview submodule already exists!');
            $this->command->info('   Skipping submodule creation...');
            $this->command->info('');
        } else {
            // === STEP 3: CREATE DASHBOARD OVERVIEW SUBMODULE ===
            $this->command->info('➕ Creating Dashboard Overview submodule...');

            $submodule = Submodule::create([
                'module_id' => $dashboardModule->id,
                'title' => 'Dashboard Overview',
                'path' => '/diocese/dashboard/',
                'description' => 'Main dashboard landing page with overview metrics',
                'is_active' => true,
            ]);

            $this->command->info("✅ Created Submodule: {$submodule->title} (ID: {$submodule->id})");
            $this->command->info('');

            // === STEP 4: CREATE PERMISSIONS FOR DASHBOARD OVERVIEW ===
            $this->command->info('🔐 Generating permissions...');

            $actions = ['read', 'export'];
            $createdPermissions = [];

            foreach ($actions as $action) {
                $permissionName = 'diocese.dashboard.dashboardoverview.' . $action;

                // Check if permission already exists
                $existingPermission = Permission::where('name', $permissionName)->first();

                if ($existingPermission) {
                    $this->command->warn("   ⚠️  Permission already exists: {$permissionName}");
                    $createdPermissions[] = $existingPermission;
                } else {
                    $permission = Permission::create([
                        'name' => $permissionName,
                        'guard_name' => 'web',
                        'module_id' => $dashboardModule->id,
                        'submodule_id' => $submodule->id,
                        'sub_submodule_id' => null,
                        'action' => $action,
                        'territory_scope' => 'diocese',
                    ]);

                    $createdPermissions[] = $permission;
                    $this->command->info("   ✅ Created: {$permissionName}");
                }
            }

            $this->command->info('');
        }

        // === STEP 5: GET ALL PERMISSIONS FOR DASHBOARD OVERVIEW ===
        $this->command->info('🔍 Getting Dashboard Overview permissions...');

        $dashboardOverviewPermissions = Permission::where('name', 'LIKE', 'diocese.dashboard.dashboardoverview.%')
            ->get();

        if ($dashboardOverviewPermissions->isEmpty()) {
            $this->command->error('❌ No Dashboard Overview permissions found!');
            return;
        }

        $this->command->info("✅ Found {$dashboardOverviewPermissions->count()} permissions");
        $this->command->info('');

        // === STEP 6: ASSIGN PERMISSIONS TO DIOCESE ROLES ===
        $this->command->info('👥 Assigning permissions to Diocese roles...');
        $this->command->info(str_repeat('=', 70));

        $dioceseRoles = [
            'Bishop',
            'Diocese Council Member',
            'Diocese Secretary',
            'Diocese Treasurer',
            'Diocese Administrator',
        ];

        $assignedCount = 0;
        $skippedCount = 0;

        foreach ($dioceseRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if (!$role) {
                $this->command->error("   ❌ Role not found: {$roleName}");
                continue;
            }

            // Get permission names
            $permissionNames = $dashboardOverviewPermissions->pluck('name')->toArray();

            // Check how many permissions the role already has
            $existingPermissions = $role->permissions()
                ->whereIn('name', $permissionNames)
                ->pluck('name')
                ->toArray();

            $newPermissions = array_diff($permissionNames, $existingPermissions);

            if (empty($newPermissions)) {
                $this->command->warn("   ⚠️  {$roleName} - Already has all permissions");
                $skippedCount++;
            } else {
                // Give new permissions
                $role->givePermissionTo($newPermissions);
                $assignedCount++;
                $newCount = count($newPermissions);
                $this->command->info("   ✅ {$roleName} - Assigned {$newCount} new permission(s)");
            }
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ DIOCESE DASHBOARD OVERVIEW SEEDED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Module: Diocese Dashboard (#{$dashboardModule->id})");
        $this->command->info("   - Submodule: Dashboard Overview");
        $this->command->info("   - Permissions Created: {$dashboardOverviewPermissions->count()}");
        $this->command->info("   - Roles Updated: {$assignedCount}");
        $this->command->info("   - Roles Skipped: {$skippedCount}");
        $this->command->info('');
        $this->command->info('🎯 Permissions:');
        foreach ($dashboardOverviewPermissions as $permission) {
            $this->command->line("   - {$permission->name}");
        }
        $this->command->info('');
        $this->command->info('👥 Assigned to Roles:');
        foreach ($dioceseRoles as $roleName) {
            $this->command->line("   - {$roleName}");
        }
        $this->command->info('');
        $this->command->info('🔗 Frontend Usage:');
        $this->command->line("   File: /makueni-west/diocese/dashboard/index.php");
        $this->command->line("   Permission: requirePermission('diocese.dashboard.dashboardoverview.read');");
        $this->command->info('');
        $this->command->info('🚀 Next Steps:');
        $this->command->info('   1. Update /diocese/dashboard/index.php to use the new permission');
        $this->command->info('   2. Test with Bishop login');
        $this->command->info('   3. Then create Church & Region dashboard overviews');
    }
}
