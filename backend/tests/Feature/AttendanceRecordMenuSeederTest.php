<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Submodule;
use Database\Seeders\AttendanceRecordMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The record pages in the church menu: Record Attendance for whoever can
 * record attendance, and Record Demographics back for the pastors.
 */
class AttendanceRecordMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_attendance_goes_to_those_who_can_record_and_demographics_entry_comes_back(): void
    {
        $attendance = Module::create(['name' => 'Attendance', 'icon' => 'ri-calendar-check-line', 'number' => 6, 'is_active' => true]);
        $reports = Submodule::create(['module_id' => $attendance->id, 'title' => 'Attendance Reports', 'path' => '/church/attendance/reports.php', 'is_active' => true]);
        $services = Submodule::create(['module_id' => $attendance->id, 'title' => 'Service Attendance', 'path' => '/church/attendance/services.php', 'is_active' => true]);
        $record = Permission::create(['name' => 'attendancemanagement.serviceattendance.create', 'guard_name' => 'web', 'module_id' => $attendance->id, 'submodule_id' => $services->id, 'action' => 'create', 'territory_scope' => 'church']);
        $read = Permission::create(['name' => 'attendancemanagement.attendancereports.read', 'guard_name' => 'web', 'module_id' => $attendance->id, 'submodule_id' => $reports->id, 'action' => 'read', 'territory_scope' => 'church']);

        $demographics = Module::create(['name' => 'Demographics & Growth', 'icon' => 'ri-line-chart-line', 'number' => 5, 'is_active' => true]);
        $tracking = new Submodule(['module_id' => $demographics->id, 'title' => 'Demographics Tracking', 'path' => '/church/demographics-growth/demographics-tracking.php', 'is_active' => true]);
        $tracking->id = 125;
        $tracking->save();
        $entry = Permission::create(['name' => 'churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create', 'guard_name' => 'web', 'module_id' => $demographics->id, 'submodule_id' => 125, 'action' => 'create', 'territory_scope' => 'church']);

        $pastor = Role::create(['name' => 'Senior Pastor', 'guard_name' => 'web']);
        $pastor->givePermissionTo($record); // can record attendance, has lost demographics entry
        $committee = Role::create(['name' => 'Church Committee Member', 'guard_name' => 'web']);
        $committee->givePermissionTo($read); // read only

        // The first version pointed the menu at record.php (one saved record).
        $old = Submodule::create(['module_id' => $attendance->id, 'title' => 'Record Attendance', 'path' => '/church/attendance/record.php', 'is_active' => true]);

        $this->seed(AttendanceRecordMenuSeeder::class);
        $this->seed(AttendanceRecordMenuSeeder::class); // twice: nothing doubles

        $page = Submodule::where('path', '/church/attendance/new.php')->sole();
        $this->assertSame($old->id, $page->id);
        $this->assertSame(0, Submodule::where('path', '/church/attendance/record.php')->count());
        $this->assertSame('Record Attendance', $page->title);
        $this->assertSame($page->id, Permission::where('name', 'attendancemanagement.recordattendance.create')->value('submodule_id'));

        $this->assertTrue($pastor->fresh()->hasPermissionTo('attendancemanagement.recordattendance.create'));
        $this->assertFalse($committee->fresh()->hasPermissionTo('attendancemanagement.recordattendance.create'));

        $this->assertSame('Record Demographics', $tracking->fresh()->title);
        $this->assertTrue($pastor->fresh()->hasPermissionTo($entry->name));
    }
}
