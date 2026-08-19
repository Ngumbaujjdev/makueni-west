<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemographicsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected FiscalYear $fiscalYear;

    protected FiscalMonth $fiscalMonth;

    protected User $pastor;

    protected Role $pastorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create([
            'name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4,
        ]);
        $this->otherChurch = Church::create([
            'name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4,
        ]);

        $this->fiscalYear = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->fiscalMonth = FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        $this->pastorRole = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        foreach ([
            'churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create',
            'churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.update',
        ] as $permName) {
            $permission = Permission::create([
                'name' => $permName, 'guard_name' => 'web', 'action' => 'create', 'territory_scope' => 'church',
            ]);
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

    public function test_pastor_can_create_a_draft_for_their_own_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 100,
            'youth_count' => 30,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.territory_id', $this->myChurch->id);

        $this->assertDatabaseHas('church_demographics', [
            'territory_id' => $this->myChurch->id,
            'total_members' => 100,
        ]);
    }

    public function test_pastor_cannot_create_a_draft_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->otherChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 100,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('church_demographics', ['territory_id' => $this->otherChurch->id]);
    }

    public function test_user_without_permission_cannot_create_a_draft(): void
    {
        $unprivileged = User::create([
            'firstname' => 'No', 'lastname' => 'Permission', 'username' => 'no.permission',
            'email' => 'no.permission@example.test', 'password' => bcrypt('password'),
        ]);
        UserTerritoryAssignment::create([
            'user_id' => $unprivileged->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $this->pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $unprivileged->id,
            'assigned_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($unprivileged);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_duplicate_submission_for_the_same_month_is_rejected(): void
    {
        Sanctum::actingAs($this->pastor);

        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_out_of_range_counts_produce_a_soft_warning_not_a_rejection(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 50,
            'youth_count' => 70, // exceeds total_members
        ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertStringContainsString('Youth count', $response->json('warnings.0'));
    }

    public function test_total_members_far_from_this_churchs_average_produces_a_soft_warning(): void
    {
        Sanctum::actingAs($this->pastor);

        $priorMonth = FiscalMonth::create(['number' => 7, 'name' => 'July', 'short_name' => 'Jul']);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $priorMonth->id,
            'status' => 'approved', 'total_members' => 622,
        ]);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 6000,
        ]);

        $response->assertStatus(201);
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('unusually high', collect($warnings)->implode(' '));
        $this->assertStringContainsString('average: 622', collect($warnings)->implode(' '));
    }

    public function test_total_members_close_to_this_churchs_average_produces_no_warning(): void
    {
        Sanctum::actingAs($this->pastor);

        $priorMonth = FiscalMonth::create(['number' => 7, 'name' => 'July', 'short_name' => 'Jul']);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $priorMonth->id,
            'status' => 'approved', 'total_members' => 622,
        ]);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 640,
        ]);

        $response->assertStatus(201);
        $this->assertEmpty($response->json('warnings'));
    }

    public function test_pastor_can_update_and_submit_their_own_draft(): void
    {
        Sanctum::actingAs($this->pastor);

        $demographic = ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->fiscalMonth->id,
            'status' => 'draft', 'total_members' => 100,
        ]);

        $updateResponse = $this->putJson("/api/demographics/{$demographic->id}", ['total_members' => 120]);
        $updateResponse->assertStatus(200)->assertJsonPath('data.total_members', 120);

        $submitResponse = $this->postJson("/api/demographics/{$demographic->id}/submit");
        $submitResponse->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('church_demographics', ['id' => $demographic->id, 'status' => 'approved']);
    }

    public function test_submitting_auto_approves_so_it_counts_toward_rollups_immediately(): void
    {
        Sanctum::actingAs($this->pastor);

        $demographic = ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->fiscalMonth->id,
            'status' => 'draft', 'total_members' => 100,
        ]);

        $this->postJson("/api/demographics/{$demographic->id}/submit")->assertStatus(200);

        $demographic->refresh();
        $this->assertFalse($demographic->is_editable, 'An approved submission should be locked, same as before');
        $this->assertNotNull($demographic->submitted_at);
        $this->assertNull($demographic->reviewed_by, 'No human reviewer acted on it - it was auto-approved');
    }

    public function test_pastor_cannot_update_another_churchs_submission(): void
    {
        Sanctum::actingAs($this->pastor);

        $demographic = ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->fiscalMonth->id,
        ]);

        $response = $this->putJson("/api/demographics/{$demographic->id}", ['total_members' => 999]);

        $response->assertStatus(403);
    }

    public function test_a_submitted_record_cannot_be_edited(): void
    {
        Sanctum::actingAs($this->pastor);

        $demographic = ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->fiscalMonth->id,
            'status' => 'submitted',
        ]);

        $response = $this->putJson("/api/demographics/{$demographic->id}", ['total_members' => 999]);

        $response->assertStatus(422);
    }

    public function test_entry_mode_defaults_to_weekly_and_monthly_and_can_be_changed(): void
    {
        Sanctum::actingAs($this->pastor);

        $getResponse = $this->getJson("/api/churches/{$this->myChurch->id}/entry-mode");
        $getResponse->assertStatus(200)->assertJsonPath('data.attendance_mode', 'weekly_and_monthly');

        $putResponse = $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", [
            'attendance_mode' => 'monthly_only',
        ]);
        $putResponse->assertStatus(200)->assertJsonPath('data.attendance_mode', 'monthly_only');

        $getAgain = $this->getJson("/api/churches/{$this->myChurch->id}/entry-mode");
        $getAgain->assertStatus(200)->assertJsonPath('data.attendance_mode', 'monthly_only');
    }

    public function test_entry_mode_cannot_be_read_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson("/api/churches/{$this->otherChurch->id}/entry-mode");

        $response->assertStatus(403);
    }
}
