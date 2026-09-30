<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchAttendanceRecord;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\ReportRun;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The attendance PDF/Excel reports (App\Reports\Attendance) through the
 * report engine - same data as AttendanceAnalyticsTest. "Today" is Tue
 * 31 Mar 2026: March has five Sundays, three of them recorded.
 */
class AttendanceReportsTest extends TestCase
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
        Storage::fake('local');

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

    private function body(array $overrides = []): array
    {
        return array_merge([
            'report_key' => 'attendance.summary',
            'territory_id' => $this->church->id,
            'fiscal_year_id' => $this->year->id,
            'format' => 'pdf',
        ], $overrides);
    }

    private function preview(array $overrides = []): array
    {
        Sanctum::actingAs($this->pastor);

        return $this->postJson('/api/reports/preview', $this->body($overrides))->assertOk()->json('data');
    }

    public function test_the_catalogue_can_be_narrowed_to_attendance(): void
    {
        Sanctum::actingAs($this->pastor);

        $reports = collect($this->getJson('/api/reports/catalogue?module=attendance&territory_id='.$this->church->id)->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(
            ['attendance.summary', 'attendance.sunday', 'attendance.ministries', 'attendance.events', 'attendance.children'],
            $reports->pluck('key')->all(),
        );
        $this->assertSame(['attendance'], $reports->pluck('module')->unique()->values()->all());
        $this->assertContains('fiscal_month', $reports->firstWhere('key', 'attendance.sunday')['inputs']);
        $this->assertContains('gathering_type', $reports->firstWhere('key', 'attendance.ministries')['inputs']);
    }

    public function test_the_sunday_report_lists_missed_sundays_and_averages_instead_of_summing(): void
    {
        $data = $this->preview(['report_key' => 'attendance.sunday', 'month' => 3]);

        $this->assertSame('Church Sunday service report', $data['kicker']);
        $this->assertSame('March 2026', $data['period_label']);
        $sundays = collect($data['sections'])->firstWhere('heading', 'Every Sunday');
        $this->assertSame(5, $sundays['row_count'], '3 recorded + 2 not recorded');
        $this->assertSame('Average Sunday', $sundays['totals'][0]);
        $this->assertSame('120', $sundays['totals'][5], 'The average of 100, 120 and 140 - not their sum');
        $this->assertContains('Not recorded', collect($sundays['rows'])->pluck(6)->all());
        $this->assertContains('2 Sundays not recorded', collect($data['insights'])->pluck('title')->all());
    }

    public function test_a_ministry_report_can_be_one_ministry(): void
    {
        $all = $this->preview(['report_key' => 'attendance.ministries']);
        $this->assertSame('Church Ministry gatherings report', $all['kicker']);
        $this->assertSame(['Ministries', 'Every meeting'], collect($all['sections'])->pluck('heading')->all());

        $one = $this->preview(['report_key' => 'attendance.ministries', 'gathering_type_id' => $this->kesha->id]);
        $this->assertSame('Kesha', $one['title']);
        $this->assertSame('Church Kesha report', $one['kicker']);
        $this->assertSame(2, collect($one['sections'])->firstWhere('heading', 'Every meeting')['row_count']);
        $this->assertSame('40', collect($one['sections'])->firstWhere('heading', 'Every meeting')['totals'][6]);
    }

    public function test_a_gathering_type_from_another_church_is_refused(): void
    {
        $foreign = GatheringType::create(['territory_id' => $this->otherChurch->id, 'gathering_category_id' => $this->categories['ministry_gathering'], 'name' => 'Theirs', 'slug' => 'theirs', 'is_active' => true]);
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/reports/preview', $this->body(['report_key' => 'attendance.ministries', 'gathering_type_id' => $foreign->id]))
            ->assertStatus(422)->assertJsonValidationErrors('gathering_type_id');
        $this->postJson('/api/reports/preview', $this->body(['report_key' => 'attendance.sunday', 'month' => 13]))->assertStatus(422);
    }

    public function test_the_summary_pdf_is_generated_with_an_attendance_verification_code(): void
    {
        Sanctum::actingAs($this->pastor);

        $uuid = $this->postJson('/api/reports', $this->body())->assertStatus(202)->json('data.uuid');
        $run = ReportRun::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(ReportRun::STATUS_READY, $run->status);
        $this->assertMatchesRegularExpression('/^MWD-ATT-[2-9A-Z]{4}-[2-9A-Z]{4}$/', $run->verification_code);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($run->file_path));
    }

    public function test_every_attendance_report_builds_as_excel_too(): void
    {
        Sanctum::actingAs($this->pastor);

        foreach (['attendance.summary', 'attendance.sunday', 'attendance.ministries', 'attendance.events', 'attendance.children'] as $key) {
            $uuid = $this->postJson('/api/reports', $this->body(['report_key' => $key, 'format' => 'xlsx', 'fiscal_year_id' => 'all']))->assertStatus(202)->json('data.uuid');
            $run = ReportRun::where('uuid', $uuid)->firstOrFail();
            $this->assertSame(ReportRun::STATUS_READY, $run->status, "{$key} should build");
            $this->assertStringStartsWith('PK', Storage::disk('local')->get($run->file_path));
        }
    }

    public function test_a_report_can_cover_a_range_of_months(): void
    {
        $data = $this->preview(['report_key' => 'attendance.sunday', 'fiscal_year_id' => null, 'from' => '2026-02', 'to' => '2026-03']);

        $this->assertSame('Feb 2026 - Mar 2026', $data['period_label']);
        $this->assertSame(6, collect($data['sections'])->firstWhere('heading', 'Every Sunday')['row_count'], '22 Feb, 1, 8 and 22 Mar recorded + 15 and 29 Mar not recorded');
    }

    public function test_another_churchs_attendance_report_is_refused(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/reports/preview', $this->body(['territory_id' => $this->otherChurch->id]))->assertForbidden();
    }
}
