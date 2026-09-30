<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Draft → In use → Closed → Reopened, with no approval
 * (docs/specs/budgets-spec.md - acceptance criteria 4, 5).
 */
class BudgetStatusTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->pastor);
    }

    public function test_a_budget_is_started_closed_and_reopened_by_its_own_place(): void
    {
        $budget = $this->budgetFor($this->myChurch);

        $this->getJson("/api/budgets/{$budget->id}")->assertJsonPath('data.can.start', true)->assertJsonPath('data.can.delete', true);
        $this->postJson("/api/budgets/{$budget->id}/start")->assertOk()
            ->assertJsonPath('data.budget.status', 'active')->assertJsonPath('data.budget.status_label', 'In use')
            ->assertJsonPath('data.budget.started_by', 'Test Pastor')
            ->assertJsonPath('data.can.edit', true)->assertJsonPath('data.can.delete', false);
        $this->postJson("/api/budgets/{$budget->id}/close")->assertOk()
            ->assertJsonPath('data.budget.status', 'closed')->assertJsonPath('data.can.edit', false);
        $this->putJson("/api/budgets/{$budget->id}", ['year' => 2026, 'month' => 1, 'lines' => []])->assertStatus(422);
        $this->postJson("/api/budgets/{$budget->id}/reopen")->assertOk()->assertJsonPath('data.budget.status', 'active');

        $actions = collect($this->getJson("/api/budgets/{$budget->id}/history")->json('data'))->pluck('action')->all();
        $this->assertSame(['reopened', 'closed', 'started', 'created'], $actions);
    }

    public function test_saving_can_start_using_straight_away(): void
    {
        $this->postJson('/api/budgets', ['year' => 2026, 'month' => 8, 'start' => true, 'lines' => [['budget_line_id' => $this->churchLine->id, 'amount' => 100]]])
            ->assertCreated()->assertJsonPath('data.budget.status', 'active');
    }

    public function test_only_drafts_can_be_deleted_and_moves_must_make_sense(): void
    {
        $active = $this->budgetFor($this->myChurch, 'active', [], 2026, 2);
        $this->deleteJson("/api/budgets/{$active->id}")->assertStatus(422);
        $this->postJson("/api/budgets/{$active->id}/start")->assertStatus(422);
        $this->postJson("/api/budgets/{$active->id}/reopen")->assertStatus(422);

        $draft = $this->budgetFor($this->myChurch, 'draft', [], 2026, 3);
        $this->postJson("/api/budgets/{$draft->id}/close")->assertStatus(422);
        $this->deleteJson("/api/budgets/{$draft->id}")->assertOk();
        $this->assertSoftDeleted('budgets', ['id' => $draft->id]);

        // Its month is free again
        $this->postJson('/api/budgets', ['year' => 2026, 'month' => 3, 'lines' => []])->assertCreated();
    }

    public function test_there_is_no_approval_any_more(): void
    {
        $budget = $this->budgetFor($this->myChurch);

        foreach (['submit', 'approve', 'reject', 'activate', 'clone'] as $old) {
            $this->postJson("/api/budgets/{$budget->id}/{$old}")->assertNotFound();
        }
    }
}
