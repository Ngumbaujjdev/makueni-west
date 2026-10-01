<?php

namespace Tests\Feature\Financial;

use App\Models\BudgetEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recording money in and out against a budget in use (docs/specs/budgets-spec.md, phase 2).
 */
class BudgetEntriesTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->pastor);
    }

    private function entry(array $extra = []): array
    {
        return ['budget_line_id' => $this->churchLine->id, 'amount' => 300, 'entry_date' => '2026-01-10', 'description' => 'Rent for January', 'method' => 'mpesa', ...$extra];
    }

    public function test_money_recorded_updates_the_line_the_budget_and_its_history(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine]);

        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))
            ->assertCreated()
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.line', 'Church Rent')
            ->assertJsonPath('line.left', 700);
        $this->postJson('/api/budget-entries', $this->entry(['budget_line_id' => $this->incomeLine->id, 'amount' => 2500, 'description' => 'Sunday offering']))
            ->assertCreated()->assertJsonPath('data.direction', 'in');

        $this->getJson("/api/budgets/{$budget->id}")->assertOk()
            ->assertJsonPath('data.budget.out_actual', 300)
            ->assertJsonPath('data.budget.in_actual', 2500)
            ->assertJsonPath('data.can.record', true);
        $history = collect($this->getJson("/api/budgets/{$budget->id}/history")->json('data'));
        $recorded = $history->where('action', 'entry_recorded')->pluck('description');
        $this->assertContains('Recorded KES 300.00 spent on Church Rent (Rent for January, 10 Jan)', $recorded);
        $this->assertContains('Recorded KES 2,500.00 received on Tithes (Sunday offering, 10 Jan)', $recorded);
    }

    public function test_the_budget_in_use_is_found_from_the_date(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [], 2026, 3);

        $this->postJson('/api/budget-entries', $this->entry(['entry_date' => '2026-03-05']))->assertCreated()
            ->assertJsonPath('data.budget_id', $budget->id);
        $this->postJson('/api/budget-entries', $this->entry(['entry_date' => '2026-04-05']))->assertStatus(422)
            ->assertJsonPath('message', "There's no budget in use for April 2026. Prepare one (or start using it) first.");
    }

    public function test_money_can_only_go_on_a_budget_in_use_and_inside_its_period(): void
    {
        $draft = $this->budgetFor($this->myChurch, 'draft', [], 2026, 1);
        $closed = $this->budgetFor($this->myChurch, 'closed', [], 2026, 2);
        $inUse = $this->budgetFor($this->myChurch, 'active', [], 2026, 3);

        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $draft->id]))->assertStatus(422)
            ->assertJsonPath('errors.budget_id.0', 'The January 2026 budget is still a draft. Start using it first.');
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $closed->id, 'entry_date' => '2026-02-03']))->assertStatus(422);
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $inUse->id, 'entry_date' => '2026-04-01']))->assertStatus(422)
            ->assertJsonPath('errors.entry_date.0', 'The date must be within March 2026.');
    }

    public function test_spending_on_a_line_the_budget_did_not_plan_adds_it_as_unplanned(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);

        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id, 'budget_line_id' => $this->allLine->id, 'amount' => 150]))->assertCreated();

        $this->assertDatabaseHas('budget_line_items', ['budget_id' => $budget->id, 'budget_line_id' => $this->allLine->id, 'is_unplanned' => true, 'budgeted_amount' => 0, 'actual_amount' => 150]);
        $this->assertEquals(150, $budget->fresh()->total_expense_actual);
        // Another church's own line can't be used
        $theirs = $this->ownLine($this->otherChurch, 'Their Bus');
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id, 'budget_line_id' => $theirs->id]))->assertStatus(422);
    }

    public function test_changing_removing_and_bringing_back_an_entry_keeps_the_totals_right(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->allLine]);
        $id = $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))->json('data.id');

        $this->putJson("/api/budget-entries/{$id}", ['amount' => 450, 'budget_line_id' => $this->allLine->id])->assertOk();
        $this->assertDatabaseHas('budget_line_items', ['budget_id' => $budget->id, 'budget_line_id' => $this->churchLine->id, 'actual_amount' => 0]);
        $this->assertDatabaseHas('budget_line_items', ['budget_id' => $budget->id, 'budget_line_id' => $this->allLine->id, 'actual_amount' => 450]);

        $this->deleteJson("/api/budget-entries/{$id}")->assertOk();
        $this->assertEquals(0, $budget->fresh()->total_expense_actual);
        $this->assertSoftDeleted('budget_entries', ['id' => $id]);

        $this->postJson("/api/budget-entries/{$id}/restore")->assertOk();
        $this->assertEquals(450, $budget->fresh()->total_expense_actual);
        $actions = collect($this->getJson("/api/budgets/{$budget->id}/history")->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('entry_changed') && $actions->contains('entry_removed') && $actions->contains('entry_restored'));
    }

    public function test_the_spending_list_gives_the_periods_entries_and_figures(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine]);
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))->assertCreated();
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id, 'budget_line_id' => $this->incomeLine->id, 'amount' => 900, 'description' => 'Offering']))->assertCreated();

        $this->getJson('/api/budget-entries?year=2026&month=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('stats.in', 900)
            ->assertJsonPath('stats.out', 300)
            ->assertJsonPath('stats.biggest_line', 'Church Rent')
            ->assertJsonPath('view_only', false)
            ->assertJsonPath('can_record', true);
        $this->getJson('/api/budget-entries?year=2026&month=2')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/budget-entries?direction=in&year=2026')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_level_above_can_look_but_not_record_and_nobody_sees_sideways(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);
        $id = $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))->json('data.id');

        Sanctum::actingAs($this->overseer);
        $this->getJson("/api/budget-entries?territory_id={$this->myChurch->id}&year=2026")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('view_only', true)->assertJsonPath('can_record', false);
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))->assertForbidden();
        $this->putJson("/api/budget-entries/{$id}", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/budget-entries/{$id}")->assertForbidden();

        $sibling = $this->userWithRole('other.pastor', 'Other Pastor', 'church', $this->otherChurch->id, ['church.budgets.budgets.read', 'church.budgets.spending.read']);
        Sanctum::actingAs($sibling);
        $this->getJson("/api/budget-entries?territory_id={$this->myChurch->id}")->assertForbidden();
        $this->deleteJson("/api/budget-entries/{$id}")->assertForbidden();
    }

    public function test_someone_without_the_record_permission_cannot_record(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);
        $reader = $this->userWithRole('reader', 'Test Reader', 'church', $this->myChurch->id, ['church.budgets.budgets.read', 'church.budgets.spending.read']);
        Sanctum::actingAs($reader);

        $this->getJson('/api/budget-entries')->assertOk()->assertJsonPath('can_record', false);
        $this->postJson('/api/budget-entries', $this->entry(['budget_id' => $budget->id]))->assertForbidden();
        $this->postJson('/api/budget-entries', $this->entry())->assertForbidden();
        $this->assertSame(0, BudgetEntry::count());
    }
}
