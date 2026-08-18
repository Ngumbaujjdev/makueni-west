<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AddGatheringTypesSubmoduleSeeder extends Seeder
{
    /**
     * Adds the church-level "Gathering Types" config screen (see the
     * gathering-types-config plan) under the existing "Settings" module
     * group at church tier (ModuleGroupSeeder's church-settings group,
     * already seeded but empty before this) as a new "Attendance
     * Settings" module - not nested inside the Attendance module itself,
     * per explicit user correction ("this was supposed to be a new church
     * module under the settings group"). Grants read/create/update to the
     * same four roles that already do Attendance entry
     * (GrantDemographicsEntryPermissionsSeeder).
     *
     * Idempotent - safe to re-run. Superseded a first version of this
     * seeder that placed the submodule under Module 28 (Attendance) with
     * permission prefix church.attendance.gatheringtypes - see
     * RelocateGatheringTypesToChurchSettingsSeeder for the one-time repair
     * of databases that already ran that version.
     */
    private const MODULE_GROUP_SLUG = 'church-settings';

    private const MODULE_NAME = 'Attendance Settings';

    private const MODULE_ICON = 'ri-settings-3-line';

    private const MODULE_NUMBER = 1;

    private const SUBMODULE_TITLE = 'Gathering Types';

    private const SUBMODULE_PATH = '/church/settings/attendance-settings/gathering-types.php';

    private const PERMISSION_PREFIX = 'church.settings.attendancesettings.gatheringtypes';

    private const ACTIONS = ['read', 'create', 'update'];

    private const ENTRY_ROLES = ['Senior Pastor', 'Associate Pastor', 'Church Secretary', 'Church Administrator'];

    public function run(): void
    {
        $this->command->info('➕ ADDING GATHERING TYPES SUBMODULE (Church Settings > Attendance Settings)');
        $this->command->info(str_repeat('=', 70));

        $group = ModuleGroup::where('slug', self::MODULE_GROUP_SLUG)->first();

        if (! $group) {
            $this->command->error('   ❌ Module group "'.self::MODULE_GROUP_SLUG.'" not found - aborting');

            return;
        }

        $module = Module::firstOrCreate(
            ['name' => self::MODULE_NAME, 'module_group_id' => $group->id],
            [
                'icon' => self::MODULE_ICON,
                'number' => self::MODULE_NUMBER,
                'description' => 'Church-level configuration for the Attendance module.',
                'is_active' => true,
            ]
        );

        $this->command->info('   ✅ Module ready: '.self::MODULE_NAME.' (ID: '.$module->id.')');

        $submodule = Submodule::where('module_id', $module->id)
            ->where('title', self::SUBMODULE_TITLE)
            ->first();

        if ($submodule) {
            $this->command->warn('   ⚠️  Submodule already exists (ID: '.$submodule->id.')');
        } else {
            $submodule = Submodule::create([
                'module_id' => $module->id,
                'title' => self::SUBMODULE_TITLE,
                'path' => self::SUBMODULE_PATH,
                'description' => 'Configure this church\'s own gathering types (e.g. Kesha, Tuesday Fellowship) used in attendance entry.',
                'is_active' => true,
            ]);
            $this->command->info('   ✅ Created submodule (ID: '.$submodule->id.')');
        }

        $roles = Role::whereIn('name', self::ENTRY_ROLES)->get();
        $granted = 0;

        foreach (self::ACTIONS as $action) {
            $permissionName = self::PERMISSION_PREFIX.'.'.$action;

            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                [
                    'guard_name' => 'web',
                    'module_id' => $module->id,
                    'submodule_id' => $submodule->id,
                    'sub_submodule_id' => null,
                    'action' => $action,
                    'territory_scope' => 'church',
                ]
            );

            foreach ($roles as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                    $granted++;
                }
            }
        }

        $this->command->info('   ✅ '.count(self::ACTIONS).' permission(s) ensured, '.$granted.' new grant(s) to: '.implode(', ', self::ENTRY_ROLES));
        $this->command->info('');
        $this->command->info('✅ Gathering Types submodule ready.');
    }
}
