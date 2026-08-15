<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Permission;
use App\Models\Module;

class RegionRolePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🔐 REGION ROLE PERMISSIONS SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Assigning Region Module permissions to Regional roles...');
        $this->command->info('');

        // Region modules, by the module's actual scope (module_groups.territory_scope)
        // — NOT a number range. Modules 11-12 (Pastoral Care, Church Reporting) sit
        // inside this number range but are church-scoped, not region-scoped;
        // whereBetween('number', [11, 18]) wrongly included them.
        $regionModules = Module::whereHas('moduleGroup', function ($query) {
            $query->where('territory_scope', 'region');
        })->get();

        if ($regionModules->count() === 0) {
            $this->command->error('❌ No region modules found! Please run RegionSystemSeeder first.');
            return;
        }

        $this->command->info("📊 Found {$regionModules->count()} region modules");
        $this->command->info('');

        // Get all permissions for these modules
        $regionModuleIds = $regionModules->pluck('id')->toArray();
        $allRegionPermissions = Permission::whereIn('module_id', $regionModuleIds)->get();

        $this->command->info("📊 Found {$allRegionPermissions->count()} region permissions");
        $this->command->info('');

        // === ASSIGN PERMISSIONS TO REGIONAL ROLES ===
        $this->command->info('🔐 Assigning permissions to roles...');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // 1. REGIONAL OVERSEER - Full Access to All Region Modules
        $this->assignPermissionsToRole('Regional Overseer', $allRegionPermissions, 'Full access to all region modules');

        // 2. REGIONAL SECRETARY - Full Access to All Region Modules
        $this->assignPermissionsToRole('Regional Secretary', $allRegionPermissions, 'Full access to all region modules');

        // Senior Pastor / Associate Pastor previously got full region access
        // granted directly to the church-scoped Pastor role itself ("as
        // committee member") — superseded by AdditionalRolePermissionsSeeder's
        // dedicated Regional Committee Member role, which RegionalLeadershipSeeder
        // now actually assigns pastors to as their secondary seat. Keeping both
        // would leave dead, confusing permission weight on the Pastor roles for
        // a territory context (region) their assignments no longer use.

        // === SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ REGION ROLE PERMISSIONS ASSIGNED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Region Modules: {$regionModules->count()}");
        $this->command->info("   - Total Permissions: {$allRegionPermissions->count()}");
        $this->command->info("   - Roles Configured: 4");
        $this->command->info('');
        $this->command->info('🎯 Next Steps:');
        $this->command->info('   1. Run RegionalLeadershipSeeder to seed regional committees');
        $this->command->info('   2. Run ChurchRolePermissionsSeeder for church roles');
        $this->command->info('   3. Seed actual pastors for churches');
    }

    /**
     * Helper method to assign permissions to a role
     */
    private function assignPermissionsToRole($roleName, $permissions, $description)
    {
        $role = Role::where('name', $roleName)->first();

        if (!$role) {
            $this->command->error("   ❌ Role '{$roleName}' not found!");
            return;
        }

        // Get existing permissions for the role
        $existingPermissions = $role->permissions->pluck('name')->toArray();

        // Add new permissions (don't remove existing ones)
        $permissionNames = $permissions->pluck('name')->toArray();
        $role->givePermissionTo($permissionNames);

        $this->command->info("✅ {$roleName}");
        $this->command->line("   - {$description}");
        $this->command->line("   - Added {$permissions->count()} region permissions");
        $this->command->info('');
    }
}
