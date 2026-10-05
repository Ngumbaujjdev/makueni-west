<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The two "record" pages, easy to find in the church menu:
 *
 * - **Record Attendance** (church/attendance/record.php) joins the Attendance
 *   module. Its permission goes to every role that can already record any
 *   attendance (service, ministry or special event) - by permission, not by
 *   role name, so Role Management decides.
 * - **Record Demographics** is the menu name of Demographics Tracking, and
 *   the demographics entry grants are put back where a role lost them
 *   (Senior Pastor and Associate Pastor had, on 2026-10-05, so the page
 *   vanished from their menu).
 *
 * Idempotent - safe to re-run; it only adds.
 */
class AttendanceRecordMenuSeeder extends Seeder
{
    private const PERMISSION = 'attendancemanagement.recordattendance.create';

    private const GRANT_FROM = [
        'attendancemanagement.serviceattendance.create',
        'attendancemanagement.ministryattendance.create',
        'attendancemanagement.specialeventsattendance.create',
    ];

    public function run(): void
    {
        $this->command?->info('📝 RECORD PAGES IN THE CHURCH MENU');

        $moduleId = Submodule::where('path', 'like', '%church/attendance/reports%')->value('module_id');
        if (! $moduleId) {
            $this->command?->error('   ❌ Attendance module not found (no Attendance Reports submodule)');

            return;
        }

        $submodule = Submodule::updateOrCreate(
            ['module_id' => $moduleId, 'path' => '/church/attendance/record.php'],
            ['title' => 'Record Attendance', 'description' => 'Record a Sunday service, ministry gathering or special event', 'is_active' => true],
        );
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['module_id' => $moduleId, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => 'create', 'territory_scope' => 'church'],
        );
        $permission->forceFill(['module_id' => $moduleId, 'submodule_id' => $submodule->id])->save();

        $roles = Role::whereHas('permissions', fn ($q) => $q->whereIn('name', self::GRANT_FROM))->get();
        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $this->command?->info("   ✅ Record Attendance (ID {$submodule->id}) for {$roles->count()} role(s)");

        // Demographics Tracking is where demographics are recorded - say so in the menu.
        Submodule::where('path', '/church/demographics-growth/demographics-tracking.php')->update(['title' => 'Record Demographics']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->call(GrantDemographicsEntryPermissionsSeeder::class);
    }
}
