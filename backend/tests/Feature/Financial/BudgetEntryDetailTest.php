<?php

namespace Tests\Feature\Financial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An entry's page and a line's page, and when money moved on a whole-year
 * budget (docs/specs/budgets-spec.md, phase 2e).
 */
class BudgetEntryDetailTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->pastor);
    }

    private function record($budget, $line, float $amount, string $date, string $what = 'Test'): int
    {
        return $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => $date, 'description' => $what])
            ->assertCreated()->json('data.id');
    }

    public function test_an_entry_shows_what_it_did_to_its_line_and_its_own_history(): void
    {
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);
        $this->record($january, $this->churchLine, 300, '2026-01-05', 'Deposit');
        $id = $this->record($january, $this->churchLine, 500, '2026-01-10', 'Rent');
        $this->record($january, $this->churchLine, 100, '2026-01-20', 'Repairs');
        $this->putJson("/api/budget-entries/{$id}", ['amount' => 550])->assertOk();

        $d = $this->getJson("/api/budget-entries/{$id}")->assertOk()->json('data');

        $this->assertSame('Rent', $d['entry']['description']);
        $this->assertEquals(300, $d['line']['before']);
        $this->assertEquals(950, $d['line']['actual']);
        $this->assertSame(2, $d['others_count']);
        $this->assertSame(['entry_changed', 'entry_recorded'], collect($d['history'])->pluck('action')->all());
        $this->assertTrue($d['can']['change']);
        $this->assertFalse($d['view_only']);
    }

    public function test_an_entry_below_is_view_only_and_sideways_is_refused(): void
    {
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);
        $id = $this->record($january, $this->churchLine, 500, '2026-01-10');

        Sanctum::actingAs($this->overseer);
        $d = $this->getJson("/api/budget-entries/{$id}")->assertOk()->json('data');
        $this->assertTrue($d['view_only']);
        $this->assertFalse($d['can']['change']);

        $sideways = $this->userWithRole('farpastor', 'Far Pastor', 'church', $this->farChurch->id, ['church.budgets.budgets.read']);
        Sanctum::actingAs($sideways);
        $this->getJson("/api/budget-entries/{$id}")->assertForbidden();
    }

    public function test_a_year_budget_line_shows_money_in_the_months_it_was_recorded(): void
    {
        $year = $this->budgetFor($this->myChurch, 'active', [$this->churchLine], 2026, null);
        $this->record($year, $this->churchLine, 200, '2026-03-15');
        $this->record($year, $this->churchLine, 50, '2026-03-20');
        $this->record($year, $this->churchLine, 400, '2026-11-02');

        $d = $this->getJson("/api/budgets/{$year->id}/lines/{$this->churchLine->id}")->assertOk()->json('data');

        $this->assertSame('months', $d['chart']['kind']);
        $months = collect($d['chart']['points'])->keyBy('month');
        $this->assertEquals(250, $months[3]['out']);
        $this->assertEquals(400, $months[11]['out']);
        $this->assertEquals(0, $months[12]['out']);
        $this->assertEqualsWithDelta(1000 / 12, $months[1]['planned'], 0.01);
        $this->assertCount(3, $d['entries']);
        $this->assertEquals(650, $d['line']['actual']);
        $this->assertSame('2026-11-02', $d['line']['last_date']);

        // The budget's own page has the same months, and History rows name their entry.
        $budget = $this->getJson("/api/budgets/{$year->id}")->assertOk()->json('data');
        $this->assertEquals(250, collect($budget['months'])->firstWhere('month', 3)['out']);
        $history = $this->getJson("/api/budgets/{$year->id}/history")->assertOk()->json('data');
        $this->assertNotNull(collect($history)->firstWhere('action', 'entry_recorded')['entry_id']);
    }

    public function test_a_month_budget_line_runs_day_by_day(): void
    {
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine]);
        $this->record($january, $this->churchLine, 300, '2026-01-05');

        $d = $this->getJson("/api/budgets/{$january->id}/lines/{$this->churchLine->id}")->assertOk()->json('data');

        $this->assertSame('days', $d['chart']['kind']);
        $this->assertCount(31, $d['chart']['points']);
        $this->assertEquals(300, $d['chart']['points'][4]['actual']);
        $this->getJson("/api/budgets/{$january->id}/lines/999999")->assertNotFound();
    }
}
