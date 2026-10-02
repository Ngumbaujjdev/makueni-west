<?php

namespace Tests\Feature\Financial;

use App\Models\BudgetLine;
use App\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributions (docs/specs/budgets-spec.md → Finance follow-up): what a
 * place sends up - due on what was received, sent, still to send - with a
 * status per period; a region / the diocese sees its churches too.
 */
class BudgetContributionsTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->bishop);
        $this->postJson('/api/budget-settings/deductions', [
            'name' => 'Diocese share', 'deduction_type' => 'percentage', 'deduction_value' => 10,
            'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'applies_to_level' => 'church', 'new_line_name' => 'Diocese share',
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function month($church, int $month, float $tithes, float $sent)
    {
        $budget = $this->budgetFor($church, 'active', [$this->incomeLine], 2026, $month);
        $share = BudgetLine::where('name', 'Diocese share')->firstOrFail();
        $book = app(\App\Services\Budgets\BudgetBook::class);
        if ($tithes > 0) {
            $book->record($this->pastor, $budget, ['budget_line_id' => $this->incomeLine->id, 'amount' => $tithes, 'entry_date' => sprintf('2026-%02d-05', $month), 'description' => 'Tithes']);
        }
        if ($sent > 0) {
            $book->record($this->pastor, $budget, ['budget_line_id' => $share->id, 'amount' => $sent, 'entry_date' => sprintf('2026-%02d-06', $month), 'description' => 'Sent']);
        }

        return $budget;
    }

    public function test_a_church_sees_each_month_sent_late_still_to_send_or_nothing_due(): void
    {
        $this->month($this->myChurch, 1, 1000, 40);   // due 100, 60 left, month over → late
        $this->month($this->myChurch, 2, 500, 50);    // all sent
        $this->month($this->myChurch, 3, 0, 0);       // nothing received
        $this->month($this->myChurch, 6, 200, 0);     // this month, 20 still to send

        Sanctum::actingAs($this->pastor);
        $d = $this->getJson('/api/budgets/contributions?year=2026')->assertOk()->json('data');

        $this->assertSame(['late', 'sent', 'none', 'pending'], array_column($d['rows'], 'status'));
        $this->assertEquals([100, 40, 60], [$d['rows'][0]['due'], $d['rows'][0]['sent'], $d['rows'][0]['owed']]);
        $this->assertSame('Test Diocese', $d['rows'][0]['to']);
        $this->assertEquals(['received' => 1700, 'due' => 170, 'sent' => 90, 'owed' => 80, 'late' => 1, 'status' => 'late'], $d['totals']);
        $this->assertNull($d['below']);
        $this->assertTrue($d['can_record']);
    }

    public function test_a_region_sees_only_its_churches_and_the_diocese_sees_all(): void
    {
        $this->month($this->myChurch, 1, 1000, 100);
        $this->month($this->farChurch, 2, 300, 0);

        Sanctum::actingAs($this->overseer);
        $region = $this->getJson('/api/budgets/contributions?year=2026')->assertOk()->json('data.below');
        $this->assertEqualsCanonicalizing(['My Church', 'Other Church'], array_column($region, 'name'));
        $this->assertSame('sent', collect($region)->firstWhere('name', 'My Church')['status']);
        $this->assertSame('none', collect($region)->firstWhere('name', 'Other Church')['status']);

        Sanctum::actingAs($this->bishop);
        $diocese = collect($this->getJson('/api/budgets/contributions?year=2026')->json('data.below'));
        $this->assertCount(3, $diocese);
        $this->assertEquals(['late', 30, 1], [$diocese->firstWhere('name', 'Far Church')['status'], $diocese->firstWhere('name', 'Far Church')['owed'], $diocese->firstWhere('name', 'Far Church')['late']]);
    }

    public function test_view_only_below_and_never_upward(): void
    {
        $this->month($this->myChurch, 1, 1000, 0);

        Sanctum::actingAs($this->overseer);
        $d = $this->getJson("/api/budgets/contributions?year=2026&territory_id={$this->myChurch->id}")->assertOk()->json('data');
        $this->assertTrue($d['view_only']);
        $this->assertFalse($d['can_record']);
        $this->assertCount(1, $d['rows']);

        Sanctum::actingAs($this->pastor);
        $this->getJson("/api/budgets/contributions?territory_id={$this->region->id}")->assertForbidden();
        $this->getJson("/api/budgets/contributions?territory_id={$this->farChurch->id}")->assertForbidden();
    }

    public function test_the_contributions_report_builds_for_a_church_and_a_region(): void
    {
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->month($this->myChurch, 1, 1000, 40);

        Sanctum::actingAs($this->pastor);
        $church = $this->postJson('/api/reports/preview', ['report_key' => 'budget.contributions', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $year->id])->assertOk()->json('data');
        $this->assertSame('Month by month', $church['sections'][0]['heading']);
        $this->assertSame('Late', $church['sections'][0]['rows'][0][6]);

        Sanctum::actingAs($this->overseer);
        $region = $this->postJson('/api/reports/preview', ['report_key' => 'budget.contributions', 'territory_id' => $this->region->id, 'fiscal_year_id' => $year->id])->assertOk()->json('data');
        $this->assertContains('My Church', collect($region['sections'])->flatMap(fn ($s) => array_column($s['rows'], 0))->all());
    }
}
