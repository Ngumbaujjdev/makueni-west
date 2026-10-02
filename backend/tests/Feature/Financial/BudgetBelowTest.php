<?php

namespace Tests\Feature\Financial;

use App\Models\FiscalYear;
use App\Reports\Budget\BudgetRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The budgets of the places below, read-only (docs/specs/budgets-spec.md,
 * phase 5): a region sees its churches, the diocese its churches and its
 * regions; the page and the budget.rollup report share BudgetRollup.
 */
class BudgetBelowTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
    }

    private function record($budget, $line, float $amount, string $date = '2026-01-10'): void
    {
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => $date, 'description' => 'Test'])->assertCreated();
    }

    private function below(array $query = []): array
    {
        return $this->getJson('/api/budgets/below?'.http_build_query(['year' => 2026, 'month' => 1, ...$query]))->assertOk()->json('data');
    }

    public function test_a_region_sees_only_its_own_churches_with_their_figures(): void
    {
        Sanctum::actingAs($this->pastor);
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $this->churchLine]);
        $this->record($budget, $this->incomeLine, 600);
        $this->record($budget, $this->churchLine, 1200); // over its plan of 1,000

        Sanctum::actingAs($this->overseer);
        $d = $this->below();

        $this->assertEqualsCanonicalizing(['My Church', 'Other Church'], array_column($d['rows'], 'name'));
        $mine = collect($d['rows'])->firstWhere('name', 'My Church');
        $this->assertSame('active', $mine['status']);
        $this->assertSame($budget->id, $mine['budget_id']);
        $this->assertEquals([1000, 600, 1000, 1200, -600, 200], [$mine['in_planned'], $mine['in_actual'], $mine['out_planned'], $mine['out_actual'], $mine['left'], $mine['over']]);
        $this->assertSame('none', collect($d['rows'])->firstWhere('name', 'Other Church')['status']);
        $this->assertSame(['places' => 2, 'with_budget' => 1, 'in_use' => 1, 'none' => 1, 'over' => 1], array_intersect_key($d['totals'], array_flip(['places', 'with_budget', 'in_use', 'none', 'over'])));

        $insights = array_column($d['insights'], 'title');
        $this->assertContains('1 church has no budget for January 2026', $insights);
        $this->assertContains('1 church is over plan', $insights);
    }

    public function test_the_diocese_sees_every_church_grouped_by_region_and_its_regions(): void
    {
        $this->budgetFor($this->farChurch, 'draft');
        $this->budgetFor($this->region, 'active');

        Sanctum::actingAs($this->bishop);
        $d = $this->below();
        $this->assertCount(3, $d['rows']);
        $this->assertSame('Region B', collect($d['rows'])->firstWhere('name', 'Far Church')['group']);
        $this->assertEqualsCanonicalizing(['Region A', 'Region B'], array_column($d['groups'], 'name'));
        $this->assertContains('1 church\'s budget is still a draft', array_column($d['insights'], 'title'));

        $regions = $this->below(['level' => 'region']);
        $this->assertEqualsCanonicalizing(['Region A', 'Region B'], array_column($regions['rows'], 'name'));
        $this->assertSame('active', collect($regions['rows'])->firstWhere('name', 'Region A')['status']);
    }

    public function test_a_year_budget_counts_a_twelfth_in_a_month_and_entries_by_their_date(): void
    {
        $year = $this->budgetFor($this->myChurch, 'active', [$this->churchLine], 2026, null); // plans 1,000 for the year
        $this->record($year, $this->churchLine, 50, '2026-03-05');

        Sanctum::actingAs($this->overseer);
        $march = collect($this->below(['month' => 3])['rows'])->firstWhere('name', 'My Church');
        $this->assertEquals(83.33, $march['out_planned']);
        $this->assertEquals(50, $march['out_actual']);
        $this->assertTrue($march['is_year']);
        $this->assertEquals(0, collect($this->below(['month' => 1])['rows'])->firstWhere('name', 'My Church')['out_actual']);
        $this->assertEquals(1000, collect($this->below(['month' => null])['rows'])->firstWhere('name', 'My Church')['out_planned']);
    }

    public function test_deductions_still_owed_roll_up_and_owed_for_says_who_it_is_owed_to(): void
    {
        Sanctum::actingAs($this->bishop);
        $this->postJson('/api/budget-settings/deductions', ['name' => 'Diocese share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'basis' => 'all', 'applies_to_level' => 'church', 'new_line_name' => 'Diocese share'])->assertCreated();
        Sanctum::actingAs($this->pastor);
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine]);
        $this->record($budget, $this->incomeLine, 400);

        Sanctum::actingAs($this->bishop);
        $d = $this->below();
        $this->assertEquals(['due' => 40, 'sent' => 0, 'owed' => 40], collect($d['rows'])->firstWhere('name', 'My Church')['deductions']);
        $this->assertEquals(40, $d['totals']['owed']);

        $owed = app(BudgetRollup::class)->owedFor($this->myChurch, 2026, 1);
        $this->assertSame('Diocese share', $owed[0]['name']);
        $this->assertSame(['type' => 'diocese', 'id' => $this->diocese->id, 'name' => 'Test Diocese'], $owed[0]['owed_to']);
        $this->assertEquals([100, 40, 0, 40], [$owed[0]['planned'], $owed[0]['due'], $owed[0]['sent'], $owed[0]['owed']]);
        $this->assertSame($budget->id, $owed[0]['budgets'][0]['budget_id']);
    }

    public function test_a_church_and_a_role_without_below_are_refused(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/budgets/below')->assertForbidden();

        $secretary = $this->userWithRole('rsec', 'Test Region Reader', 'region', $this->region->id, ['region.budgets.budgets.read']);
        Sanctum::actingAs($secretary);
        $this->getJson('/api/budgets/below')->assertForbidden();
    }

    public function test_the_rollup_report_builds_and_needs_below(): void
    {
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->budgetFor($this->myChurch, 'active');

        Sanctum::actingAs($this->overseer);
        $preview = $this->postJson('/api/reports/preview', ['report_key' => 'budget.rollup', 'territory_id' => $this->region->id, 'fiscal_year_id' => $year->id, 'month' => 1])->assertOk()->json('data');
        $this->assertContains('No budget yet', array_column($preview['sections'], 'heading'));
        $this->assertSame('My Church', collect($preview['sections'])->first()['rows'][0][0]);

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/reports/preview', ['report_key' => 'budget.rollup', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $year->id, 'month' => 1])->assertStatus(422);
    }
}
