<?php

namespace Tests\Feature\Mobile;

use App\Models\Activity;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\Permission;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Settings\BuildsSettingsWorld;
use Tests\TestCase;

/**
 * The phone app's church screens (docs/specs/mobile-app-spec.md, M5-M7):
 * app/home, app/demographics in the church's cadence, men/women/converts on
 * service counts, and crusades and conferences as church events.
 */
class ChurchAppTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        $this->year = FiscalYear::firstOrCreate(['year' => (int) now()->year], ['start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $m) {
            FiscalMonth::firstOrCreate(['number' => $i + 1], ['name' => $m, 'short_name' => substr($m, 0, 3)]);
        }
    }

    private function counts(int $members, string $status = 'approved', array $period = []): ChurchDemographic
    {
        return ChurchDemographic::create($period + [
            'territory_type' => 'church', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $this->year->id,
            'total_members' => $members, 'youth_count' => 40, 'baptisms_count' => 3, 'conversions_count' => 5, 'status' => $status,
        ]);
    }

    public function test_home_gives_the_churchs_members_and_report(): void
    {
        $h1 = $this->year->semiAnnuals()->where('number', 1)->first();
        $h2 = $this->year->semiAnnuals()->where('number', 2)->first();
        $this->counts(100, 'approved', ['fiscal_semi_annual_id' => $h1->id]);
        $this->counts(112, 'approved', ['fiscal_semi_annual_id' => $h2->id]);

        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/app/home')->assertOk()
            ->assertJsonPath('data.place.id', $this->myChurch->id)
            ->assertJsonPath('data.members.total', 112)
            ->assertJsonPath('data.members.change', 12)
            ->assertJsonPath('data.latest.youth', 40)
            ->assertJsonStructure(['data' => ['report' => ['status', 'due_on', 'label'], 'money']])
            ->assertJsonPath('data.money', null);   // no accounting rights in this world
    }

    public function test_an_overseer_gets_the_overseer_home_and_not_a_churchs(): void
    {
        Sanctum::actingAs($this->overseer);
        $this->getJson('/api/app/home')->assertJsonPath('success', true)->assertJsonPath('data.place.level', 'region');
        $this->getJson('/api/app/demographics')->assertJsonPath('status', 403);
    }

    public function test_home_never_shows_another_churchs_figures(): void
    {
        ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $this->otherChurch->id, 'fiscal_year_id' => $this->year->id,
            'total_members' => 999, 'status' => 'approved',
        ]);
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/app/home')->assertJsonPath('data.members.total', null);
    }

    public function test_demographics_follow_the_churchs_cadence(): void
    {
        // Half-yearly is the default: this year's open halves.
        Sanctum::actingAs($this->pastor);
        $half = $this->getJson('/api/app/demographics')->assertJsonPath('data.mode', 'half_yearly')->json('data.periods');
        $this->assertNotEmpty($half);
        $this->assertNotNull($half[0]['fiscal_semi_annual_id']);
        $this->assertNull($half[0]['fiscal_month_id']);

        // Monthly: one period for each month so far this year.
        $church = Territory::find($this->myChurch->id);
        $church->metadata = array_merge($church->metadata ?? [], ['demographics_mode' => 'monthly']);
        $church->save();
        $months = collect($this->getJson('/api/app/demographics')->assertJsonPath('data.mode', 'monthly')->json('data.periods'))
            ->where('year', (int) now()->year);
        $this->assertCount((int) now()->month, $months);
        $this->assertNotNull($months->first()['fiscal_month_id']);
    }

    public function test_a_recorded_period_carries_its_record(): void
    {
        $h1 = $this->year->semiAnnuals()->where('number', 1)->first();
        $row = $this->counts(80, 'draft', ['fiscal_semi_annual_id' => $h1->id]);
        Sanctum::actingAs($this->pastor);
        $period = collect($this->getJson('/api/app/demographics')->json('data.periods'))->firstWhere('fiscal_semi_annual_id', $h1->id);
        $this->assertSame($row->id, $period['record']['id']);
        $this->assertSame('draft', $period['record']['status']);
    }

    public function test_men_and_women_make_the_adults_and_converts_are_kept(): void
    {
        $role = $this->pastor->roles()->first();
        foreach (['attendancemanagement.serviceattendance.create', 'attendancemanagement.serviceattendance.update'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => 'create', 'territory_scope' => 'church']));
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $sunday = now()->previous('Sunday')->toDateString();
        FiscalMonth::where('number', (int) now()->previous('Sunday')->month)->firstOrFail();

        Sanctum::actingAs($this->pastor);
        $res = $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id, 'service_date' => $sunday,
            'gathering_category_id' => GatheringCategory::where('slug', 'sunday_service')->value('id'),
            'men_count' => 40, 'women_count' => 55, 'youth_count' => 30, 'converts_count' => 4,
        ]);
        $res->assertJsonPath('success', true);
        $id = $res->json('data.id');
        $this->assertDatabaseHas('church_attendance_records', ['id' => $id, 'adults_count' => 95, 'men_count' => 40, 'women_count' => 55, 'converts_count' => 4]);

        $this->putJson("/api/attendance/{$id}", ['men_count' => 41, 'women_count' => 55])->assertJsonPath('success', true);
        $this->assertDatabaseHas('church_attendance_records', ['id' => $id, 'adults_count' => 96]);

        // The web still sends adults alone, and that still works.
        $this->putJson("/api/attendance/{$id}", ['adults_count' => 90])->assertJsonPath('success', true);
        $this->assertDatabaseHas('church_attendance_records', ['id' => $id, 'adults_count' => 90]);
    }

    public function test_a_church_can_hold_a_crusade_or_a_conference(): void
    {
        $this->assertArrayHasKey('crusade', Activity::TYPES['church']);
        $this->assertArrayHasKey('conference', Activity::TYPES['church']);
    }

    public function test_history_follows_the_churchs_current_cadence(): void
    {
        $h1 = $this->year->semiAnnuals()->where('number', 1)->first();
        $this->counts(100, 'approved', ['fiscal_semi_annual_id' => $h1->id]);
        $this->counts(90, 'approved', ['fiscal_month_id' => FiscalMonth::where('number', 3)->value('id')]);

        Sanctum::actingAs($this->pastor);   // half-yearly by default
        $labels = collect($this->getJson('/api/app/demographics')->json('data.history'))->pluck('total_members')->all();
        $this->assertSame([100], $labels);
    }
}
