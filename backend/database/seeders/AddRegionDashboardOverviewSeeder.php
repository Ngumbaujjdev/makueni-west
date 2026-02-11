<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;
use Spatie\Permission\Models\Role;

class AddRegionDashboardOverviewSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🗺️  REGION DASHBOARD OVERVIEW SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Creating Region Dashboard module with Dashboard Overview...');
        $this->command->info('');

        // === STEP 1: CREATE OR GET REGION DASHBOARD MODULE ===
        $this->command->info('📊 Creating/Finding Region Dashboard Module...');

        // Check if a Region Dashboard module already exists by name
        $dashboardModule = Module::where('name', 'Region Dashboard')->first();

        if ($dashboardModule) {
            $this->command->info("   ✅ Found existing Module: {$dashboardModule->name} (ID: {$dashboardModule->id}, Number: {$dashboardModule->number})");
            $this->command->info("   Using existing module for dashboard overview...");
        } else {
            // Find the next available module number (check all modules, not just 11-20)
            $maxModuleNumber = Module::max('number');
            $nextNumber = $maxModuleNumber ? $maxModuleNumber + 1 : 19;

            $this->command->info("   Creating new Region Dashboard module (#{$nextNumber})...");

            $dashboardModule = Module::create([
                'name' => 'Region Dashboard',
                'icon' => 'dashboard',
                'number' => $nextNumber,
                'description' => 'Comprehensive overview of regional operations',
                'is_active' => true,
            ]);

            $this->command->info("   ✅ Created Module: {$dashboardModule->name} (ID: {$dashboardModule->id}, Number: {$dashboardModule->number})");
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
                'path' => '/region/dashboard/',
                'description' => 'Main region dashboard landing page with overview metrics',
                'is_active' => true,
            ]);

            $this->command->info("✅ Created Submodule: {$submodule->title} (ID: {$submodule->id})");
            $this->command->info('');

            // === STEP 4: CREATE PERMISSIONS FOR DASHBOARD OVERVIEW ===
            $this->command->info('🔐 Generating permissions...');

            $actions = ['read', 'export'];
            $createdPermissions = [];

            foreach ($actions as $action) {
                $permissionName = 'region.regiondashboard.dashboardoverview.' . $action;

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
                        'territory_scope' => 'region',
                    ]);

                    $createdPermissions[] = $permission;
                    $this->command->info("   ✅ Created: {$permissionName}");
                }
            }

            $this->command->info('');
        }

        // === STEP 5: GET ALL PERMISSIONS FOR DASHBOARD OVERVIEW ===
        $this->command->info('🔍 Getting Dashboard Overview permissions...');

        $dashboardOverviewPermissions = Permission::where('name', 'LIKE', 'region.regiondashboard.dashboardoverview.%')
            ->get();

        if ($dashboardOverviewPermissions->isEmpty()) {
            $this->command->error('❌ No Dashboard Overview permissions found!');
            return;
        }

        $this->command->info("✅ Found {$dashboardOverviewPermissions->count()} permissions");
        $this->command->info('');

        // === STEP 6: ASSIGN PERMISSIONS TO REGION ROLES ===
        $this->command->info('👥 Assigning permissions to Region roles...');
        $this->command->info(str_repeat('=', 70));

        $regionRoles = [
            'Regional Overseer',
            'Regional Secretary',
            'Senior Pastor',
            'Associate Pastor',
        ];

        $assignedCount = 0;
        $skippedCount = 0;

        foreach ($regionRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if (!$role) {
                $this->command->error("   ❌ Role not found: {$roleName}");
                continue;
            }

            $permissionNames = $dashboardOverviewPermissions->pluck('name')->toArray();
            $existingPermissions = $role->permissions()
                ->whereIn('name', $permissionNames)
                ->pluck('name')
                ->toArray();

            $newPermissions = array_diff($permissionNames, $existingPermissions);

            if (empty($newPermissions)) {
                $this->command->warn("   ⚠️  {$roleName} - Already has all permissions");
                $skippedCount++;
            } else {
                $role->givePermissionTo($newPermissions);
                $assignedCount++;
                $newCount = count($newPermissions);
                $this->command->info("   ✅ {$roleName} - Assigned {$newCount} new permission(s)");
            }
        }

        // === FINAL SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ REGION DASHBOARD OVERVIEW SEEDED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Module: Region Dashboard (#{$dashboardModule->id})");
        $this->command->info("   - Submodule: Dashboard Overview");
        $this->command->info("   - Permissions Created: {$dashboardOverviewPermissions->count()}");
        $this->command->info("   - Roles Updated: {$assignedCount}");
        $this->command->info("   - Roles Skipped: {$skippedCount}");
        $this->command->info('');
    }
}
