<?php

namespace Tests\Feature\Diocese;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use Database\Seeders\AddSystemAdministrationRolePagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAdministrationRolePagesSeederTest extends TestCase
{
    use RefreshDatabase;

    protected Submodule $systemAdministration;

    protected Role $globalAdmin;

    protected Role $bishop;

    protected function setUp(): void
    {
        parent::setUp();

        $module = Module::create(['name' => 'Diocese Settings', 'icon' => 'cogs', 'number' => 35, 'is_active' => true]);
        $this->systemAdministration = Submodule::create([
            'module_id' => $module->id, 'title' => 'System Administration',
            'path' => '/diocese/settings/admin', 'is_active' => true,
        ]);
        SubSubmodule::create([
            'submodule_id' => $this->systemAdministration->id, 'title' => 'User Management',
            'path' => '/diocese/settings/admin/users', 'is_active' => true,
        ]);

        $this->globalAdmin = Role::create(['name' => 'Global Administrator', 'guard_name' => 'web', 'territory_level' => 'diocese']);
        $this->bishop = Role::create(['name' => 'Bishop', 'guard_name' => 'web', 'territory_level' => 'diocese']);
    }

    public function test_access_control_pages_are_added_under_system_administration(): void
    {
        $this->seed(AddSystemAdministrationRolePagesSeeder::class);

        $pages = SubSubmodule::where('submodule_id', $this->systemAdministration->id)->pluck('path', 'title');

        $this->assertSame('/diocese/settings/admin/role-management', $pages['Role Management']);
        $this->assertSame('/diocese/settings/admin/permissions', $pages['Permissions']);
        $this->assertSame('/diocese/settings/admin/modules', $pages['Modules']);
        $this->assertSame('/diocese/settings/admin/module-groups', $pages['Module Groups']);
        $this->assertSame('/diocese/settings/admin/users', $pages['User Management'], 'existing entries are left alone');
    }

    public function test_each_page_gets_read_and_update_permissions_tied_to_its_menu_entry(): void
    {
        $this->seed(AddSystemAdministrationRolePagesSeeder::class);

        $roleManagement = SubSubmodule::where('title', 'Role Management')->firstOrFail();

        foreach (['rolemanagement', 'permissions', 'modules', 'modulegroups'] as $slug) {
            foreach (['read', 'update'] as $action) {
                $this->assertDatabaseHas('permissions', [
                    'name' => "diocesesettings.systemadministration.{$slug}.{$action}",
                    'territory_scope' => 'diocese',
                    'submodule_id' => $this->systemAdministration->id,
                ]);
            }
        }

        $this->assertSame(
            $roleManagement->id,
            Permission::where('name', 'diocesesettings.systemadministration.rolemanagement.read')->value('sub_submodule_id')
        );
    }

    public function test_only_the_global_administrator_role_is_granted_them(): void
    {
        $this->seed(AddSystemAdministrationRolePagesSeeder::class);

        $this->assertTrue($this->globalAdmin->fresh()->hasPermissionTo('diocesesettings.systemadministration.rolemanagement.update'));
        $this->assertTrue($this->globalAdmin->fresh()->hasPermissionTo('diocesesettings.systemadministration.modulegroups.read'));
        $this->assertFalse($this->bishop->fresh()->hasPermissionTo('diocesesettings.systemadministration.rolemanagement.read'));
    }

    public function test_running_it_twice_creates_nothing_new(): void
    {
        $this->seed(AddSystemAdministrationRolePagesSeeder::class);
        $this->seed(AddSystemAdministrationRolePagesSeeder::class);

        $this->assertSame(5, SubSubmodule::where('submodule_id', $this->systemAdministration->id)->count());
        $this->assertSame(8, Permission::where('name', 'like', 'diocesesettings.systemadministration.%')->count());
        $this->assertSame(8, $this->globalAdmin->fresh()->permissions()->count());
    }
}
