<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
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

    protected int $sundayServiceCategoryId;

    protected int $specialEventCategoryId;

    protected int $ministryGatheringCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        // Categories are seeded directly in the gathering_categories
        // migration, so they already exist once RefreshDatabase has migrated.
        $this->sundayServiceCategoryId = GatheringCategory::where('slug', 'sunday_service')->value('id');
        $this->specialEventCategoryId = GatheringCategory::where('slug', 'special_event')->value('id');
        $this->ministryGatheringCategoryId = GatheringCategory::where('slug', 'ministry_gathering')->value('id');

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
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 40,
            'youth_count' => 15,
            'children_male_count' => 8,
            'children_female_count' => 7,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.territory_id', $this->myChurch->id)
            ->assertJsonPath('data.gathering_category_id', $this->sundayServiceCategoryId);

        $this->assertDatabaseHas('church_attendance_records', [
            'territory_id' => $this->myChurch->id,
            'adults_count' => 40,
        ]);
    }

    public function test_counts_left_empty_are_saved_as_zero(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16',
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 11,
            'youth_count' => 2,
            'children_male_count' => null,
            'children_female_count' => null,
        ])->assertStatus(201)->assertJsonPath('data.children_male_count', 0);

        $record = ChurchAttendanceRecord::firstOrFail();
        $this->putJson("/api/attendance/{$record->id}", ['youth_count' => null])->assertOk()->assertJsonPath('data.youth_count', 0);
    }

    public function test_the_same_sunday_cannot_be_recorded_twice(): void
    {
        Sanctum::actingAs($this->pastor);

        $payload = [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-16',
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 40,
        ];

        $first = $this->postJson('/api/attendance', $payload)->assertStatus(201);

        $this->postJson('/api/attendance', [...$payload, 'adults_count' => 55])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_date')
            ->assertJsonPath('existing_id', $first->json('data.id'));

        $this->assertSame(1, ChurchAttendanceRecord::where('territory_id', $this->myChurch->id)->count());
    }

    public function test_special_event_requires_an_event_name_when_no_gathering_type_is_selected(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-15',
            'gathering_category_id' => $this->specialEventCategoryId,
            'adults_count' => 20,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('event_name');
    }

    public function test_selecting_a_configured_gathering_type_auto_fills_event_name(): void
    {
        Sanctum::actingAs($this->pastor);

        $gatheringType = GatheringType::create([
            'gathering_category_id' => $this->specialEventCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Baptism Service',
            'slug' => 'baptism-service',
        ]);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-15',
            'gathering_category_id' => $this->specialEventCategoryId,
            'gathering_type_id' => $gatheringType->id,
            'adults_count' => 20,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.event_name', 'Baptism Service');
    }

    public function test_a_gathering_type_from_another_church_is_rejected(): void
    {
        Sanctum::actingAs($this->pastor);

        $otherChurchType = GatheringType::create([
            'gathering_category_id' => $this->specialEventCategoryId,
            'territory_id' => $this->otherChurch->id,
            'name' => 'Baptism Service',
            'slug' => 'baptism-service',
        ]);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-15',
            'gathering_category_id' => $this->specialEventCategoryId,
            'gathering_type_id' => $otherChurchType->id,
            'adults_count' => 20,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('gathering_type_id');
    }

    public function test_pastor_cannot_record_attendance_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/attendance', [
            'territory_id' => $this->otherChurch->id,
            'service_date' => '2026-08-16',
            'gathering_category_id' => $this->sundayServiceCategoryId,
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
            'gathering_category_id' => $this->ministryGatheringCategoryId,
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
            'gathering_category_id' => $this->sundayServiceCategoryId, 'adults_count' => 40,
        ]);

        $response = $this->putJson("/api/attendance/{$record->id}", ['adults_count' => 45]);

        $response->assertStatus(200)->assertJsonPath('data.adults_count', 45);
    }

    private function sunday(string $date, int $adults = 40, ?int $churchId = null): ChurchAttendanceRecord
    {
        return ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $churchId ?? $this->myChurch->id,
            'service_date' => $date,
            'fiscal_year_id' => FiscalYear::first()->id, 'fiscal_month_id' => FiscalMonth::first()->id,
            'gathering_category_id' => $this->sundayServiceCategoryId, 'adults_count' => $adults,
        ]);
    }

    private function grantDelete(): void
    {
        $permission = Permission::create(['name' => 'attendancemanagement.serviceattendance.delete', 'guard_name' => 'web', 'action' => 'delete', 'territory_scope' => 'church']);
        $this->pastorRole->givePermissionTo($permission);
    }

    public function test_a_sunday_entered_on_the_wrong_date_can_be_moved(): void
    {
        Sanctum::actingAs($this->pastor);
        $record = $this->sunday('2026-08-16');

        $this->putJson("/api/attendance/{$record->id}", ['service_date' => '2026-08-09', 'adults_count' => 40])
            ->assertOk()
            ->assertJsonPath('data.adults_count', 40);

        $this->assertSame('2026-08-09', $record->fresh()->service_date->toDateString());
    }

    public function test_a_sunday_cannot_be_moved_off_a_sunday_or_onto_a_recorded_one(): void
    {
        Sanctum::actingAs($this->pastor);
        $taken = $this->sunday('2026-08-09');
        $record = $this->sunday('2026-08-16');

        $this->putJson("/api/attendance/{$record->id}", ['service_date' => '2026-08-15'])
            ->assertStatus(422)->assertJsonValidationErrors('service_date');
        $this->putJson("/api/attendance/{$record->id}", ['service_date' => '2026-08-09'])
            ->assertStatus(422)->assertJsonPath('existing_id', $taken->id);
        // No fiscal month is set up for September in this test.
        $this->putJson("/api/attendance/{$record->id}", ['service_date' => '2026-09-06'])
            ->assertStatus(422)->assertJsonValidationErrors('service_date');
        $this->assertSame('2026-08-16', $record->fresh()->service_date->toDateString());
    }

    public function test_deleting_needs_the_delete_permission(): void
    {
        Sanctum::actingAs($this->pastor);
        $record = $this->sunday('2026-08-16');

        $this->deleteJson("/api/attendance/{$record->id}")->assertForbidden();
        $this->assertNotSoftDeleted($record);
    }

    public function test_a_deleted_record_leaves_the_list_and_can_be_put_back(): void
    {
        $this->grantDelete();
        Sanctum::actingAs($this->pastor);
        $record = $this->sunday('2026-08-16');

        $this->deleteJson("/api/attendance/{$record->id}")->assertOk();
        $this->assertSoftDeleted($record);
        $this->assertCount(0, $this->getJson('/api/attendance?territory_id='.$this->myChurch->id)->json('data'));

        $this->postJson("/api/attendance/{$record->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($record);
    }

    public function test_a_delete_cannot_be_undone_once_that_sunday_is_recorded_again(): void
    {
        $this->grantDelete();
        Sanctum::actingAs($this->pastor);
        $record = $this->sunday('2026-08-16');
        $this->deleteJson("/api/attendance/{$record->id}")->assertOk();
        $again = $this->sunday('2026-08-16', 55);

        $this->postJson("/api/attendance/{$record->id}/restore")
            ->assertStatus(422)->assertJsonPath('existing_id', $again->id);
    }

    public function test_another_churchs_record_cannot_be_deleted(): void
    {
        $this->grantDelete();
        Sanctum::actingAs($this->pastor);
        $theirs = $this->sunday('2026-08-16', 40, $this->otherChurch->id);

        $this->deleteJson("/api/attendance/{$theirs->id}")->assertForbidden();
        $this->assertNotSoftDeleted($theirs);
    }

    public function test_pastor_cannot_update_another_churchs_attendance_record(): void
    {
        Sanctum::actingAs($this->pastor);

        $record = ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id,
            'service_date' => '2026-08-16',
            'fiscal_year_id' => FiscalYear::first()->id, 'fiscal_month_id' => FiscalMonth::first()->id,
            'gathering_category_id' => $this->sundayServiceCategoryId, 'adults_count' => 40,
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
            'fiscal_month_id' => FiscalMonth::first()->id, 'gathering_category_id' => $this->sundayServiceCategoryId,
        ]);

        $response = $this->getJson('/api/attendance?territory_id='.$this->myChurch->id);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
