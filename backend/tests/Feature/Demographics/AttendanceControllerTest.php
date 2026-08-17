<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;
    protected Church $otherChurch;
    protected User $pastor;
    protected Role $pastorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        $this->pastorRole = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        foreach ([
            'attendancemanagement.serviceattendance.create',
            'attendancemanagement.serviceattendance.update',
            'attendancemanagement.specialeventsattendance.create',
        ] as $permName) {
            $permission = Permission::create(['name' => $permName, 'guard_name' => 'web', 'action' => 'create', 'territory_scope' => 'church']);
            $this->pastorRole->givePermissionTo($permission);
        }

        $this->pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
        ]);
        $this->pastor->assignRole($this->pastorRole);

        UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $this->pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $this->pastor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }

    public function test_pastor_can_record_sunday_service_attendance_for_their_own_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16',
            'service_type' => 'sunday_service',
            'adults_count' => 40,
            'youth_count' => 15,
            'children_male_count' => 8,
            'children_female_count' => 7,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.territory_id', $this->myChurch->id)
            ->assertJsonPath('data.service_type', 'sunday_service');

        $this->assertDatabaseHas('church_attendance_records', [
            'territory_id' => $this->myChurch->id,
            'adults_count' => 40,
        ]);
    }

    public function test_special_event_requires_an_event_name(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-15',
            'service_type' => 'special_event',
            'adults_count' => 20,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('event_name');
    }

    public function test_pastor_cannot_record_attendance_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->otherChurch->id,
            'service_date' => '2026-08-16',
            'service_type' => 'sunday_service',
            'adults_count' => 40,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('church_attendance_records', ['territory_id' => $this->otherChurch->id]);
    }

    public function test_user_without_ministry_gathering_permission_gets_403(): void
    {
        Sanctum::actingAs($this->pastor);

        // pastorRole was only granted serviceattendance + specialeventsattendance,
        // not ministryattendance
        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16',
            'service_type' => 'ministry_gathering',
            'event_name' => "Women's Fellowship Meeting",
            'adults_count' => 20,
        ]);

        $response->assertStatus(403);
    }

    public function test_pastor_can_update_their_own_churchs_attendance_record(): void
    {
        Sanctum::actingAs($this->pastor);

        $record = ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16',
            'fiscal_year_id' => FiscalYear::first()->id, 'fiscal_month_id' => FiscalMonth::first()->id,
            'service_type' => 'sunday_service', 'adults_count' => 40,
        ]);

        $response = $this->putJson("/api/attendance/{$record->id}", ['adults_count' => 45]);

        $response->assertStatus(200)->assertJsonPath('data.adults_count', 45);
    }

    public function test_pastor_cannot_update_another_churchs_attendance_record(): void
    {
        Sanctum::actingAs($this->pastor);

        $record = ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id,
            'service_date' => '2026-08-16',
            'fiscal_year_id' => FiscalYear::first()->id, 'fiscal_month_id' => FiscalMonth::first()->id,
            'service_type' => 'sunday_service', 'adults_count' => 40,
        ]);

        $response = $this->putJson("/api/attendance/{$record->id}", ['adults_count' => 999]);

        $response->assertStatus(403);
    }

    public function test_index_lists_only_the_requested_churchs_records(): void
    {
        Sanctum::actingAs($this->pastor);

        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16', 'fiscal_year_id' => FiscalYear::first()->id,
            'fiscal_month_id' => FiscalMonth::first()->id, 'service_type' => 'sunday_service',
        ]);

        $response = $this->getJson('/api/attendance?territory_id=' . $this->myChurch->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
