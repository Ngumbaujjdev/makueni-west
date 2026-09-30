<?php

namespace Tests\Feature\Financial;

use App\Models\Role;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A church pastor prepares and submits only their own church's budgets;
 * approving, rejecting, activating and closing stay with the diocese.
 */
class ChurchBudgetAccessTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    public function test_a_pastor_lists_only_their_own_churchs_budgets_whatever_they_ask_for(): void
    {
        $mine = $this->budgetFor($this->myChurch);
        $this->budgetFor($this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/budgets?territory_type=church&territory_id='.$this->otherChurch->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('stats.total', 1);
    }

    public function test_a_budget_a_pastor_creates_always_lands_on_their_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/budgets', [
            'budget_type_id' => $this->type->id,
            'territory_type' => 'diocese',
            'territory_id' => $this->otherChurch->id,
            'name' => 'Our 2026 budget',
            'fiscal_year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'items' => [['budget_line_id' => $this->churchLine->id, 'budgeted_amount' => 5000]],
        ])->assertCreated()
            ->assertJsonPath('data.territory_type', 'church')
            ->assertJsonPath('data.territory_id', $this->myChurch->id)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_a_church_budget_cannot_use_another_churchs_own_line(): void
    {
        $theirs = $this->ownLine($this->otherChurch, 'Their Choir Robes');
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/budgets', [
            'budget_type_id' => $this->type->id,
            'territory_type' => 'church',
            'territory_id' => $this->myChurch->id,
            'name' => 'Sneaky',
            'fiscal_year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'items' => [['budget_line_id' => $theirs->id, 'budgeted_amount' => 5000]],
        ])->assertStatus(422);
    }

    public function test_a_pastor_edits_and_submits_their_own_draft(): void
    {
        $budget = $this->budgetFor($this->myChurch);
        Sanctum::actingAs($this->pastor);

        $this->putJson("/api/budgets/{$budget->id}", ['name' => 'Renamed'])->assertOk();
        $this->postJson("/api/budgets/{$budget->id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_a_pastor_adds_changes_and_removes_lines_on_their_draft(): void
    {
        $budget = $this->budgetFor($this->myChurch);
        $own = $this->ownLine($this->myChurch, 'Choir Robes');
        Sanctum::actingAs($this->pastor);

        $itemId = $this->postJson("/api/budgets/{$budget->id}/line-items", ['budget_line_id' => $own->id, 'budgeted_amount' => 2500])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/budgets/{$budget->id}/line-items/{$itemId}", ['budgeted_amount' => 3000])
            ->assertOk()->assertJsonPath('data.budgeted_amount', '3000.00');
        $this->deleteJson("/api/budgets/{$budget->id}/line-items/{$itemId}")->assertOk();
        $this->assertSoftDeleted('budget_line_items', ['id' => $itemId]);
    }

    public function test_a_submitted_budget_is_locked_for_the_church_while_the_diocese_reviews_it(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'under_review');
        Sanctum::actingAs($this->pastor);

        $this->putJson("/api/budgets/{$budget->id}", ['name' => 'Changed'])->assertStatus(400);
        $this->postJson("/api/budgets/{$budget->id}/line-items", ['budget_line_id' => $this->allLine->id, 'budgeted_amount' => 10])->assertStatus(400);
    }

    public function test_another_churchs_budget_is_off_limits(): void
    {
        $theirs = $this->budgetFor($this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $this->getJson("/api/budgets/{$theirs->id}")->assertForbidden();
        $this->putJson("/api/budgets/{$theirs->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->postJson("/api/budgets/{$theirs->id}/submit")->assertForbidden();
        $this->deleteJson("/api/budgets/{$theirs->id}")->assertForbidden();
        $item = $theirs->budgetLineItems()->first();
        $this->putJson("/api/budgets/line-items/{$item->id}", ['budgeted_amount' => 1])->assertForbidden();
    }

    public function test_a_pastor_cannot_approve_reject_activate_or_close(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'submitted');
        Sanctum::actingAs($this->pastor);

        $this->postJson("/api/budgets/{$budget->id}/approve")->assertForbidden();
        $this->postJson("/api/budgets/{$budget->id}/reject", ['reason' => 'No'])->assertForbidden();
        $this->postJson("/api/budgets/{$budget->id}/activate")->assertForbidden();
        $this->postJson("/api/budgets/{$budget->id}/close")->assertForbidden();
        $this->assertSame('submitted', $budget->fresh()->status);
    }

    public function test_the_diocese_approves_a_church_budget(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'submitted');
        Sanctum::actingAs($this->approver);

        $this->getJson('/api/budgets?territory_type=church')->assertOk()->assertJsonPath('data.0.id', $budget->id);
        $this->postJson("/api/budgets/{$budget->id}/approve", ['approval_notes' => 'Good'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_pastor_who_also_sits_on_the_diocese_council_acts_in_the_role_they_are_in(): void
    {
        // The pastor also holds the diocese approver role, at the diocese (a secondary assignment).
        $council = Role::where('name', 'Test Bishop')->first();
        $this->pastor->assignRole($council);
        $dioceseAssignment = UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id, 'territory_id' => $this->diocese->id, 'role_id' => $council->id,
            'assignment_type' => 'secondary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $this->pastor->id, 'assigned_at' => now()->subDay(),
        ]);
        $budget = $this->budgetFor($this->myChurch, 'submitted');
        $theirs = $this->budgetFor($this->otherChurch);
        Sanctum::actingAs($this->pastor);

        // As the pastor (primary): no approving, and no other church's budgets
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertForbidden();
        $this->getJson("/api/budgets/{$theirs->id}")->assertForbidden();

        // Acting in the diocese role
        $this->withHeader('X-Assignment-Id', (string) $dioceseAssignment->id)
            ->postJson("/api/budgets/{$budget->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_an_assignment_that_is_not_the_users_own_is_ignored(): void
    {
        $bishopAssignment = UserTerritoryAssignment::where('user_id', $this->approver->id)->first();
        $budget = $this->budgetFor($this->myChurch, 'submitted');
        Sanctum::actingAs($this->pastor);

        $this->withHeader('X-Assignment-Id', (string) $bishopAssignment->id)
            ->postJson("/api/budgets/{$budget->id}/approve")->assertForbidden();
    }

    public function test_reject_accepts_reason_and_the_pastor_can_resubmit(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'submitted');

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/budgets/{$budget->id}/reject", ['reason' => 'Too high'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Too high');

        Sanctum::actingAs($this->pastor);
        $this->postJson("/api/budgets/{$budget->id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.rejection_reason', null);
    }

    public function test_church_users_cannot_change_the_dioceses_shared_budget_settings(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/budget-types')->assertOk();
        $this->postJson('/api/budget-types', ['name' => 'Ours'])->assertForbidden();
        $this->putJson("/api/budget-categories/{$this->category->id}", ['name' => 'Renamed'])->assertForbidden();
    }
}
