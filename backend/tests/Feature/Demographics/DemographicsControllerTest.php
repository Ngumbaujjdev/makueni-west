<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalSemiAnnual;
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

    // ==========================================================================
    // RECORDING CADENCE (demographics_mode - monthly/half_yearly/yearly)
    // ==========================================================================

    public function test_demographics_mode_defaults_to_monthly_and_can_be_changed(): void
    {
        Sanctum::actingAs($this->pastor);

        $getResponse = $this->getJson("/api/churches/{$this->myChurch->id}/entry-mode");
        $getResponse->assertStatus(200)->assertJsonPath('data.demographics_mode', 'monthly');

        $putResponse = $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", [
            'demographics_mode' => 'half_yearly',
        ]);
        $putResponse->assertStatus(200)
            ->assertJsonPath('data.demographics_mode', 'half_yearly')
            ->assertJsonPath('data.attendance_mode', 'weekly_and_monthly'); // untouched by this call

        $getAgain = $this->getJson("/api/churches/{$this->myChurch->id}/entry-mode");
        $getAgain->assertStatus(200)->assertJsonPath('data.demographics_mode', 'half_yearly');
    }

    public function test_updating_entry_mode_requires_at_least_one_field(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", []);

        $response->assertStatus(422);
    }

    public function test_half_yearly_church_can_submit_with_a_fiscal_semi_annual(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", ['demographics_mode' => 'half_yearly']);

        // FiscalYear::create() auto-generates its own quarters/semi-annuals
        // (see FiscalYear::boot()) - no need to create one here.
        $h1 = FiscalSemiAnnual::where('fiscal_year_id', $this->fiscalYear->id)->where('number', 1)->firstOrFail();

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_semi_annual_id' => $h1->id,
            'total_members' => 100,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.fiscal_semi_annual_id', $h1->id);
        $this->assertDatabaseHas('church_demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_semi_annual_id' => $h1->id,
            'fiscal_month_id' => null,
        ]);
    }

    public function test_half_yearly_church_is_rejected_for_submitting_a_fiscal_month_instead(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", ['demographics_mode' => 'half_yearly']);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 100,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('church_demographics', ['territory_id' => $this->myChurch->id]);
    }

    public function test_yearly_church_can_submit_with_no_period_field_at_all(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", ['demographics_mode' => 'yearly']);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'total_members' => 100,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('church_demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_month_id' => null,
            'fiscal_semi_annual_id' => null,
        ]);
    }

    public function test_duplicate_submission_for_the_same_half_year_is_rejected(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", ['demographics_mode' => 'half_yearly']);

        // FiscalYear::create() auto-generates its own quarters/semi-annuals
        // (see FiscalYear::boot()) - no need to create one here.
        $h1 = FiscalSemiAnnual::where('fiscal_year_id', $this->fiscalYear->id)->where('number', 1)->firstOrFail();
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_semi_annual_id' => $h1->id,
        ]);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_semi_annual_id' => $h1->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_submission_for_the_same_year_is_rejected(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson("/api/churches/{$this->myChurch->id}/entry-mode", ['demographics_mode' => 'yearly']);

        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]);

        $response = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]);

        $response->assertStatus(422);
    }

    // ==========================================================================
    // LEADERSHIP & MINISTRY TEAM (sunday_school_teachers_count + clergy-summary)
    // ==========================================================================

    public function test_sunday_school_teachers_count_is_accepted_on_create_and_update(): void
    {
        Sanctum::actingAs($this->pastor);

        $createResponse = $this->postJson('/api/demographics', [
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'total_members' => 100,
            'sunday_school_teachers_count' => 4,
        ]);

        $createResponse->assertStatus(201)->assertJsonPath('data.sunday_school_teachers_count', 4);

        $demographicId = $createResponse->json('data.id');
        $updateResponse = $this->putJson("/api/demographics/{$demographicId}", ['sunday_school_teachers_count' => 6]);

        $updateResponse->assertStatus(200)->assertJsonPath('data.sunday_school_teachers_count', 6);
    }

    public function test_clergy_summary_returns_counts_scoped_to_one_church_only(): void
    {
        Sanctum::actingAs($this->pastor);

        $seniorPastorRole = Role::create(['name' => 'Senior Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);
        $associatePastorRole = Role::create(['name' => 'Associate Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        $this->assignClergy($this->myChurch, $seniorPastorRole);
        $this->assignClergy($this->myChurch, $associatePastorRole);
        $this->assignClergy($this->myChurch, $associatePastorRole);
        // A pastor on a different church must never leak into this church's count.
        $this->assignClergy($this->otherChurch, $seniorPastorRole);

        $response = $this->getJson("/api/churches/{$this->myChurch->id}/clergy-summary");

        $response->assertStatus(200)
            ->assertJsonPath('data.counts.Senior Pastor', 1)
            ->assertJsonPath('data.counts.Associate Pastor', 2)
            ->assertJsonPath('data.total', 3);
    }

    public function test_clergy_summary_returns_zero_for_a_church_with_no_assigned_pastors(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson("/api/churches/{$this->myChurch->id}/clergy-summary");

        $response->assertStatus(200)
            ->assertJsonPath('data.counts', [])
            ->assertJsonPath('data.total', 0);
    }

    public function test_clergy_summary_cannot_be_read_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson("/api/churches/{$this->otherChurch->id}/clergy-summary");

        $response->assertStatus(403);
    }

    private function assignClergy(Church $church, Role $role): void
    {
        $user = User::create([
            'firstname' => 'Clergy', 'lastname' => (string) \Illuminate\Support\Str::uuid(),
            'username' => 'clergy.'.\Illuminate\Support\Str::uuid(),
            'email' => \Illuminate\Support\Str::uuid().'@example.test', 'password' => bcrypt('password'),
        ]);

        UserTerritoryAssignment::create([
            'user_id' => $user->id,
            'territory_id' => $church->id,
            'role_id' => $role->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $user->id,
            'assigned_at' => now()->subDay(),
        ]);
    }
}
