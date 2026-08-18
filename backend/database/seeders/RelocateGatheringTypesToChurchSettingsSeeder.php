<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RelocateGatheringTypesToChurchSettingsSeeder extends Seeder
{
    /**
     * One-time cleanup for databases that already ran the first version of
     * AddGatheringTypesSubmoduleSeeder, which placed "Gathering Types"
     * under Module 28 (Attendance) with permission prefix
     * church.attendance.gatheringtypes - the user corrected this to belong
     * under the church-level Settings module group instead. That seeder
     * has since been fixed to create the submodule/permissions in the
     * right place directly (as "Attendance Settings" under the existing
     * church-settings ModuleGroup) - this seeder just removes the old,
     * now-superseded rows so there isn't a duplicate/dead "Gathering
     * Types" entry left sitting under Attendance.
     *
     * Idempotent - does nothing if the old rows are already gone.
     */
    private const OLD_SUBMODULE_PATH = '/church/attendance/gathering-types.php';

    private const OLD_PERMISSION_PREFIX = 'church.attendance.gatheringtypes';

    public function run(): void
    {
        $this->command->info('🧹 CLEANING UP OLD GATHERING TYPES SUBMODULE/PERMISSIONS UNDER ATTENDANCE');
        $this->command->info(str_repeat('=', 70));

        $oldSubmodule = Submodule::where('path', self::OLD_SUBMODULE_PATH)->first();

        if ($oldSubmodule) {
            $this->command->info('   Found old submodule (ID: '.$oldSubmodule->id.') - removing.');
            $oldSubmodule->delete();
        } else {
            $this->command->warn('   ⚠️  No old submodule found - nothing to remove.');
        }

        $oldPermissions = Permission::where('name', 'like', self::OLD_PERMISSION_PREFIX.'.%')->get();
        $removed = 0;

        foreach ($oldPermissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
            $removed++;
        }

        if ($removed > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->command->info("   ✅ Removed {$removed} old permission(s) and cleared the permission cache.");
        } else {
            $this->command->warn('   ⚠️  No old permissions found - nothing to remove.');
        }

        $this->command->info('');
        $this->command->info('✅ Cleanup done.');
    }
}
