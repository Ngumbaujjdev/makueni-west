<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AddDemographicsSettingsSubmoduleSeeder extends Seeder
{
    /**
     * Adds the church-level "Recording Cadence" config screen under the
     * existing "Settings" module group at church tier (ModuleGroupSeeder's
     * church-settings group) as a new "Demographics Settings" module -
     * mirrors AddGatheringTypesSubmoduleSeeder's exact shape ("Attendance
     * Settings" under the same group). The recording-cadence toggle
     * (monthly/half_yearly/yearly) used to live as a card on the Growth
     * Overview dashboard; it moved here (2026-09-09) to its own Settings
     * page, matching where Attendance's equivalent config already lives.
     * Grants read/update to the same four roles that already do
     * Demographics entry (GrantDemographicsEntryPermissionsSeeder).
     *
     * Idempotent - safe to re-run.
     */
    private const MODULE_GROUP_SLUG = 'church-settings';

    private const MODULE_NAME = 'Demographics Settings';

    private const MODULE_ICON = 'ri-settings-3-line';

    private const MODULE_NUMBER = 2;

    private const SUBMODULE_TITLE = 'Recording Cadence';

    private const SUBMODULE_PATH = '/church/settings/demographics-settings/recording-cadence.php';

    private const PERMISSION_PREFIX = 'church.settings.demographicssettings.recordingcadence';

    private const ACTIONS = ['read', 'update'];

    private const ENTRY_ROLES = ['Senior Pastor', 'Associate Pastor', 'Church Secretary', 'Church Administrator'];

    public function run(): void
    {
        $this->command->info('➕ ADDING RECORDING CADENCE SUBMODULE (Church Settings > Demographics Settings)');
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
                'description' => 'Church-level configuration for the Demographics & Growth module.',
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
                'description' => 'Choose how often this church records demographics: monthly, half-yearly, or yearly.',
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
        $this->command->info('✅ Recording Cadence submodule ready.');
    }
}
