<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Permission;
use App\Models\Module;

class GlobalAdministratorPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🌍 GLOBAL ADMINISTRATOR PERMISSIONS SEEDER');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('Assigning ALL permissions to Global Administrator...');
        $this->command->info('');

        // Get Global Administrator role
        $globalAdminRole = Role::where('name', 'Global Administrator')->first();

        if (!$globalAdminRole) {
            $this->command->error('❌ Global Administrator role not found!');
            return;
        }

        // Get ALL modules
        $allModules = Module::all();
        $this->command->info("📊 Found {$allModules->count()} total modules");

        // Get ALL permissions from ALL modules
        $allPermissions = Permission::all();
        $this->command->info("📊 Found {$allPermissions->count()} total permissions");
        $this->command->info('');

        if ($allPermissions->count() === 0) {
            $this->command->error('❌ No permissions found! Please run module and permission seeders first.');
            return;
        }

        // Clear existing permissions first
        $globalAdminRole->permissions()->detach();

        // Assign ALL permissions to Global Administrator
        $permissionNames = $allPermissions->pluck('name')->toArray();
        $globalAdminRole->givePermissionTo($permissionNames);

        $this->command->info('✅ GLOBAL ADMINISTRATOR');
        $this->command->line("   - Assigned ALL {$allPermissions->count()} permissions");
        $this->command->line('   - Has access to ALL modules across ALL territorial levels');
        $this->command->info('');

        // Show breakdown by module
        $this->command->info('📊 Permissions by Module:');
        foreach ($allModules as $module) {
            $modulePermissions = $allPermissions->where('module_id', $module->id);
            if ($modulePermissions->count() > 0) {
                $this->command->line("   - {$module->name}: {$modulePermissions->count()} permissions");
            }
        }
        $this->command->info('');

        $this->command->info(str_repeat('=', 70));
        $this->command->info('✅ GLOBAL ADMINISTRATOR PERMISSIONS ASSIGNED SUCCESSFULLY!');
        $this->command->info(str_repeat('=', 70));
        $this->command->info('');
        $this->command->info('📊 Summary:');
        $this->command->info("   - Total Modules: {$allModules->count()}");
        $this->command->info("   - Total Permissions: {$allPermissions->count()}");
        $this->command->info("   - Role: Global Administrator");
        $this->command->info('');
        $this->command->info('🎯 Global Administrator can now:');
        $this->command->info('   ✓ Access ALL diocese modules');
        $this->command->info('   ✓ Access ALL region modules');
        $this->command->info('   ✓ Access ALL church modules');
        $this->command->info('   ✓ Manage all users and territories');
        $this->command->info('   ✓ Override all permission checks');
        $this->command->info('');
        $this->command->info('⚠️  IMPORTANT: Logout and login again to see changes!');
    }
}
