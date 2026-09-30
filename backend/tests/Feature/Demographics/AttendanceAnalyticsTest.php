<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
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

/**
 * GET /attendance-reports/analytics (AttendanceData) - the Attendance
 * Analytics page. "Today" is fixed at Tue 31 Mar 2026: March has five
 * Sundays (1, 8, 15, 22, 29), three of them recorded.
 */
class AttendanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected Church $otherChurch;

    protected User $pastor;

    protected FiscalYear $year;

    protected array $months;

    protected array $categories;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-31 10:00:00');

        $this->church = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        foreach (range(1, 12) as $n) {
            $this->months[$n] = FiscalMonth::create(['number' => $n, 'name' => date('F', mktime(0, 0, 0, $n, 1)), 'short_name' => date('M', mktime(0, 0, 0, $n, 1))]);
        }
        $this->categories = GatheringCategory::pluck('id', 'slug')->all();

        $role = Role::create(['name' => 'Test Pastor', 'guard_name' => 'web', 'territory_level' => 'church']);
        $this->pastor = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
        ]);
        $this->pastor->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id, 'territory_id' => $this->church->id, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true, 'effective_from' => now()->subYear(),
            'assigned_by' => $this->pastor->id, 'assigned_at' => now()->subYear(),
        ]);

        // Sundays: one in February, three of March's five.
        $this->sunday('2026-02-22', 60, 20, 10, 10);   // 100
        $this->sunday('2026-03-01', 60, 20, 10, 10);   // 100
        $this->sunday('2026-03-08', 70, 25, 10, 15);   // 120
        $this->sunday('2026-03-22', 80, 30, 15, 15);   // 140

        $kesha = GatheringType::create(['territory_id' => $this->church->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Kesha', 'slug' => 'kesha', 'is_active' => true]);
        $choir = GatheringType::create(['territory_id' => $this->church->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Choir', 'slug' => 'choir', 'is_active' => true]);
        GatheringType::create(['territory_id' => $this->church->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Men', 'slug' => 'men', 'is_active' => true]);
        $this->gathering('2026-03-03', $kesha, 30);
        $this->gathering('2026-03-10', $kesha, 50);
        $this->gathering('2026-01-05', $choir, 20);     // 85 days before "today" - quiet
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sunday(string $date, int $adults, int $youth, int $boys, int $girls, ?Church $church = null): void
    {
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => ($church ?? $this->church)->id, 'service_date' => $date,
            'fiscal_year_id' => $this->year->id, 'fiscal_month_id' => $this->months[(int) substr($date, 5, 2)]->id,
            'gathering_category_id' => $this->categories['sunday_service'],
            'adults_count' => $adults, 'youth_count' => $youth, 'children_male_count' => $boys, 'children_female_count' => $girls,
        ]);
    }

    private function gathering(string $date, GatheringType $type, int $adults): void
    {
        ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->church->id, 'service_date' => $date,
            'fiscal_year_id' => $this->year->id, 'fiscal_month_id' => $this->months[(int) substr($date, 5, 2)]->id,
            'gathering_category_id' => $type->gathering_category_id, 'gathering_type_id' => $type->id, 'event_name' => $type->name,
            'adults_count' => $adults,
        ]);
    }

    private function analytics(array $query = [])
    {
        Sanctum::actingAs($this->pastor);

        return $this->getJson('/api/attendance-reports/analytics?'.http_build_query([
            'territory_id' => $this->church->id, 'fiscal_year_id' => $this->year->id, ...$query,
        ]));
    }

    public function test_a_month_shows_its_sundays_coverage_and_the_change_from_the_month_before(): void
    {
        $data = $this->analytics(['fiscal_month_id' => $this->months[3]->id])->assertOk()->json('data');

        $this->assertSame('March 2026', $data['period']['label']);
        $this->assertSame('Feb 2026', $data['period']['previous_label']);
        $this->assertSame(120, $data['summary']['sunday_average']);
        $this->assertSame(100, $data['summary']['previous_sunday_average']);
        $this->assertSame(['recorded' => 3, 'elapsed' => 5, 'percentage' => 60, 'missing' => ['2026-03-29', '2026-03-15']], $data['summary']['coverage']);
        $this->assertSame(140, $data['summary']['peak']['total']);
        $this->assertSame(['Adults' => 70, 'Youth' => 25, 'Boys' => 12, 'Girls' => 13], collect($data['sunday']['composition'])->pluck('average', 'label')->all());

        $titles = collect($data['sunday']['insights'])->pluck('title');
        $this->assertContains('2 Sundays not recorded', $titles);
        $this->assertContains('Sunday attendance is up 20%', $titles);
        $this->assertSame('concern', collect($data['sunday']['insights'])->firstWhere('title', '2 Sundays not recorded')['tone']);
    }

    public function test_a_month_can_be_given_by_its_number(): void
    {
        $this->analytics(['month' => 3])->assertOk()->assertJsonPath('data.period.label', 'March 2026');
        $this->analytics(['month' => 13])->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_sundays_before_the_first_record_are_not_counted_as_missing(): void
    {
        $coverage = $this->analytics()->assertOk()->json('data.summary.coverage');

        // First record is 22 Feb: 22 Feb + five March Sundays = 6 elapsed, not all of Jan-Mar.
        $this->assertSame(6, $coverage['elapsed']);
        $this->assertSame(4, $coverage['recorded']);
    }

    public function test_ministries_show_each_type_with_a_status_from_its_last_meeting(): void
    {
        $items = collect($this->analytics()->assertOk()->json('data.ministries.items'))->keyBy('name');

        $this->assertSame(['times' => 2, 'average' => 40, 'peak' => 50, 'status' => 'active'], collect($items['Kesha'])->only('times', 'average', 'peak', 'status')->all());
        $this->assertSame('quiet', $items['Choir']['status']);
        $this->assertSame('never', $items['Men']['status']);
        $this->assertSame(0, $items['Men']['times']);
        $this->assertStringContainsString('Choir', collect($this->analytics()->json('data.ministries.insights'))->firstWhere('tone', 'watch')['detail']);
    }

    public function test_all_time_starts_at_the_first_record_and_has_nothing_to_compare_with(): void
    {
        $data = $this->analytics(['fiscal_year_id' => 'all'])->assertOk()->json('data');

        $this->assertSame('all', $data['period']['mode']);
        $this->assertSame('All time', $data['period']['label']);
        $this->assertSame('2026-01-05', $data['period']['start']);
        $this->assertNull($data['summary']['previous_sunday_average']);
        $this->assertCount(4, $data['sunday']['weekly']);
    }

    public function test_membership_rate_uses_the_latest_approved_submission(): void
    {
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->church->id, 'fiscal_year_id' => $this->year->id,
            'fiscal_month_id' => $this->months[2]->id, 'total_members' => 200, 'status' => 'approved',
        ]);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->church->id, 'fiscal_year_id' => $this->year->id,
            'fiscal_month_id' => $this->months[3]->id, 'total_members' => 999, 'status' => 'draft',
        ]);

        $membership = $this->analytics(['fiscal_month_id' => $this->months[3]->id])->assertOk()->json('data.summary.membership');

        $this->assertSame(['total_members' => 200, 'as_of' => 'Feb 2026', 'rate' => 60], $membership);
    }

    public function test_children_split_is_the_average_boys_and_girls_per_sunday(): void
    {
        $children = $this->analytics(['fiscal_month_id' => $this->months[3]->id])->assertOk()->json('data.children');

        $this->assertSame(12, $children['boys']);
        $this->assertSame(13, $children['girls']);
        $this->assertSame(52, $children['girls_share']);
    }

    public function test_a_range_of_months_can_cross_years(): void
    {
        $data = $this->analytics(['fiscal_year_id' => '', 'from' => '2025-12', 'to' => '2026-03'])->assertOk()->json('data');

        $this->assertSame('range', $data['period']['mode']);
        $this->assertSame('Dec 2025 - Mar 2026', $data['period']['label']);
        $this->assertSame('2025-12-01', $data['period']['start']);
        $this->assertSame('2026-03-31', $data['period']['end']);
        $this->assertSame('the previous 4 months', $data['period']['previous_label']);
        $this->assertSame(115, $data['summary']['sunday_average'], '(100 + 100 + 120 + 140) / 4');
        $this->assertSame(6, $data['summary']['coverage']['elapsed'], 'From the first record (22 Feb), not December');
        $this->assertNull($data['summary']['previous_sunday_average'], 'Nothing was recorded in the 4 months before');
    }

    public function test_a_range_must_run_forwards_and_be_months(): void
    {
        $this->analytics(['from' => '2026-03', 'to' => '2026-01'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->analytics(['from' => '2026-3-1', 'to' => '2026-04'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->analytics(['from' => '2026-01'])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_another_churchs_attendance_is_refused(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/attendance-reports/analytics?'.http_build_query(['territory_id' => $this->otherChurch->id, 'fiscal_year_id' => $this->year->id]))
            ->assertStatus(403);
    }

    public function test_a_year_is_required(): void
    {
        $this->analytics(['fiscal_year_id' => ''])->assertStatus(422);
        $this->analytics(['fiscal_year_id' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('fiscal_year_id');
    }
}
