<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Permission;
use App\Models\Module;

class ChurchRolePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This was referenced as a planned "next step" in DioceseRolePermissionsSeeder's
     * output but never actually built — church-level roles (Senior Pastor, Associate
     * Pastor, etc.) had zero permissions assigned, so every church-level login saw an
     * empty module list / sidebar despite having a valid territory assignment.
     */
    public function run(): void
    {
        $this->command->info('⛪ CHURCH ROLE PERMISSIONS SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Assigning Church Module permissions to Church-level roles...');
        $this->command->info('');

        // Church modules, by the module's actual scope (module_groups.territory_scope)
        // — NOT a number range. Modules 11-12 (Pastoral Care, Church Reporting) are
        // church-scoped despite sitting right before the region range numerically;
        // whereBetween('number', [1, 10]) silently dropped them on the first pass.
        $churchModules = Module::whereHas('moduleGroup', function ($query) {
            $query->where('territory_scope', 'church');
        })->get();

        if ($churchModules->count() === 0) {
            $this->command->error('❌ No church modules found! Please run ChurchSystemSeeder first.');
            return;
        }

        $this->command->info("📊 Found {$churchModules->count()} church modules");
        $this->command->info('');

        // Get all permissions for these modules
        $churchModuleIds = $churchModules->pluck('id')->toArray();
        $allChurchPermissions = Permission::whereIn('module_id', $churchModuleIds)->get();

        $this->command->info("📊 Found {$allChurchPermissions->count()} church permissions");
        $this->command->info('');

        // === ASSIGN PERMISSIONS TO CHURCH ROLES ===
        $this->command->info('🔐 Assigning permissions to roles...');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // Church-level roles get full access to all church modules — same
        // "full access at this level" pattern DioceseRolePermissionsSeeder and
        // RegionRolePermissionsSeeder already use for their tier's roles.
        $this->assignPermissionsToRole('Senior Pastor', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Associate Pastor', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Church Secretary', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Church Treasurer', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Church Committee Member', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Elder', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Deacon', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Youth Leader', $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole("Women's Ministry Leader", $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole("Men's Ministry Leader", $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole("Children's Ministry Leader", $allChurchPermissions, 'Full access to all church modules');
        $this->assignPermissionsToRole('Music Director', $allChurchPermissions, 'Full access to all church modules');

        // === SUMMARY ===
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ CHURCH ROLE PERMISSIONS ASSIGNED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Church Modules: {$churchModules->count()}");
        $this->command->info("   - Total Permissions: {$allChurchPermissions->count()}");
        $this->command->info("   - Roles Configured: 12");
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

        // Clear existing permissions first
        $role->permissions()->detach();

        // Assign all permissions
        $permissionNames = $permissions->pluck('name')->toArray();
        $role->givePermissionTo($permissionNames);

        $this->command->info("✅ {$roleName}");
        $this->command->line("   - {$description}");
        $this->command->line("   - Assigned {$permissions->count()} permissions");
        $this->command->info('');
    }
}
