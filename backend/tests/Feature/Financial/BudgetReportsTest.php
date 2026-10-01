<?php

namespace Tests\Feature\Financial;

use App\Models\FiscalYear;
use App\Models\ReportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Budget reports: summary, money in and out, and one budget's statement
 * (docs/specs/budgets-spec.md, Reports). Built from the same figures as the
 * Overview, money with 2 decimals, own place or a place below only.
 */
class BudgetReportsTest extends TestCase
{
    use BuildsBudgetWorld;
    use RefreshDatabase;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-20 10:00:00');
        Storage::fake('local');
        $this->buildBudgetWorld();
        $this->year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(array $overrides = []): array
    {
        return [
            'report_key' => 'budget.summary',
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->year->id,
            'month' => 1,
            'format' => 'pdf',
            ...$overrides,
        ];
    }

    private function record($budget, $line, float $amount, string $what = 'Test'): void
    {
        $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => '2026-01-10', 'description' => $what])->assertCreated();
    }

    private function januaryWithMoney()
    {
        Sanctum::actingAs($this->pastor);
        $january = $this->budgetFor($this->myChurch, 'active', [$this->churchLine, $this->incomeLine]);
        $this->record($january, $this->churchLine, 1200.5, 'Rent');
        $this->record($january, $this->incomeLine, 400.25, 'Sunday tithes');

        return $january;
    }

    public function test_the_catalogue_lists_the_budget_reports_for_people_who_can_export(): void
    {
        Sanctum::actingAs($this->pastor);
        $keys = collect($this->getJson("/api/reports/catalogue?territory_id={$this->myChurch->id}&module=budget")->assertOk()->json('data'))->pluck('key');

        $this->assertEqualsCanonicalizing(['budget.summary', 'budget.spending', 'budget.statement'], $keys->all());
    }

    public function test_the_summary_matches_the_overview_with_money_to_two_decimals(): void
    {
        $this->januaryWithMoney();

        $d = $this->postJson('/api/reports/preview', $this->body())->assertOk()->json('data');

        $this->assertSame('Church budget summary', $d['kicker']);
        $this->assertSame('January 2026', $d['period_label']);
        $this->assertSame('KES 1,200.50', collect($d['tiles'])->firstWhere('label', 'Spent')['value']);
        $out = collect($d['sections'])->firstWhere('heading', 'Money out by line');
        $this->assertSame(['Church Rent', '1,000.00', '1,200.50', '-200.50', '120%', 'Over by KES 200.50'], $out['rows'][0]);
        $in = collect($d['sections'])->firstWhere('heading', 'Money in by line');
        $this->assertSame('400.25', $in['totals'][2]);
        $this->assertContains('Church Rent is over plan by KES 200.50', collect($d['insights'])->pluck('title'));
    }

    public function test_a_year_summary_has_month_by_month(): void
    {
        $this->januaryWithMoney();

        $d = $this->postJson('/api/reports/preview', $this->body(['month' => null]))->assertOk()->json('data');

        $this->assertSame('Whole of 2026', $d['period_label']);
        $months = collect($d['sections'])->firstWhere('heading', 'Month by month');
        $this->assertSame(12, $months['row_count']);
    }

    public function test_money_in_and_out_lists_every_entry_with_totals(): void
    {
        $this->januaryWithMoney();

        $d = $this->postJson('/api/reports/preview', $this->body(['report_key' => 'budget.spending']))->assertOk()->json('data');

        $out = collect($d['sections'])->firstWhere('heading', 'Money out');
        $this->assertSame(1, $out['row_count']);
        $this->assertSame('Rent', $out['rows'][0][1]);
        $this->assertSame('1,200.50', $out['totals'][7]);
    }

    public function test_a_statement_is_about_one_budget_and_only_one_the_user_may_see(): void
    {
        $january = $this->januaryWithMoney();

        $d = $this->postJson('/api/reports/preview', $this->body(['report_key' => 'budget.statement', 'budget_id' => $january->id]))->assertOk()->json('data');
        $this->assertSame('January 2026 budget', $d['title']);
        $this->assertNotNull(collect($d['sections'])->firstWhere('heading', 'History'));

        // Another church's budget, asked for through that church: the pastor can't see it.
        $theirs = $this->budgetFor($this->otherChurch, 'active');
        $this->postJson('/api/reports/preview', $this->body(['report_key' => 'budget.statement', 'territory_id' => $this->otherChurch->id, 'budget_id' => $theirs->id]))->assertForbidden();
        // ...or slipped in under the pastor's own church.
        $this->postJson('/api/reports/preview', $this->body(['report_key' => 'budget.statement', 'budget_id' => $theirs->id]))->assertStatus(422);
    }

    public function test_a_place_below_can_be_exported_but_never_upwards_or_sideways(): void
    {
        $this->januaryWithMoney();

        Sanctum::actingAs($this->overseer);
        $this->postJson('/api/reports/preview', $this->body())->assertOk();
        $this->postJson('/api/reports/preview', $this->body(['territory_id' => $this->farChurch->id]))->assertForbidden();

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/reports/preview', $this->body(['territory_id' => $this->region->id]))->assertForbidden();
    }

    public function test_a_role_without_export_gets_no_budget_reports(): void
    {
        $secretary = $this->userWithRole('secretary', 'Test Secretary', 'church', $this->myChurch->id, ['church.budgets.budgets.read']);
        Sanctum::actingAs($secretary);

        $this->assertSame([], $this->getJson("/api/reports/catalogue?territory_id={$this->myChurch->id}&module=budget")->assertOk()->json('data'));
        $this->postJson('/api/reports/preview', $this->body())->assertForbidden();
    }

    public function test_all_time_is_refused(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/reports/preview', $this->body(['fiscal_year_id' => 'all']))->assertStatus(422);
    }

    public function test_the_pdf_and_excel_build_with_a_budget_code_and_numbers_kept_as_numbers(): void
    {
        $this->januaryWithMoney();

        $uuid = $this->postJson('/api/reports', $this->body())->assertStatus(202)->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ReportRun::STATUS_READY, $run->status);
        $this->assertMatchesRegularExpression('/^MWD-BUD-[2-9A-Z]{4}-[2-9A-Z]{4}$/', $run->verification_code);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($run->file_path));

        $uuid = $this->postJson('/api/reports', $this->body(['report_key' => 'budget.spending', 'format' => 'xlsx']))->assertStatus(202)->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ReportRun::STATUS_READY, $run->status);
        $path = Storage::disk('local')->path($run->file_path);
        $sheet = IOFactory::load($path)->getSheetByName('Money out');
        $this->assertSame(1200.5, $sheet->getCell('H2')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
    }
}
