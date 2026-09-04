<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceReportWidgetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected User $pastor;

    protected FiscalYear $fiscalYear;

    protected FiscalMonth $august;

    protected int $sundayServiceCategoryId;

    protected int $ministryGatheringCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen "today" so Sunday-coverage math (recorded vs. elapsed) is
        // deterministic regardless of when the suite actually runs - all 5
        // Sundays in August 2026 (2,9,16,23,30) are in the past relative
        // to this date.
        Carbon::setTestNow('2026-09-02');

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        $this->fiscalYear = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->august = FiscalMonth::create(['number' => 8, 'name' => 'August', 'short_name' => 'Aug']);

        $this->sundayServiceCategoryId = GatheringCategory::where('slug', 'sunday_service')->value('id');
        $this->ministryGatheringCategoryId = GatheringCategory::where('slug', 'ministry_gathering')->value('id');

        $pastorRole = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);

        $this->pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
        ]);
        $this->pastor->assignRole($pastorRole);

        UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id,
            'territory_id' => $this->myChurch->id,
            'role_id' => $pastorRole->id,
            'assignment_type' => 'primary',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'assigned_by' => $this->pastor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createSundayRecord(string $date, int $adults, int $youth, int $childM, int $childF): ChurchAttendanceRecord
    {
        return ChurchAttendanceRecord::create([
            'territory_type' => 'church',
            'territory_id' => $this->myChurch->id,
            'service_date' => $date,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => $adults,
            'youth_count' => $youth,
            'children_male_count' => $childM,
            'children_female_count' => $childF,
            'created_by' => $this->pastor->id,
            'updated_by' => $this->pastor->id,
        ]);
    }

    public function test_sunday_service_widgets_compute_coverage_average_and_highest_attended(): void
    {
        // 3 of the 5 Sundays in August 2026 (2, 9, 16, 23, 30) recorded - 9 and 23 left blank on purpose.
        $this->createSundayRecord('2026-08-02', 20, 5, 3, 2); // total 30
        $this->createSundayRecord('2026-08-16', 40, 15, 8, 7); // total 70 (highest)
        $this->createSundayRecord('2026-08-30', 25, 5, 5, 5); // total 40

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.coverage.recorded', 3)
            ->assertJsonPath('data.coverage.elapsed', 5)
            ->assertJsonPath('data.coverage.percentage', 60)
            ->assertJsonPath('data.chart.categories', ['2 Aug', '16 Aug', '30 Aug'])
            ->assertJsonPath('data.chart.series.0.data', [30, 70, 40]);

        $stats = $response->json('data.stats');
        $this->assertEquals('Sundays Recorded', $stats[0]['label']);
        $this->assertEquals(3, $stats[0]['value']);
        $this->assertEquals(47, $stats[2]['value']); // Average Attendance = round(140/3)
        $this->assertStringContainsString('70 on 16 Aug', $stats[3]['value']); // Highest Attended Sunday

        // No July fiscal month configured in this test, so there's no valid
        // previous period to compare against - trend stays null, not a
        // fabricated number. Coverage/Highest Attended Sunday never get a
        // trend at all (composite/text values, see the service's own doc).
        $this->assertNull($stats[0]['trend']);
        $this->assertNull($stats[1]['trend']);
        $this->assertNull($stats[2]['trend']);
        $this->assertNull($stats[3]['trend']);

        $this->assertStringContainsString("2 Sundays weren't recorded this month", $response->json('data.insights.0'));

        $statColumns = collect($response->json('data.stat_columns'))->keyBy('label');
        $this->assertEquals('Aug (140)', $statColumns['Best Month']['value']);
        $this->assertEquals('47', $statColumns['Weekly Average']['value']);
        $this->assertEquals('-', $statColumns['This Month vs. Last']['value']); // only one month in scope
    }

    public function test_sunday_stats_trend_compares_to_previous_fiscal_month(): void
    {
        $july = FiscalMonth::create(['number' => 7, 'name' => 'July', 'short_name' => 'Jul']);

        // July (previous period): 2 Sundays, total 50 (avg 25).
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-07-05', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $july->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 15, 'youth_count' => 3, 'children_male_count' => 1, 'children_female_count' => 1,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]); // total 20
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-07-12', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $july->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 20, 'youth_count' => 5, 'children_male_count' => 3, 'children_female_count' => 2,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]); // total 30

        // August (current period): 3 Sundays, total 140 (avg 47) - same fixture as the coverage test above.
        $this->createSundayRecord('2026-08-02', 20, 5, 3, 2); // total 30
        $this->createSundayRecord('2026-08-16', 40, 15, 8, 7); // total 70
        $this->createSundayRecord('2026-08-30', 25, 5, 5, 5); // total 40

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(200);
        $stats = $response->json('data.stats');

        // Sundays Recorded: 3 vs. 2 previous = +50%.
        $this->assertEquals('up', $stats[0]['trend']['direction']);
        $this->assertEquals(50.0, $stats[0]['trend']['percent']);
        $this->assertEquals('vs last month', $stats[0]['trend']['label']);

        // Average Attendance: 47 (rounded) vs. 25 previous = +88%.
        $this->assertEquals('up', $stats[2]['trend']['direction']);
        $this->assertEquals(88.0, $stats[2]['trend']['percent']);

        // Coverage and Highest Attended Sunday never get a trend badge (composite/text values).
        $this->assertNull($stats[1]['trend']);
        $this->assertNull($stats[3]['trend']);
    }

    public function test_combined_summary_trend_compares_to_previous_fiscal_year(): void
    {
        $previousYear = FiscalYear::create(['year' => 2025, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

        // 2025 (previous period): 1 gathering, total 20.
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2025-08-03', 'fiscal_year_id' => $previousYear->id, 'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 15, 'youth_count' => 3, 'children_male_count' => 1, 'children_female_count' => 1,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]); // total 20

        // 2026 (current period): 2 gatherings, total 60.
        $this->createSundayRecord('2026-08-02', 20, 5, 3, 2); // total 30
        $this->createSundayRecord('2026-08-16', 20, 5, 3, 2); // total 30

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(200);
        $stats = $response->json('data.stats');

        // Total Gatherings Recorded: 2 vs. 1 previous year = +100%.
        $this->assertEquals('Total Gatherings Recorded', $stats[0]['label']);
        $this->assertEquals('up', $stats[0]['trend']['direction']);
        $this->assertEquals(100.0, $stats[0]['trend']['percent']);
        $this->assertEquals('vs last year', $stats[0]['trend']['label']);

        // Peak Attendance: 30 vs. 20 previous year = +50%.
        $this->assertEquals('up', $stats[1]['trend']['direction']);
        $this->assertEquals(50.0, $stats[1]['trend']['percent']);

        // Most Active Category never gets a trend badge (text value).
        $this->assertNull($stats[3]['trend']);
    }

    public function test_sunday_trend_insight_compares_latest_month_to_the_years_average(): void
    {
        $march = FiscalMonth::create(['number' => 3, 'name' => 'March', 'short_name' => 'Mar']);

        // March: low attendance (avg 20). August: much higher (avg 70) - latest recorded month is August.
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-03-01', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $march->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
            'adults_count' => 15, 'youth_count' => 3, 'children_male_count' => 1, 'children_female_count' => 1,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]);
        $this->createSundayRecord('2026-08-02', 40, 15, 8, 7); // total 70

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'gathering_category_id' => $this->sundayServiceCategoryId,
        ]));

        $response->assertStatus(200);
        $insights = $response->json('data.insights');
        $this->assertCount(2, $insights); // coverage sentence + trend sentence
        $this->assertStringContainsString('Attendance in Aug is', $insights[1]);
        $this->assertStringContainsString('above your usual for this year', $insights[1]);
    }

    public function test_ministry_gathering_breakdown_includes_zero_record_types(): void
    {
        $kesha = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Kesha', 'slug' => 'kesha',
        ]);
        $tuesdayFellowship = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Tuesday Fellowship', 'slug' => 'tuesday-fellowship',
        ]);
        GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Crusade', 'slug' => 'crusade',
        ]);

        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-10', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId, 'gathering_type_id' => $kesha->id, 'event_name' => 'Kesha',
            'adults_count' => 20, 'youth_count' => 5, 'children_male_count' => 4, 'children_female_count' => 3,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]);

        foreach (['2026-08-04', '2026-08-11'] as $date) {
            ChurchAttendanceRecord::create([
                'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
                'service_date' => $date, 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
                'gathering_category_id' => $this->ministryGatheringCategoryId, 'gathering_type_id' => $tuesdayFellowship->id, 'event_name' => 'Tuesday Fellowship',
                'adults_count' => 15, 'youth_count' => 5, 'children_male_count' => 3, 'children_female_count' => 3,
                'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
            ]);
        }

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
        ]));

        $response->assertStatus(200);
        $breakdown = collect($response->json('data.breakdown'))->keyBy('name');

        $this->assertEquals(1, $breakdown['Kesha']['times_held']);
        $this->assertEquals(32, $breakdown['Kesha']['total_attendance']);

        $this->assertEquals(2, $breakdown['Tuesday Fellowship']['times_held']);
        $this->assertEquals(52, $breakdown['Tuesday Fellowship']['total_attendance']);
        $this->assertEquals(26.0, $breakdown['Tuesday Fellowship']['average_attendance']);

        $this->assertEquals(0, $breakdown['Crusade']['times_held']);
        $this->assertEquals(0, $breakdown['Crusade']['total_attendance']);
        $this->assertNull($breakdown['Crusade']['last_held']);

        $this->assertEquals('on_track', $breakdown['Kesha']['status']); // last held 23 days before "today"
        $this->assertEquals('on_track', $breakdown['Tuesday Fellowship']['status']);
        $this->assertEquals('never_held', $breakdown['Crusade']['status']);

        // Ranked by total attendance descending, like a "Top Selling Products" list.
        $ranked = collect($response->json('data.breakdown'))->pluck('name')->all();
        $this->assertEquals(['Tuesday Fellowship', 'Kesha', 'Crusade'], $ranked);

        $stats = $response->json('data.stats');
        $this->assertEquals('2 of 3', $stats[0]['value']); // Gathering Types Held
        $this->assertEquals('Average Attendance', $stats[2]['label']);
        $this->assertEquals(28, $stats[2]['value']); // Average per gathering = round((32 + 52) / 3)
        $this->assertStringContainsString('Tuesday Fellowship (2x)', $stats[3]['value']); // Most Active Type

        $this->assertEquals(
            ['1 gathering type not held this period: Crusade.'],
            $response->json('data.insights'),
        );
    }

    public function test_breakdown_status_flags_a_type_inactive_after_60_days(): void
    {
        $may = FiscalMonth::create(['number' => 5, 'name' => 'May', 'short_name' => 'May']);

        $kesha = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Kesha', 'slug' => 'kesha',
        ]);

        // "today" is frozen at 2026-09-02 - 2026-05-20 is 105 days earlier, well past the 60-day threshold.
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-05-20', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $may->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId, 'gathering_type_id' => $kesha->id, 'event_name' => 'Kesha',
            'adults_count' => 20, 'youth_count' => 5, 'children_male_count' => 4, 'children_female_count' => 3,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]);

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.breakdown.0.status', 'inactive')
            ->assertJsonPath('data.insights.0', "1 gathering type haven't met in over 60 days: Kesha.");
    }

    public function test_combined_summary_sums_across_all_categories(): void
    {
        $this->createSundayRecord('2026-08-02', 20, 5, 3, 2); // total 30

        $tuesdayFellowship = GatheringType::create([
            'gathering_category_id' => $this->ministryGatheringCategoryId,
            'territory_id' => $this->myChurch->id,
            'name' => 'Tuesday Fellowship', 'slug' => 'tuesday-fellowship',
        ]);
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'service_date' => '2026-08-04', 'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'gathering_category_id' => $this->ministryGatheringCategoryId, 'gathering_type_id' => $tuesdayFellowship->id, 'event_name' => 'Tuesday Fellowship',
            'adults_count' => 15, 'youth_count' => 5, 'children_male_count' => 3, 'children_female_count' => 3,
            'created_by' => $this->pastor->id, 'updated_by' => $this->pastor->id,
        ]);

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(200);
        $stats = $response->json('data.stats');
        $this->assertEquals(2, $stats[0]['value']); // Total Gatherings Recorded
        $this->assertEquals('Peak Attendance', $stats[1]['label']);
        $this->assertEquals(30, $stats[1]['value']); // max(30, 26) - a real single gathering's headcount, not a sum
        $this->assertEquals('Overall Average', $stats[2]['label']);
        $this->assertEquals(28, $stats[2]['value']); // round((30 + 26) / 2)
        $this->assertEquals('Most Active Category', $stats[3]['label']);
        $this->assertStringContainsString('Sunday Service (30)', $stats[3]['value']); // 30 > Ministry Gathering's 26
        $this->assertArrayNotHasKey('breakdown', $response->json('data'));
        $this->assertArrayNotHasKey('coverage', $response->json('data'));
        $this->assertArrayNotHasKey('insights', $response->json('data'));
    }

    public function test_pastor_cannot_view_widgets_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?'.http_build_query([
            'territory_id' => $this->otherChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(403);
    }

    public function test_missing_fiscal_year_id_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/attendance-reports/widgets?territory_id='.$this->myChurch->id);

        $response->assertStatus(422)->assertJsonValidationErrors('fiscal_year_id');
    }
}
