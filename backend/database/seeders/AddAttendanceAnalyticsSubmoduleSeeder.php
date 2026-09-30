<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Submodule;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Adds the "Attendance Analytics" page (church/attendance/analytics.php) to
 * the Attendance module, so it shows in the sidebar and the secondary nav -
 * both are built from these rows, never hardcoded. The tabbed dashboard
 * that used to live on Attendance Reports moved here; Attendance Reports
 * becomes the PDF/Excel reports page (docs/specs/reports-spec.md).
 *
 * The read permission goes to every role that can already read Attendance
 * Reports - the same people who used that dashboard.
 *
 * Idempotent - safe to re-run.
 */
class AddAttendanceAnalyticsSubmoduleSeeder extends Seeder
{
    private const PERMISSION = 'attendancemanagement.attendanceanalytics.read';

    private const GRANT_FROM = 'attendancemanagement.attendancereports.read';

    public function run(): void
    {
        $this->command->info('📊 ATTENDANCE ANALYTICS SUBMODULE');

        // The Attendance module is the one holding Attendance Reports.
        $moduleId = Submodule::where('path', 'like', '%church/attendance/reports%')->value('module_id');
        if (! $moduleId) {
            $this->command->error('   ❌ Attendance module not found (no Attendance Reports submodule)');

            return;
        }

        $submodule = Submodule::firstOrCreate(
            ['module_id' => $moduleId, 'path' => '/church/attendance/analytics.php'],
            ['title' => 'Attendance Analytics', 'description' => 'Sunday trends, coverage, ministries, children and insights', 'is_active' => true],
        );
        $this->command->info("   ✅ Submodule: {$submodule->title} (ID: {$submodule->id})");

        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['module_id' => $moduleId, 'submodule_id' => $submodule->id, 'sub_submodule_id' => null, 'action' => 'read', 'territory_scope' => 'church'],
        );
        $this->command->info("   ✅ Permission: {$permission->name}");

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', self::GRANT_FROM))->get();
        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
                $this->command->info("   ✅ Granted to {$role->name}");
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('✅ Done');
    }
}
