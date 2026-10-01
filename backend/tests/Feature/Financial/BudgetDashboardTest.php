<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The budget Overview: planned vs received/spent for a month or a year (docs/specs/budgets-spec.md, phase 2).
 */
class BudgetDashboardTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->pastor);
    }

    private function spend($budget, $line, float $amount, string $date = '2026-01-10'): void
    {
        $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => $date, 'description' => 'Test'])->assertCreated();
    }

    public function test_a_month_shows_planned_against_what_came_in_and_went_out(): void
    {
        $december = $this->budgetFor($this->myChurch, 'active', [$this->churchLine], 2025, 12);
        $this->spend($december, $this->churchLine, 200, '2025-12-15');
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine]);
        $this->spend($january, $this->churchLine, 1200);
        $this->spend($january, $this->incomeLine, 400);

        $d = $this->getJson('/api/budgets/dashboard?year=2026&month=1')->assertOk()->json('data');

        $this->assertSame('January 2026', $d['period']['label']);
        $this->assertSame('December 2025', $d['period']['previous_label']);
        $this->assertSame($january->id, $d['budget']['id']);
        $this->assertEquals(['in_planned' => 1000, 'in_actual' => 400, 'out_planned' => 1000, 'out_actual' => 1200, 'left_planned' => 0, 'left_actual' => -800, 'entries' => 2], $d['totals']);
        $this->assertEquals(200, $d['previous']['out_actual']);
        $rent = collect($d['lines']['out'])->firstWhere('name', 'Church Rent');
        $this->assertEquals(-200, $rent['left']);
        $this->assertEquals(120, $rent['pct']);
        $titles = collect($d['insights'])->pluck('title');
        $this->assertContains('Church Rent is over plan by KES 200.00', $titles);
        $this->assertContains('More has gone out than came in', $titles);
        $this->assertSame('days', $d['trend']['kind']);
        $this->assertCount(31, $d['trend']['points']);
        $this->assertCount(2, $d['recent']);
    }

    public function test_one_month_of_a_whole_year_budget_counts_a_twelfth_of_its_plan(): void
    {
        $this->budgetFor($this->myChurch, 'active', [$this->churchLine], 2027, null);

        $month = $this->getJson('/api/budgets/dashboard?year=2027&month=3')->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000 / 12, $month['totals']['out_planned'], 0.01);
        $this->assertTrue($month['budget']['is_year']);

        $year = $this->getJson('/api/budgets/dashboard?year=2027')->assertOk()->json('data');
        $this->assertEquals(1000, $year['totals']['out_planned']);
        $this->assertSame('months', $year['trend']['kind']);
        $this->assertCount(12, $year['trend']['points']);
    }

    public function test_no_budget_says_so_and_a_place_below_is_view_only(): void
    {
        $d = $this->getJson('/api/budgets/dashboard?year=2026&month=6')->assertOk()->json('data');
        $this->assertNull($d['budget']);
        $this->assertSame('No budget for June 2026', $d['insights'][0]['title']);
        $this->assertTrue($d['can_record']);

        Sanctum::actingAs($this->overseer);
        $this->getJson("/api/budgets/dashboard?territory_id={$this->myChurch->id}&year=2026&month=6")->assertOk()
            ->assertJsonPath('data.view_only', true)->assertJsonPath('data.can_record', false);
        $this->getJson("/api/budgets/dashboard?territory_id={$this->farChurch->id}")->assertForbidden();
    }
}
