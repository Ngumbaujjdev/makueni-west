<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Permission;
use App\Models\Module;

class DioceseRolePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🔐 DIOCESE ROLE PERMISSIONS SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Assigning Diocese Module permissions to Diocese roles...');
        $this->command->info('');

        // Get Diocese Modules (19-35)
        $dioceseModules = Module::whereBetween('number', [19, 35])->get();

        if ($dioceseModules->count() === 0) {
            $this->command->error('❌ No diocese modules found! Please run DioceseSystemSeeder first.');
            return;
        }

        $this->command->info("📊 Found {$dioceseModules->count()} diocese modules");
        $this->command->info('');

        // Get all permissions for these modules
        $dioceseModuleIds = $dioceseModules->pluck('id')->toArray();
        $allDiocesePermissions = Permission::whereIn('module_id', $dioceseModuleIds)->get();

        $this->command->info("📊 Found {$allDiocesePermissions->count()} diocese permissions");
        $this->command->info('');

        // === ASSIGN PERMISSIONS TO DIOCESE ROLES ===
        $this->command->info('🔐 Assigning permissions to roles...');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');

        // 1. BISHOP - Full Access to All Diocese Modules
        $this->assignPermissionsToRole('Bishop', $allDiocesePermissions, 'Full access to all diocese modules');

        // 2. DIOCESE COUNCIL MEMBER - Full Access to All Diocese Modules
        $this->assignPermissionsToRole('Diocese Council Member', $allDiocesePermissions, 'Full access to all diocese modules');

        // 3. DIOCESE SECRETARY - Full Access to All Diocese Modules
        $this->assignPermissionsToRole('Diocese Secretary', $allDiocesePermissions, 'Full access to all diocese modules');

        // 4. DIOCESE TREASURER - Full Access to All Diocese Modules (especially financial)
        $this->assignPermissionsToRole('Diocese Treasurer', $allDiocesePermissions, 'Full access to all diocese modules');

        // 5. DIOCESE ADMINISTRATOR - Full Access to All Diocese Modules
        $this->assignPermissionsToRole('Diocese Administrator', $allDiocesePermissions, 'Full access to all diocese modules');

        // === SUMMARY ===
        $this->command->info('');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ DIOCESE ROLE PERMISSIONS ASSIGNED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Diocese Modules: {$dioceseModules->count()}");
        $this->command->info("   - Total Permissions: {$allDiocesePermissions->count()}");
        $this->command->info("   - Roles Configured: 5");
        $this->command->info('');
        $this->command->info('🎯 Next Steps:');
        $this->command->info('   1. Run RegionRolePermissionsSeeder for regional roles');
        $this->command->info('   2. Run ChurchRolePermissionsSeeder for church roles');
        $this->command->info('   3. Then seed actual users (Bishop, Regional Overseers, Pastors)');
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
