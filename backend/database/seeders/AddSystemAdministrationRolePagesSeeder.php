<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AddSystemAdministrationRolePagesSeeder extends Seeder
{
    /**
     * Puts the existing access-control pages (diocese/settings/admin/
     * role-management, permissions, modules, module-groups) on the menu as
     * sub-submodules of Diocese Settings > System Administration, next to
     * User Management. The pages were built but never seeded, so the only
     * way to reach them was typing the URL.
     *
     * Each gets read/update permissions named the way DioceseSystemSeeder's
     * createPermissions() names the other System Administration pages, and
     * the pages now require their own .read (they used to require only
     * diocese.dashboard.dashboardoverview.read). These pages change what
     * every role can do, so they are granted to the Global Administrator
     * role only - not to the six diocese roles that hold User Management.
     * A global admin can grant them to another role from Role Management.
     *
     * Idempotent - safe to re-run.
     */
    private const MODULE_NAME = 'Diocese Settings';

    private const SUBMODULE_TITLE = 'System Administration';

    private const PERMISSION_PREFIX = 'diocesesettings.systemadministration';

    private const ACTIONS = ['read', 'update'];

    private const ADMIN_ROLES = ['Global Administrator'];

    private const PAGES = [
        ['title' => 'Role Management', 'slug' => 'rolemanagement', 'path' => '/diocese/settings/admin/role-management', 'description' => 'Create roles and choose what each one can do.'],
        ['title' => 'Permissions', 'slug' => 'permissions', 'path' => '/diocese/settings/admin/permissions', 'description' => 'Every permission in the system, by module.'],
        ['title' => 'Modules', 'slug' => 'modules', 'path' => '/diocese/settings/admin/modules', 'description' => 'The modules, submodules and pages on the menu.'],
        ['title' => 'Module Groups', 'slug' => 'modulegroups', 'path' => '/diocese/settings/admin/module-groups', 'description' => 'The sidebar sections modules are grouped under.'],
    ];

    public function run(): void
    {
        $this->command->info('➕ ADDING ACCESS-CONTROL PAGES (Diocese Settings > System Administration)');
        $this->command->info(str_repeat('=', 70));

        $module = Module::where('name', self::MODULE_NAME)->first();
        $submodule = $module
            ? Submodule::where('module_id', $module->id)->where('title', self::SUBMODULE_TITLE)->first()
            : null;

        if (! $submodule) {
            $this->command->error('   ❌ '.self::MODULE_NAME.' > '.self::SUBMODULE_TITLE.' not found - run DioceseSystemSeeder first');

            return;
        }

        $roles = Role::whereIn('name', self::ADMIN_ROLES)->get();
        $granted = 0;

        foreach (self::PAGES as $page) {
            $subSubmodule = SubSubmodule::firstOrCreate(
                ['submodule_id' => $submodule->id, 'title' => $page['title']],
                ['path' => $page['path'], 'description' => $page['description'], 'is_active' => true]
            );

            foreach (self::ACTIONS as $action) {
                $permission = Permission::firstOrCreate(
                    ['name' => self::PERMISSION_PREFIX.'.'.$page['slug'].'.'.$action],
                    [
                        'guard_name' => 'web',
                        'module_id' => $module->id,
                        'submodule_id' => $submodule->id,
                        'sub_submodule_id' => $subSubmodule->id,
                        'action' => $action,
                        'territory_scope' => 'diocese',
                    ]
                );

                foreach ($roles as $role) {
                    if (! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                        $granted++;
                    }
                }
            }

            $this->command->info('   ✅ '.$page['title'].' -> '.$page['path']);
        }

        $this->command->info('   ✅ '.(count(self::PAGES) * count(self::ACTIONS)).' permission(s) ensured, '.$granted.' new grant(s) to: '.implode(', ', self::ADMIN_ROLES));
        $this->command->info('');
        $this->command->info('✅ Access-control pages are on the System Administration menu.');
    }
}
