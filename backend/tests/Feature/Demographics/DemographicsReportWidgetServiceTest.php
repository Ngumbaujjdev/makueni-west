<?php

namespace Tests\Feature\Demographics;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemographicsReportWidgetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Church $myChurch;

    protected Church $otherChurch;

    protected User $pastor;

    protected FiscalYear $fiscalYear;

    protected FiscalMonth $january;

    protected FiscalMonth $march;

    protected FiscalMonth $august;

    /** All 12 fiscal months, keyed by short name - production always has the full set (FiscalMonthSeeder), so the widget service's "always 12 months" assumption needs the same here. */
    private const MONTHS = [
        ['number' => 1, 'name' => 'January', 'short_name' => 'Jan'],
        ['number' => 2, 'name' => 'February', 'short_name' => 'Feb'],
        ['number' => 3, 'name' => 'March', 'short_name' => 'Mar'],
        ['number' => 4, 'name' => 'April', 'short_name' => 'Apr'],
        ['number' => 5, 'name' => 'May', 'short_name' => 'May'],
        ['number' => 6, 'name' => 'June', 'short_name' => 'Jun'],
        ['number' => 7, 'name' => 'July', 'short_name' => 'Jul'],
        ['number' => 8, 'name' => 'August', 'short_name' => 'Aug'],
        ['number' => 9, 'name' => 'September', 'short_name' => 'Sep'],
        ['number' => 10, 'name' => 'October', 'short_name' => 'Oct'],
        ['number' => 11, 'name' => 'November', 'short_name' => 'Nov'],
        ['number' => 12, 'name' => 'December', 'short_name' => 'Dec'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->myChurch = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OTHER-CH', 'territory_type' => 'church', 'level' => 4]);

        $this->fiscalYear = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $months = collect(self::MONTHS)->map(fn (array $m) => FiscalMonth::create($m))->keyBy('short_name');
        $this->january = $months['Jan'];
        $this->march = $months['Mar'];
        $this->august = $months['Aug'];

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

    public function test_widgets_compute_monthly_rows_stats_and_spiritual_activity_breakdown(): void
    {
        // March: approved, lower figures. August: approved, higher figures.
        // Every other of the 12 fiscal months has no row at all.
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->march->id,
            'status' => 'approved', 'total_members' => 100,
            'baptisms_count' => 2, 'communion_participants_count' => 50, 'conversions_count' => 1, 'transferred_out_count' => 0,
        ]);
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->august->id,
            'status' => 'approved', 'total_members' => 120,
            'baptisms_count' => 4, 'communion_participants_count' => 60, 'conversions_count' => 3, 'transferred_out_count' => 1,
        ]);
        // A draft (not yet approved) row for January - should NOT count toward anything.
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id, 'fiscal_month_id' => $this->january->id,
            'status' => 'draft', 'total_members' => 999,
        ]);

        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/demographics-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(200);

        $months = collect($response->json('data.months'))->keyBy('month');

        $this->assertEquals('approved', $months['Mar']['status']);
        $this->assertEquals(100, $months['Mar']['total_members']);
        $this->assertEquals(2, $months['Mar']['baptisms_count']);

        $this->assertEquals('approved', $months['Aug']['status']);
        $this->assertEquals(120, $months['Aug']['total_members']);

        // January has a draft row (not approved) - reports as not_submitted, no fabricated numbers.
        $this->assertEquals('not_submitted', $months['Jan']['status']);
        $this->assertNull($months['Jan']['total_members']);

        // A month with no row at all.
        $this->assertEquals('not_submitted', $months['Feb']['status']);
        $this->assertNull($months['Feb']['baptisms_count']);

        $this->assertCount(12, $response->json('data.months'));

        $stats = collect($response->json('data.stats'))->keyBy('label');
        $this->assertEquals('2 of 12', $stats['Months Reported']['value']);
        $this->assertEquals(120, $stats['Latest Total Members']['value']); // Aug (fiscal_month_id 8) is later than Mar (3)
        $this->assertEquals(110, $stats['Average Members']['value']); // round((100 + 120) / 2)

        $spiritual = collect($response->json('data.spiritual'))->keyBy('metric');

        $baptisms = collect($spiritual['baptisms_count']['stats'])->keyBy('label');
        $this->assertEquals(6, $baptisms['Total This Year']['value']); // 2 + 4
        $this->assertEquals(3.0, $baptisms['Average per Month']['value']); // 6 / 2 reported months
        $this->assertEquals('Aug (4)', $baptisms['Best Month']['value']);
        $this->assertEquals(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $spiritual['baptisms_count']['chart']['categories']);
        $this->assertEquals([0, 0, 2, 0, 0, 0, 0, 4, 0, 0, 0, 0], $spiritual['baptisms_count']['chart']['series'][0]['data']);

        $departures = collect($spiritual['transferred_out_count']['stats'])->keyBy('label');
        $this->assertEquals(1, $departures['Total This Year']['value']); // 0 + 1
        $this->assertEquals('Aug (1)', $departures['Best Month']['value']);
    }

    public function test_widgets_report_no_data_when_nothing_approved(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/demographics-reports/widgets?'.http_build_query([
            'territory_id' => $this->myChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(200);

        $stats = collect($response->json('data.stats'))->keyBy('label');
        $this->assertEquals('0 of 12', $stats['Months Reported']['value']);
        $this->assertEquals('-', $stats['Latest Total Members']['value']);
        $this->assertEquals('-', $stats['Average Members']['value']);

        $spiritual = collect($response->json('data.spiritual'))->keyBy('metric');
        $baptisms = collect($spiritual['baptisms_count']['stats'])->keyBy('label');
        $this->assertEquals(0, $baptisms['Total This Year']['value']);
        $this->assertEquals('-', $baptisms['Average per Month']['value']);
        $this->assertEquals('-', $baptisms['Best Month']['value']);
    }

    public function test_pastor_cannot_view_widgets_for_another_church(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/demographics-reports/widgets?'.http_build_query([
            'territory_id' => $this->otherChurch->id,
            'fiscal_year_id' => $this->fiscalYear->id,
        ]));

        $response->assertStatus(403);
    }

    public function test_missing_fiscal_year_id_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->pastor);

        $response = $this->getJson('/api/demographics-reports/widgets?territory_id='.$this->myChurch->id);

        $response->assertStatus(422)->assertJsonValidationErrors('fiscal_year_id');
    }
}
