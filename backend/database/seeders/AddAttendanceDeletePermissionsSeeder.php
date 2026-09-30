<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Lets people delete an attendance record entered by mistake (it's a soft
 * delete, with Undo). Adds `.delete` for Sunday services, ministry
 * gatherings and special events, granted to every role that can already
 * update that kind of record - the same people who fix its numbers.
 *
 * Idempotent - safe to re-run.
 */
class AddAttendanceDeletePermissionsSeeder extends Seeder
{
    /** permission prefix => the submodule's page path */
    private const SUBMODULES = [
        'attendancemanagement.serviceattendance' => '%church/attendance/services%',
        'attendancemanagement.ministryattendance' => '%church/attendance/ministries%',
        'attendancemanagement.specialeventsattendance' => '%church/attendance/events%',
    ];

    public function run(): void
    {
        $this->command->info('🗑️  ATTENDANCE DELETE PERMISSIONS');

        foreach (self::SUBMODULES as $prefix => $path) {
            $submodule = Submodule::where('path', 'like', $path)->first();
            if (! $submodule) {
                $this->command->error("   ❌ No submodule for {$prefix}");

                continue;
            }

            $permission = Permission::firstOrCreate(
                ['name' => "{$prefix}.delete", 'guard_name' => 'web'],
                ['module_id' => $submodule->module_id, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => 'delete', 'territory_scope' => 'church'],
            );
            $this->command->info("   ✅ {$permission->name}");

            Role::whereHas('permissions', fn ($q) => $q->where('name', "{$prefix}.update"))->get()
                ->each(function (Role $role) use ($permission) {
                    if (! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                        $this->command->info("      granted to {$role->name}");
                    }
                });
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done');
    }
}
