<?php

namespace Tests\Feature\Financial;

use App\Models\BudgetLine;
use App\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deductions: a share sent up out of money in, worked out for you
 * (docs/specs/budgets-spec.md, phase 4).
 */
class BudgetDeductionsTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    private function deduction(array $overrides = []): int
    {
        return $this->postJson('/api/budget-settings/deductions', [
            'name' => 'Diocese share',
            'deduction_type' => 'percentage',
            'deduction_value' => 10,
            'basis' => 'all',
            'applies_to_level' => 'church',
            'new_line_name' => 'Diocese share',
            ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    private function line(string $name): BudgetLine
    {
        return BudgetLine::where('name', $name)->firstOrFail();
    }

    private function record($budget, BudgetLine $line, float $amount): void
    {
        $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => '2026-01-10', 'description' => 'Test'])->assertCreated();
    }

    public function test_the_diocese_share_is_worked_out_on_a_church_budget_and_tracked_as_due_sent_owed(): void
    {
        Sanctum::actingAs($this->bishop);
        $this->deduction();
        $share = $this->line('Diocese share');
        $this->assertNull($share->territory_id);
        $this->assertSame('church', $share->territory_scope);

        Sanctum::actingAs($this->pastor);
        $form = $this->getJson('/api/budgets/form?year=2026&month=1')->assertOk()->json('data');
        $this->assertSame('Diocese share', $form['deductions'][0]['name']);
        $this->assertSame('Standard', $form['deductions'][0]['set_by']); // neutral: the system's standard, not one level ordering another

        $january = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $this->churchLine]);
        $item = $january->budgetLineItems->firstWhere('budget_line_id', $share->id);
        $this->assertEquals(100, $item->budgeted_amount); // 10% of KES 1,000 planned in
        $this->assertNotNull($item->budget_deduction_id);

        $this->record($january, $this->incomeLine, 400);
        $this->record($january, $share, 30);
        $d = $this->getJson("/api/budgets/{$january->id}")->assertOk()->json('data.deductions.0');
        $this->assertEquals([100, 40, 30, 10], [$d['planned'], $d['due'], $d['sent'], $d['owed']]);
        $this->assertSame('10% of all money received', $d['rule']);

        $history = collect($this->getJson("/api/budgets/{$january->id}/history")->json('data'))->pluck('description');
        $this->assertContains('Estimated Diocese share from the plan: 10% of all money received (KES 1,000.00 planned) = KES 100.00', $history);

        // Locked for the church
        $row = collect($this->getJson('/api/budget-settings')->json('data.deductions'))->firstWhere('name', 'Diocese share');
        $this->assertFalse($row['editable']);
        $this->putJson("/api/budget-settings/deductions/{$row['id']}", ['is_active' => false])->assertForbidden();

        // ...and the Overview notices what's still owed
        $insights = collect($this->getJson('/api/budgets/dashboard?year=2026&month=1')->json('data.insights'))->pluck('title');
        $this->assertContains('KES 10.00 still owed in deductions', $insights);
    }

    public function test_the_diocese_share_is_ten_percent_of_the_tithes_actually_recorded(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/budget-settings/lines', ['name' => 'Offerings', 'side' => 'in'])->assertCreated();
        $offerings = $this->line('Offerings');

        Sanctum::actingAs($this->bishop);
        $this->deduction(['basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id]]); // 10% of Tithes

        Sanctum::actingAs($this->pastor);
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $offerings]); // plans 1,000 each

        $d = $this->getJson("/api/budgets/{$budget->id}")->json('data.deductions.0');
        $this->assertSame('10% of Tithes received', $d['rule']);
        $this->assertSame([$this->incomeLine->id], $d['basis_line_ids']);
        $this->assertEquals([100, 0], [$d['planned'], $d['due']]); // an estimate from the plan; nothing recorded yet

        $this->record($budget, $offerings, 5000); // offerings don't count
        $this->assertEquals(0, $this->getJson("/api/budgets/{$budget->id}")->json('data.deductions.0.due'));

        $this->record($budget, $this->incomeLine, 400); // tithes recorded
        $d = $this->getJson("/api/budgets/{$budget->id}")->json('data.deductions.0');
        $this->assertEquals([40, 0, 40], [$d['due'], $d['sent'], $d['owed']]);
    }

    public function test_a_new_deduction_reaches_budgets_already_in_use_but_not_closed_ones(): void
    {
        Sanctum::actingAs($this->pastor);
        $inUse = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine], 2026, 1); // before any deduction
        $closed = $this->budgetFor($this->myChurch, 'closed', [$this->incomeLine], 2026, 2);
        $this->record($inUse, $this->incomeLine, 400);
        $this->assertSame([], $this->getJson("/api/budgets/{$inUse->id}")->json('data.deductions'));

        Sanctum::actingAs($this->bishop);
        $id = $this->deduction(['basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id]]);

        Sanctum::actingAs($this->pastor);
        $d = $this->getJson("/api/budgets/{$inUse->id}")->assertOk()->json('data.deductions.0');
        $this->assertSame('Standard', $d['set_by']);
        $this->assertEquals([100, 40, 0, 40], [$d['planned'], $d['due'], $d['sent'], $d['owed']]); // shown at once, due on what was recorded
        $this->assertSame([], $this->getJson("/api/budgets/{$closed->id}")->json('data.deductions')); // closed stays frozen
        $this->assertContains('Added Diocese share: 10% of Tithes received (estimate from the plan KES 100.00)', collect($this->getJson("/api/budgets/{$inUse->id}/history")->json('data'))->pluck('description'));

        // Switched off: it leaves the open budgets at once.
        Sanctum::actingAs($this->bishop);
        $this->putJson("/api/budget-settings/deductions/{$id}", ['is_active' => false])->assertOk();
        Sanctum::actingAs($this->pastor);
        $this->assertSame([], $this->getJson("/api/budgets/{$inUse->id}")->json('data.deductions'));
    }

    public function test_a_church_deduction_on_some_lines_only(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/budget-settings/lines', ['name' => 'Harambee', 'side' => 'in'])->assertCreated();
        $this->deduction(['name' => 'Building fund', 'deduction_value' => 5, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'applies_to_level' => 'own', 'new_line_name' => 'Building fund']);

        $budget = $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine, $this->line('Harambee')]);

        $this->assertEquals(50, $budget->budgetLineItems->firstWhere('budget_line_id', $this->line('Building fund')->id)->budgeted_amount); // 5% of Tithes only
        $this->assertSame($this->myChurch->id, (int) $this->line('Building fund')->territory_id);
    }

    public function test_a_fixed_amount_is_per_month_and_twelve_times_for_a_year(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->deduction(['name' => 'Pension', 'deduction_type' => 'fixed_amount', 'deduction_value' => 200, 'applies_to_level' => 'own', 'new_line_name' => 'Pension']);
        $pension = $this->line('Pension');

        $month = $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine], 2026, 3);
        $year = $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine], 2027, null);

        $this->assertEquals(200, $month->budgetLineItems->firstWhere('budget_line_id', $pension->id)->budgeted_amount);
        $this->assertEquals(2400, $year->budgetLineItems->firstWhere('budget_line_id', $pension->id)->budgeted_amount);
    }

    public function test_a_switched_off_deduction_is_left_out_of_budgets_saved_after(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->deduction(['name' => 'Pension', 'deduction_type' => 'fixed_amount', 'deduction_value' => 200, 'applies_to_level' => 'own', 'new_line_name' => 'Pension']);
        $budget = $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine]);
        $this->assertCount(1, $this->getJson("/api/budgets/{$budget->id}")->json('data.deductions'));
        // A budget works it out, so it can't be deleted - only switched off.
        $this->deleteJson("/api/budget-settings/deductions/{$id}")->assertStatus(422);

        $this->putJson("/api/budget-settings/deductions/{$id}", ['is_active' => false])->assertOk();
        $this->putJson("/api/budgets/{$budget->id}", ['year' => 2026, 'month' => 1, 'lines' => [['budget_line_id' => $this->incomeLine->id, 'amount' => 1000]]])->assertOk();

        $this->assertSame([], $this->getJson("/api/budgets/{$budget->id}")->json('data.deductions'));
        $this->assertNull($budget->fresh()->budgetLineItems->firstWhere('budget_line_id', $this->line('Pension')->id));
        // No budget uses it any more, so now it can go.
        $this->deleteJson("/api/budget-settings/deductions/{$id}")->assertOk();
    }

    public function test_a_region_deduction_reaches_only_its_own_churches(): void
    {
        Sanctum::actingAs($this->overseer);
        $this->deduction(['name' => 'Region levy', 'deduction_value' => 2, 'applies_to_level' => 'church', 'new_line_name' => null, 'budget_line_id' => $this->churchLine->id]);

        Sanctum::actingAs($this->pastor);
        $mine = $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine]);
        $this->assertEquals(20, $mine->budgetLineItems->firstWhere('budget_line_id', $this->churchLine->id)->budgeted_amount);

        $far = $this->budgetFor($this->farChurch, 'draft', [$this->incomeLine]);
        $this->assertNull($far->budgetLineItems->firstWhere('budget_line_id', $this->churchLine->id));
    }

    public function test_a_region_cannot_create_lines_for_its_churches_and_a_church_cannot_set_deductions_for_others(): void
    {
        Sanctum::actingAs($this->overseer);
        $this->postJson('/api/budget-settings/deductions', ['name' => 'Levy', 'deduction_type' => 'percentage', 'deduction_value' => 5, 'basis' => 'all', 'applies_to_level' => 'church', 'new_line_name' => 'Levy'])->assertStatus(422);

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/budget-settings/deductions', ['name' => 'X', 'deduction_type' => 'percentage', 'deduction_value' => 5, 'basis' => 'all', 'applies_to_level' => 'church', 'new_line_name' => 'X'])->assertStatus(422);
        $this->postJson('/api/budget-settings/deductions', ['name' => 'X', 'deduction_type' => 'percentage', 'deduction_value' => 150, 'basis' => 'all', 'applies_to_level' => 'own', 'new_line_name' => 'X'])->assertStatus(422);
    }

    public function test_the_summary_report_lists_the_deductions(): void
    {
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        Sanctum::actingAs($this->bishop);
        $this->deduction();
        Sanctum::actingAs($this->pastor);
        $this->budgetFor($this->myChurch, 'active', [$this->incomeLine]);

        $d = $this->postJson('/api/reports/preview', ['report_key' => 'budget.summary', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $year->id, 'month' => 1])->assertOk()->json('data');

        $section = collect($d['sections'])->firstWhere('heading', 'Deductions');
        $this->assertSame(['Diocese share', '10% of all money received', 'Diocese share', '0.00', '0.00', '0.00', '100.00'], $section['rows'][0]);
    }
}
