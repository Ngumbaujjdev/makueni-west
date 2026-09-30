<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One page, one request: a month or a year, all the lines at once
 * (docs/specs/budgets-spec.md - acceptance criteria 2, 3, 9).
 */
class BudgetFormTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->pastor);
    }

    public function test_a_period_can_hold_only_one_budget(): void
    {
        $this->budgetFor($this->myChurch, 'draft', [], 2026, 3);

        $line = [['budget_line_id' => $this->churchLine->id, 'amount' => 100]];
        $this->postJson('/api/budgets', ['year' => 2026, 'month' => 3, 'lines' => $line])
            ->assertStatus(422)->assertJsonPath('errors.month.0', "There's already a budget for March 2026.");
        $this->postJson('/api/budgets', ['year' => 2026, 'month' => null, 'lines' => $line])
            ->assertStatus(422)->assertJsonPath('errors.month.0', 'There are already month budgets in 2026. Use those, or delete them first.');
        // Another church may use the same month
        $this->budgetFor($this->otherChurch, 'draft', [], 2026, 3);
        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_a_whole_year_budget_blocks_months_in_that_year(): void
    {
        $this->budgetFor($this->myChurch, 'draft', [], 2027, null);

        $this->postJson('/api/budgets', ['year' => 2027, 'month' => 5, 'lines' => []])
            ->assertStatus(422)->assertJsonPath('errors.month.0', "There's already a budget for the whole of 2027.");
    }

    public function test_the_form_offers_this_places_lines_and_last_budgets_amounts(): void
    {
        $own = $this->ownLine($this->myChurch, 'Choir Robes');
        $theirs = $this->ownLine($this->otherChurch, 'Their Bus');
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $own], 2026, 1);

        $form = $this->getJson('/api/budgets/form?year=2026&month=2')->assertOk()->json('data');

        $outIds = collect($form['lines']['out'])->pluck('id');
        $this->assertContains($own->id, $outIds);
        $this->assertContains($this->churchLine->id, $outIds);
        $this->assertNotContains($theirs->id, $outIds);
        $this->assertNotContains($this->dioceseLine->id, $outIds);
        $this->assertSame([$this->incomeLine->id], collect($form['lines']['in'])->pluck('id')->all());
        $this->assertSame($january->id, $form['copy']['budget_id']);
        $this->assertEquals(1000, $form['copy']['amounts'][$own->id]);
        $this->assertSame($january->id, $form['taken']['months'][1]);
    }

    public function test_saving_replaces_the_lines_and_writes_one_history_entry(): void
    {
        $budget = $this->budgetFor($this->myChurch, 'draft', [$this->churchLine, $this->allLine]);

        $this->putJson("/api/budgets/{$budget->id}", ['year' => 2026, 'month' => 1, 'lines' => [
            ['budget_line_id' => $this->churchLine->id, 'amount' => 6000],
            ['budget_line_id' => $this->allLine->id, 'amount' => 0],
            ['budget_line_id' => $this->incomeLine->id, 'amount' => 20000],
        ]])->assertOk()
            ->assertJsonPath('data.budget.out_planned', 6000)
            ->assertJsonPath('data.budget.in_planned', 20000)
            ->assertJsonCount(1, 'data.lines.out');

        $history = $this->getJson("/api/budgets/{$budget->id}/history")->assertOk()->json('data');
        $this->assertCount(2, $history); // prepared + this change
        $this->assertSame('updated', $history[0]['action']);
        $this->assertStringContainsString('Church Rent 1,000.00 → 6,000.00', $history[0]['description']);
        $this->assertStringContainsString('added Tithes 20,000.00', $history[0]['description']);
        $this->assertStringContainsString('removed Utilities', $history[0]['description']);
        $this->assertSame('Test Pastor', $history[0]['who']);
    }

    public function test_a_line_this_place_cannot_use_is_refused(): void
    {
        $theirs = $this->ownLine($this->otherChurch, 'Their Bus');

        $this->postJson('/api/budgets', ['year' => 2026, 'month' => 6, 'lines' => [['budget_line_id' => $theirs->id, 'amount' => 500]]])
            ->assertStatus(422);
    }

    public function test_someone_else_saving_in_between_is_caught(): void
    {
        $budget = $this->budgetFor($this->myChurch);

        $this->putJson("/api/budgets/{$budget->id}", ['year' => 2026, 'month' => 1, 'lines' => [], 'updated_at' => '2020-01-01T00:00:00Z'])
            ->assertStatus(409);
    }
}
