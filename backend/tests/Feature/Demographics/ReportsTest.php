<?php

namespace Tests\Feature\Demographics;

use App\Exports\Reports\InsightsSheet;
use App\Exports\ReportWorkbook;
use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Region;
use App\Models\ReportRun;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Reports\ReportColumn;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Pdf\DioceseReportPdf;
use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\ReportFacts;
use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\MembershipTrendRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;
use App\Support\Reports\Insights\Rules\SundaySchoolTeachersRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The acceptance criteria in docs/specs/reports-spec.md: catalogue per
 * level, opt-in totals, insights only when there are any, automatic
 * orientation, queued generation with fingerprint + verification code,
 * private runs, and the public verify endpoint.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected Region $region;

    protected User $pastor;

    protected User $otherPastor;

    protected FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->region = Region::create(['name' => 'Test Region', 'code' => 'TEST-RG', 'territory_type' => 'region', 'level' => 2]);
        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id, 'metadata' => ['demographics_mode' => 'monthly']]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        $this->year = FiscalYear::create(['year' => 2025, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $months = collect(range(1, 12))->map(fn ($n) => FiscalMonth::create([
            'number' => $n, 'name' => date('F', mktime(0, 0, 0, $n, 1)), 'short_name' => date('M', mktime(0, 0, 0, $n, 1)),
        ]));

        foreach ([[1, 600, 4, 1], [2, 610, 6, 2], [3, 580, 1, 9]] as [$month, $total, $new, $out]) {
            ChurchDemographic::create([
                'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
                'fiscal_year_id' => $this->year->id, 'fiscal_month_id' => $months[$month - 1]->id,
                'total_members' => $total, 'male_count' => 200, 'female_count' => $total - 200, 'youth_count' => 90,
                'womens_fellowship_count' => 120, 'mens_fellowship_count' => 60,
                'sunday_school_male_count' => 60, 'sunday_school_female_count' => 70, 'sunday_school_teachers_count' => 4,
                'seniors_count' => 40, 'new_members_count' => $new, 'transferred_out_count' => $out,
                'baptisms_count' => 2, 'communion_participants_count' => 150, 'conversions_count' => 1,
                'status' => 'approved',
            ]);
        }

        $role = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);
        $this->pastor = $this->userAt($this->myChurch->id, $role, 'pastor');
        $this->otherPastor = $this->userAt($this->otherChurch->id, $role, 'other');
    }

    private function userAt(int $territoryId, Role $role, string $name): User
    {
        $user = User::create([
            'firstname' => ucfirst($name), 'lastname' => 'Pastor', 'username' => "{$name}.pastor",
            'email' => "{$name}.pastor@example.test", 'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true, 'effective_from' => now()->subDay(),
            'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'report_key' => 'demographics.summary',
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->year->id,
            'format' => 'pdf',
        ], $overrides);
    }

    // 1. Catalogue per level --------------------------------------------------

    public function test_catalogue_lists_the_five_demographics_reports_for_a_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $keys = collect($this->getJson('/api/reports/catalogue?territory_id='.$this->myChurch->id)->assertOk()->json('data'))->pluck('key');

        $this->assertEqualsCanonicalizing(
            ['demographics.summary', 'demographics.monthly', 'demographics.spiritual', 'demographics.growth', 'demographics.submission'],
            $keys->all(),
        );
    }

    public function test_catalogue_is_empty_for_a_level_no_report_supports_yet(): void
    {
        $role = Role::create(['name' => 'Test Overseer', 'guard_name' => 'web', 'territory_level' => 'region']);
        $overseer = $this->userAt($this->region->id, $role, 'overseer');
        Sanctum::actingAs($overseer);

        $this->getJson('/api/reports/catalogue?territory_id='.$this->region->id)->assertOk()->assertJsonPath('data', []);
    }

    // 2. Totals are opt-in ------------------------------------------------------

    public function test_a_section_has_totals_only_when_a_column_asks_for_them(): void
    {
        $rows = [['Jan', 10, 3], ['Feb', 12, null], ['Mar', 15, 4]];

        $none = new ReportSection('Plain', [ReportColumn::text('Period'), ReportColumn::number('A'), ReportColumn::number('B')], $rows);
        $this->assertNull($none->totals());

        $mixed = new ReportSection('Mixed', [ReportColumn::text('Period'), ReportColumn::number('Members', 'latest'), ReportColumn::number('Baptisms', 'sum')], $rows, null, 'Year total');
        $this->assertSame(['Year total', 15, 7], $mixed->totals());
    }

    public function test_preview_reports_which_sections_have_totals(): void
    {
        Sanctum::actingAs($this->pastor);

        $growth = $this->postJson('/api/reports/preview', $this->body(['report_key' => 'demographics.growth', 'years' => 'all']))->assertOk();
        foreach ($growth->json('data.sections') as $section) {
            $this->assertFalse($section['has_totals'], "{$section['heading']} should have no totals");
        }

        $summary = $this->postJson('/api/reports/preview', $this->body())->assertOk();
        $membership = collect($summary->json('data.sections'))->firstWhere('heading', 'Membership');
        $this->assertTrue($membership['has_totals']);
        $this->assertSame('580', $membership['totals'][1], 'Headcount total is the latest value, not the sum');
    }

    // 3. Insights ----------------------------------------------------------------

    public function test_insight_rules_read_the_facts(): void
    {
        $rows = [
            ['label' => 'Jan', 'total_members' => 600, 'new_members_count' => 1, 'transferred_out_count' => 6],
            ['label' => 'Feb', 'total_members' => 560, 'new_members_count' => 2, 'transferred_out_count' => 5, 'male_count' => 150, 'female_count' => 410,
                'sunday_school_male_count' => 40, 'sunday_school_female_count' => 50, 'sunday_school_teachers_count' => 2, 'communion_participants_count' => 80],
        ];
        $facts = new ReportFacts(['rows' => $rows, 'latest' => $rows[1], 'missing' => ['Mar', 'Apr', 'May'], 'period_noun' => 'month']);

        $this->assertSame(Insight::CONCERN, (new MembershipTrendRule)->evaluate($facts)->tone);
        $this->assertSame(Insight::CONCERN, (new DeparturesVsNewMembersRule)->evaluate($facts)->tone);
        $this->assertStringContainsString('Women are 73%', GenderBalanceRule::members()->evaluate($facts)->title);
        $this->assertStringContainsString('45 children per Sunday school teacher', (new SundaySchoolTeachersRule)->evaluate($facts)->title);
        $this->assertSame(Insight::WATCH, (new HolyCommunionRule)->evaluate($facts)->tone);
        $this->assertSame('3 months not reported', (new ReportingGapsRule)->evaluate($facts)->title);
        $this->assertNull((new ReportingGapsRule)->evaluate(new ReportFacts(['missing' => []])));
    }

    public function test_a_report_without_insights_has_no_insights_part(): void
    {
        $section = new ReportSection('Figures', [ReportColumn::text('Figure'), ReportColumn::number('Value')], [['Members', 10]]);
        $plain = new ReportData('Church demographics report', 'Plain', 'Fiscal year 2025', 'My Church', sections: [$section]);
        $withInsights = new ReportData('Church demographics report', 'With insights', 'Fiscal year 2025', 'My Church', sections: [$section],
            insights: [new Insight(Insight::WATCH, 'Something to watch', 'Detail.', 'Do something.')]);

        $this->assertNotContains(InsightsSheet::class, array_map('get_class', (new ReportWorkbook($plain))->sheets()));
        $this->assertContains(InsightsSheet::class, array_map('get_class', (new ReportWorkbook($withInsights))->sheets()));

        $text = function (ReportData $data) {
            $pdf = new DioceseReportPdf($data);
            $pdf->setCompression(false);

            return $pdf->build()->toPdfString();
        };
        $this->assertStringNotContainsString('What we noticed', $text($plain));
        $this->assertStringContainsString('What we noticed', $text($withInsights));
        $this->assertStringContainsString('Recommendations', $text($withInsights));
    }

    // 4. Orientation ------------------------------------------------------------

    public function test_orientation_is_portrait_for_a_narrow_table_and_landscape_for_a_wide_one(): void
    {
        $pdf = new DioceseReportPdf(new ReportData('k', 't', 'p', 's'));

        $narrow = [ReportColumn::text('Period'), ReportColumn::number('Members'), ReportColumn::number('Baptisms')];
        $this->assertSame('P', $pdf->chooseOrientation($narrow, [['January 2025', '600', '2']]));

        $wide = [ReportColumn::text('Period')];
        foreach (range(1, 16) as $i) {
            $wide[] = ReportColumn::number("Measurement {$i}");
        }
        $this->assertSame('L', $pdf->chooseOrientation($wide, [['January 2025', ...array_fill(0, 16, '12,345')]]));
    }

    // 5. Queued generation ------------------------------------------------------

    public function test_a_queued_pdf_is_generated_with_a_fingerprint_and_verification_code(): void
    {
        Sanctum::actingAs($this->pastor);

        $uuid = $this->postJson('/api/reports', $this->body())->assertStatus(202)->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(ReportRun::STATUS_READY, $run->status);
        $this->assertSame(100, $run->progress);
        $this->assertMatchesRegularExpression('/^MWD-DEM-[2-9A-Z]{4}-[2-9A-Z]{4}$/', $run->verification_code);
        $contents = Storage::disk('local')->get($run->file_path);
        $this->assertStringStartsWith('%PDF', $contents);
        $this->assertSame(hash('sha256', $contents), $run->file_hash);
        $this->assertSame(strlen($contents), $run->file_size);
    }

    public function test_an_excel_report_is_a_real_workbook(): void
    {
        Sanctum::actingAs($this->pastor);

        $uuid = $this->postJson('/api/reports', $this->body(['report_key' => 'demographics.monthly', 'format' => 'xlsx']))->assertStatus(202)->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(ReportRun::STATUS_READY, $run->status);
        $this->assertStringStartsWith('PK', Storage::disk('local')->get($run->file_path));
        $this->assertStringEndsWith('.xlsx', $run->file_name);
    }

    public function test_a_failing_build_marks_the_run_failed(): void
    {
        Sanctum::actingAs($this->pastor);
        $foreign = ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id, 'fiscal_year_id' => $this->year->id,
            'total_members' => 10, 'status' => 'approved',
        ]);

        $uuid = $this->postJson('/api/reports', $this->body(['report_key' => 'demographics.submission', 'demographic_id' => $foreign->id]))
            ->assertStatus(202)->json('data.uuid');

        $run = ReportRun::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ReportRun::STATUS_FAILED, $run->status);
        $this->assertSame('That submission does not belong to this church.', $run->error);
    }

    // 6. Access -----------------------------------------------------------------

    public function test_runs_are_private_to_their_owner(): void
    {
        Sanctum::actingAs($this->pastor);
        $uuid = $this->postJson('/api/reports', $this->body())->json('data.uuid');
        $this->get("/api/reports/runs/{$uuid}/download")->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/reports/runs/{$uuid}")->assertNotFound();
        $this->getJson("/api/reports/runs/{$uuid}/download")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/reports/runs')->json('data'));
    }

    public function test_another_churchs_user_cannot_preview_or_generate(): void
    {
        Sanctum::actingAs($this->otherPastor);

        $this->postJson('/api/reports/preview', $this->body())->assertForbidden();
        $this->postJson('/api/reports', $this->body())->assertForbidden();
        $this->assertSame(0, ReportRun::count());
    }

    public function test_bad_input_is_a_json_422_never_a_redirect(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/reports', $this->body(['format' => 'docx']))->assertStatus(422)->assertJsonPath('success', false);
        $this->postJson('/api/reports', $this->body(['report_key' => 'nope']))->assertStatus(422);
        $this->postJson('/api/reports', $this->body(['report_key' => 'demographics.submission']))->assertStatus(422);
    }

    // 7. Verify -----------------------------------------------------------------

    public function test_verify_confirms_a_genuine_report_without_any_figures(): void
    {
        Sanctum::actingAs($this->pastor);
        $uuid = $this->postJson('/api/reports', $this->body())->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();

        $data = $this->getJson('/api/reports/verify/'.strtolower($run->verification_code))->assertOk()->json('data');

        $this->assertTrue($data['genuine']);
        $this->assertSame('Demographics summary', $data['title']);
        $this->assertSame($run->file_hash, $data['file_hash']);
        $this->assertSame('Pastor Pastor', $data['generated_by']);
        $this->assertEqualsCanonicalizing(
            ['genuine', 'code', 'title', 'scope_label', 'period_label', 'format', 'generated_at', 'generated_by', 'file_hash'],
            array_keys($data),
            'Verify must never return report figures',
        );
    }

    public function test_verify_says_not_recognised_for_an_unknown_code_and_is_rate_limited(): void
    {
        $this->getJson('/api/reports/verify/MWD-DEM-XXXX-XXXX')->assertOk()->assertJsonPath('data.genuine', false);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/reports/verify/MWD-DEM-XXXX-XXXX');
        }
        $this->getJson('/api/reports/verify/MWD-DEM-XXXX-XXXX')->assertStatus(429);
    }
}
