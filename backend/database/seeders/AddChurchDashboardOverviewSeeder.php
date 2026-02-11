<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class AddChurchDashboardOverviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates Church Dashboard Module and Dashboard Overview submodule
     * and assigns permissions to all Church-level roles
     */
    public function run(): void
    {
        $this->command->info('⛪ CHURCH DASHBOARD OVERVIEW SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Creating Church Dashboard module with Dashboard Overview...');
        $this->command->info('');

        // === STEP 1: CREATE OR GET CHURCH DASHBOARD MODULE ===
        $this->command->info('📊 Creating/Finding Church Dashboard Module...');

        // Use module #13 since #10 is already taken by Visitors
        $dashboardModule = Module::where('number', 13)->first();

        if ($dashboardModule) {
            $this->command->info("   ✅ Found existing Module: {$dashboardModule->name} (ID: {$dashboardModule->id})");
            $this->command->info("   Using existing module for dashboard overview...");
        } else {
            $this->command->info('   Creating new Church Dashboard module...');

            $dashboardModule = Module::create([
                'name' => 'Church Dashboard',
                'icon' => 'dashboard',
                'number' => 13,
                'description' => 'Comprehensive overview of church operations',
                'is_active' => true,
            ]);

            $this->command->info("   ✅ Created Module: {$dashboardModule->name} (ID: {$dashboardModule->id})");
        }

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
                'path' => '/church/dashboard/',
                'description' => 'Main church dashboard landing page with overview metrics',
                'is_active' => true,
            ]);

            $this->command->info("✅ Created Submodule: {$submodule->title} (ID: {$submodule->id})");
            $this->command->info('');

            // === STEP 4: CREATE PERMISSIONS FOR DASHBOARD OVERVIEW ===
            $this->command->info('🔐 Generating permissions...');

            $actions = ['read', 'export'];
            $createdPermissions = [];

            foreach ($actions as $action) {
                $permissionName = 'church.churchdashboard.dashboardoverview.' . $action;

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
                        'territory_scope' => 'church',
                    ]);

                    $createdPermissions[] = $permission;
                    $this->command->info("   ✅ Created: {$permissionName}");
                }
            }

            $this->command->info('');
        }

        // === STEP 5: GET ALL PERMISSIONS FOR DASHBOARD OVERVIEW ===
        $this->command->info('🔍 Getting Dashboard Overview permissions...');

        $dashboardOverviewPermissions = Permission::where('name', 'LIKE', 'church.churchdashboard.dashboardoverview.%')
            ->get();

        if ($dashboardOverviewPermissions->isEmpty()) {
            $this->command->error('❌ No Dashboard Overview permissions found!');
            return;
        }

        $this->command->info("✅ Found {$dashboardOverviewPermissions->count()} permissions");
        $this->command->info('');

        // === STEP 6: ASSIGN PERMISSIONS TO CHURCH ROLES ===
        $this->command->info('👥 Assigning permissions to Church roles...');
        $this->command->info(str_repeat('=', 70));

        $churchRoles = [
            'Senior Pastor',
            'Associate Pastor',
            'Church Secretary',
            'Church Treasurer',
        ];

        $assignedCount = 0;
        $skippedCount = 0;

        foreach ($churchRoles as $roleName) {
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
        $this->command->info('✅ CHURCH DASHBOARD OVERVIEW SEEDED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Module: Church Dashboard (#{$dashboardModule->id})");
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
        foreach ($churchRoles as $roleName) {
            $this->command->line("   - {$roleName}");
        }
        $this->command->info('');
        $this->command->info('🔗 Frontend Usage:');
        $this->command->line("   File: /makueni-west/church/dashboard/index.php");
        $this->command->line("   Permission: requirePermission('church.churchdashboard.dashboardoverview.read');");
    }
}
