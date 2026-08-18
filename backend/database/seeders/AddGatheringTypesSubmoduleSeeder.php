<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AddGatheringTypesSubmoduleSeeder extends Seeder
{
    /**
     * Adds the church-level "Gathering Types" config screen (see the
     * gathering-types-config plan) as a new submodule under Module 28
     * "Attendance", alongside Service/Ministry/Special Events Attendance.
     * Grants read/create/update to the same four roles that already do
     * Attendance entry (GrantDemographicsEntryPermissionsSeeder).
     *
     * Idempotent - safe to re-run.
     */
    private const MODULE_ID = 28;

    private const SUBMODULE_TITLE = 'Gathering Types';

    private const SUBMODULE_PATH = '/church/attendance/gathering-types.php';

    private const PERMISSION_PREFIX = 'church.attendance.gatheringtypes';

    private const ACTIONS = ['read', 'create', 'update'];

    private const ENTRY_ROLES = ['Senior Pastor', 'Associate Pastor', 'Church Secretary', 'Church Administrator'];

    public function run(): void
    {
        $this->command->info('➕ ADDING GATHERING TYPES SUBMODULE');
        $this->command->info(str_repeat('=', 70));

        $module = Module::find(self::MODULE_ID);

        if (! $module) {
            $this->command->error('   ❌ Module '.self::MODULE_ID.' (Attendance) not found - aborting');

            return;
        }

        $submodule = Submodule::where('module_id', self::MODULE_ID)
            ->where('title', self::SUBMODULE_TITLE)
            ->first();

        if ($submodule) {
            // Self-healing: the very first version of this seeder stored
            // the path without a leading slash (same bug class fixed
            // system-wide by FixDemographicsSubmodulePathSlashesSeeder) -
            // sidebar.php's formatPath() has a defensive fallback that
            // masks this in the UI, but the source data should still be
            // correct.
            if ($submodule->path !== self::SUBMODULE_PATH) {
                $submodule->path = self::SUBMODULE_PATH;
                $submodule->save();
                $this->command->info('   ✅ Corrected path (ID: '.$submodule->id.')');
            } else {
                $this->command->warn('   ⚠️  Submodule already exists (ID: '.$submodule->id.')');
            }
        } else {
            $submodule = Submodule::create([
                'module_id' => self::MODULE_ID,
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
                    'module_id' => self::MODULE_ID,
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
