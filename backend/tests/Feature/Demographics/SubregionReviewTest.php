<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subregion;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubregionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Subregion $mySubregion;
    protected Subregion $otherSubregion;
    protected Church $myChurch;
    protected Church $otherSubregionChurch;
    protected Church $noSubregionChurch;
    protected FiscalYear $fiscalYear;
    protected FiscalMonth $fiscalMonth;
    protected User $overseer;
    protected Role $overseerRole;
    protected Role $pastorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mySubregion = Subregion::create(['name' => 'My Subregion', 'code' => 'MY-SR', 'territory_type' => 'subregion', 'level' => 3]);
        $this->otherSubregion = Subregion::create(['name' => 'Other Subregion', 'code' => 'OTHER-SR', 'territory_type' => 'subregion', 'level' => 3]);

        $this->myChurch = Church::create([
            'name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4,
            'parent_territory_id' => $this->mySubregion->id,
        ]);
        $this->otherSubregionChurch = Church::create([
            'name' => 'Other Subregion Church', 'code' => 'OTHER-SR-CH', 'territory_type' => 'church', 'level' => 4,
            'parent_territory_id' => $this->otherSubregion->id,
        ]);
        $this->noSubregionChurch = Church::create([
            'name' => 'No Subregion Church', 'code' => 'NO-SR-CH', 'territory_type' => 'church', 'level' => 4,
            'parent_territory_id' => null,
        ]);

        $this->fiscalYear = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->fiscalMonth = FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        $this->overseerRole = Role::create(['name' => 'Test Subregional Overseer', 'guard_name' => 'web', 'territory_level' => 'subregion']);
        foreach ([
            'subregiondemographicsreview.churchsubmissions.approve',
            'subregiondemographicsreview.churchsubmissions.flag',
            'subregiondemographicsreview.churchsubmissions.requestchanges',
        ] as $permName) {
            $permission = Permission::create(['name' => $permName, 'guard_name' => 'web', 'action' => 'approve', 'territory_scope' => 'subregion']);
            $this->overseerRole->givePermissionTo($permission);
        }

        $this->overseer = User::create([
            'firstname' => 'Test', 'lastname' => 'Overseer', 'username' => 'test.overseer',
            'email' => 'test.overseer@example.test', 'password' => bcrypt('password'),
        ]);
        $this->overseer->assignRole($this->overseerRole);

        UserTerritoryAssignment::create([
            'user_id' => $this->overseer->id,
            'territory_id' => $this->mySubregion->id,
            'role_id' => $this->overseerRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $this->overseer->id,
            'assigned_at' => now()->subDay(),
        ]);

        $this->pastorRole = Role::create(['name' => 'Test Pastor Reviewee', 'guard_name' => 'web', 'territory_level' => 'church']);
    }

    private function submittedDemographic(Church $church): ChurchDemographic
    {
        return ChurchDemographic::create([
            'territory_type' => 'church',
            'territory_id' => $church->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->fiscalMonth->id,
            'status' => 'submitted',
            'total_members' => 100,
            'youth_count' => 20,
        ]);
    }

    public function test_overseer_can_approve_a_submission_from_their_own_subregion(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->myChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/approve");

        $response->assertStatus(200)->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('church_demographics', [
            'id' => $demographic->id, 'status' => 'approved', 'reviewed_by' => $this->overseer->id,
        ]);
    }

    public function test_overseer_can_flag_a_submission(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->myChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/flag", [
            'notes' => 'Youth count up 40% - please confirm',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'flagged');
        $this->assertDatabaseHas('church_demographics', [
            'id' => $demographic->id, 'status' => 'flagged', 'review_notes' => 'Youth count up 40% - please confirm',
        ]);
    }

    public function test_overseer_can_request_changes_sending_it_back_to_draft_cycle(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->myChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/request-changes", [
            'notes' => 'Please recheck the Sunday school count',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'changes_requested');
    }

    public function test_overseer_cannot_review_a_church_outside_their_subregion(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->otherSubregionChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/approve");

        $response->assertStatus(403);
        $this->assertDatabaseHas('church_demographics', ['id' => $demographic->id, 'status' => 'submitted']);
    }

    public function test_overseer_cannot_review_a_church_with_no_subregion_parent(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->noSubregionChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/approve");

        $response->assertStatus(403);
    }

    public function test_church_user_cannot_approve_their_own_submission(): void
    {
        $pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor.reviewee',
            'email' => 'test.pastor.reviewee@example.test', 'password' => bcrypt('password'),
        ]);
        UserTerritoryAssignment::create([
            'user_id' => $pastor->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $this->pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $pastor->id,
            'assigned_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($pastor);

        $demographic = $this->submittedDemographic($this->myChurch);

        $response = $this->postJson("/api/demographics/{$demographic->id}/approve");

        $response->assertStatus(403);
    }

    public function test_cannot_re_review_an_already_approved_submission(): void
    {
        Sanctum::actingAs($this->overseer);

        $demographic = $this->submittedDemographic($this->myChurch);
        $demographic->update(['status' => 'approved']);

        $response = $this->postJson("/api/demographics/{$demographic->id}/approve");

        $response->assertStatus(422);
    }
}
