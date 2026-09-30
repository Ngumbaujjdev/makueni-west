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
 * The attendance detail pages' API: GET /attendance/{id} (record page),
 * GET /attendance/{id}/audits and GET /attendance-reports/gathering
 * (gathering page). Same data as AttendanceAnalyticsTest: "today" is Tue
 * 31 Mar 2026, Sundays 22 Feb, 1, 8 and 22 Mar recorded.
 */
class AttendanceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected Church $otherChurch;

    protected User $pastor;

    protected FiscalYear $year;

    protected array $months;

    protected array $categories;

    protected GatheringType $kesha;

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

        $this->kesha = $kesha = GatheringType::create(['territory_id' => $this->church->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Kesha', 'slug' => 'kesha', 'is_active' => true]);
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

    private function sundayOn(string $date): ChurchAttendanceRecord
    {
        return ChurchAttendanceRecord::whereDate('service_date', $date)->where('gathering_category_id', $this->categories['sunday_service'])->firstOrFail();
    }

    public function test_a_record_shows_the_meetings_around_it_and_how_it_compares(): void
    {
        Sanctum::actingAs($this->pastor);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->church->id, 'fiscal_year_id' => $this->year->id,
            'fiscal_month_id' => $this->months[2]->id, 'total_members' => 400, 'status' => 'approved',
        ]);

        $data = $this->getJson('/api/attendance/'.$this->sundayOn('2026-03-08')->id)->assertOk()->json('data');

        $this->assertSame('Sunday service', $data['name']);
        $this->assertSame(120, $data['total']);
        $this->assertSame('2026-03-01', $data['previous']['date']);
        $this->assertSame('2026-03-22', $data['next']['date'], 'The next recorded Sunday of the same gathering');
        $this->assertSame(100, $data['usual'], 'Average of 22 Feb and 1 Mar');
        $this->assertSame(140, $data['best']['total']);
        $this->assertSame(2, $data['rank']);
        $this->assertSame(30, $data['members']['rate'], '120 of 400 members');
        // The Sunday after (15 Mar) wasn't recorded.
        $this->assertSame(['date' => '2026-03-15', 'id' => null, 'total' => null], $data['sundays']['next']);
    }

    public function test_a_ministry_meeting_compares_with_the_same_ministry_only(): void
    {
        Sanctum::actingAs($this->pastor);
        $second = ChurchAttendanceRecord::where('gathering_type_id', $this->kesha->id)->whereDate('service_date', '2026-03-10')->firstOrFail();

        $data = $this->getJson("/api/attendance/{$second->id}")->assertOk()->json('data');

        $this->assertSame('Kesha', $data['name']);
        $this->assertSame(30, $data['previous']['total']);
        $this->assertNull($data['next']);
        $this->assertNull($data['sundays']);
        $this->assertTrue($data['is_best']);
    }

    public function test_another_churchs_record_is_refused(): void
    {
        $theirs = ChurchAttendanceRecord::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id, 'service_date' => '2026-03-01',
            'fiscal_year_id' => $this->year->id, 'fiscal_month_id' => $this->months[3]->id,
            'gathering_category_id' => $this->categories['sunday_service'], 'adults_count' => 10,
        ]);
        Sanctum::actingAs($this->pastor);

        $this->getJson("/api/attendance/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/attendance/{$theirs->id}/audits")->assertForbidden();
    }

    public function test_audits_list_each_change_from_old_to_new(): void
    {
        // Auditing is off in the console by default and attaches when the
        // model boots - switch it on, then boot the model again.
        config(['audit.console' => true]);
        ChurchAttendanceRecord::clearBootedModels();
        $record = $this->sundayOn('2026-03-22');
        $record->update(['adults_count' => 85, 'notes' => 'Easter practice']);
        Sanctum::actingAs($this->pastor);

        $audits = $this->getJson("/api/attendance/{$record->id}/audits")->assertOk()->json('data');

        $changes = collect($audits[0]['changes'])->keyBy('field');
        $this->assertSame('updated', $audits[0]['event']);
        $this->assertSame(['field' => 'Adults', 'old' => 80, 'new' => 85], $changes['Adults']);
        $this->assertSame('Easter practice', $changes['Notes']['new']);
    }

    public function test_the_gathering_page_covers_one_ministry_over_a_period(): void
    {
        Sanctum::actingAs($this->pastor);

        $data = $this->getJson('/api/attendance-reports/gathering?'.http_build_query([
            'territory_id' => $this->church->id, 'gathering_type_id' => $this->kesha->id, 'from' => '2026-01', 'to' => '2026-03',
        ]))->assertOk()->json('data');

        $this->assertSame('Kesha', $data['gathering']['name']);
        $this->assertSame(['times' => 2, 'average' => 40, 'status' => 'active'], collect($data['summary'])->only('times', 'average', 'status')->all());
        $this->assertSame(50, $data['summary']['peak']['total']);
        $this->assertSame(80, $data['summary']['share'], 'Kesha 80 of the 100 at ministry gatherings in the range (Choir 20)');
        $this->assertSame(['2026-03-10', '2026-03-03'], array_column($data['meetings'], 'date'), 'Newest first');
        $this->assertSame(20, $data['meetings'][0]['change']);
        $this->assertSame([0, 0, 2], array_column($data['monthly'], 'meetings'), 'Jan, Feb, Mar');
    }

    public function test_the_gathering_page_refuses_another_churchs_type(): void
    {
        $foreign = GatheringType::create(['territory_id' => $this->otherChurch->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Theirs', 'slug' => 'theirs', 'is_active' => true]);
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/attendance-reports/gathering?'.http_build_query(['territory_id' => $this->church->id, 'gathering_type_id' => $foreign->id, 'fiscal_year_id' => $this->year->id]))
            ->assertStatus(422);
        $this->getJson('/api/attendance-reports/gathering?'.http_build_query(['territory_id' => $this->church->id, 'name' => 'Never held', 'fiscal_year_id' => $this->year->id]))
            ->assertNotFound();
    }
}
