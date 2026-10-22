<?php

namespace Tests\Feature\Mobile;

use App\Models\Church;
use App\Models\ChurchDemographic;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Subregion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Settings\BuildsSettingsWorld;
use Tests\TestCase;

/**
 * The phone app's overseer screens (docs/specs/mobile-app-spec.md, M5, M8-M10):
 * each level sees only the churches below it, the totals add up to the churches'
 * latest counts, review notes, flagging after the automatic approval, and the
 * members trend with its estimate.
 */
class OverseerAppTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    private Subregion $subregion;

    private Church $subA;

    private Church $subB;

    private User $subOverseer;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();   // region "Region A" holds myChurch and otherChurch directly
        $this->subregion = Subregion::create(['name' => 'Sub A', 'code' => 'T-SA', 'territory_type' => 'subregion', 'level' => 3, 'parent_territory_id' => $this->region->id]);
        $this->subA = Church::create(['name' => 'Sub Church A', 'code' => 'SA-1', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->subregion->id]);
        $this->subB = Church::create(['name' => 'Sub Church B', 'code' => 'SA-2', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->subregion->id]);
        $this->subOverseer = $this->settingsUser('suboverseer', 'Test Subregion Overseer', 'subregion', $this->subregion->id, []);
        foreach (['flag', 'approve', 'requestchanges'] as $a) {
            $this->subOverseer->roles()->first()->givePermissionTo(Permission::firstOrCreate(
                ['name' => "subregiondemographicsreview.churchsubmissions.{$a}", 'guard_name' => 'web'], ['action' => $a, 'territory_scope' => 'subregion'],
            ));
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->year = FiscalYear::firstOrCreate(['year' => (int) now()->year], ['start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
    }

    private function counts(Church $church, int $members, int $youth, int $half = 1, string $status = 'approved', ?int $year = null): ChurchDemographic
    {
        $fy = $year ? FiscalYear::firstOrCreate(['year' => $year], ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]) : $this->year;

        return ChurchDemographic::create([
            'territory_type' => 'church', 'territory_id' => $church->id, 'fiscal_year_id' => $fy->id,
            'fiscal_semi_annual_id' => $fy->semiAnnuals()->where('number', $half)->value('id'),
            'total_members' => $members, 'youth_count' => $youth, 'baptisms_count' => 2, 'status' => $status,
        ]);
    }

    public function test_the_region_home_adds_up_its_churches_in_groups(): void
    {
        $this->counts($this->subA, 100, 40);
        $this->counts($this->subB, 50, 10);
        $this->counts($this->myChurch, 80, 20);

        Sanctum::actingAs($this->overseer);
        $res = $this->getJson('/api/app/home')->assertOk()
            ->assertJsonPath('data.place.level', 'region')
            ->assertJsonPath('data.churches', 4)
            ->assertJsonPath('data.totals.members', 230)
            ->assertJsonPath('data.totals.youth', 70);
        $groups = collect($res->json('data.groups'))->keyBy('name');
        $this->assertSame(150, $groups['Sub A']['members']);
        $this->assertSame(2, $groups['Sub A']['churches']);
        $this->assertSame(2, $groups['Churches directly under Region A']['churches']);   // myChurch + otherChurch
    }

    public function test_a_subregion_sees_only_its_own_churches(): void
    {
        $this->counts($this->subA, 100, 40);
        $this->counts($this->myChurch, 80, 20);

        Sanctum::actingAs($this->subOverseer);
        $this->getJson('/api/app/home')->assertJsonPath('data.place.level', 'subregion')->assertJsonPath('data.totals.members', 100);
        $names = collect($this->getJson('/api/app/places')->json('data.items'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Sub Church A', 'Sub Church B'], $names);
        $this->getJson("/api/app/review/{$this->myChurch->id}")->assertJsonPath('status', 403);
        $this->getJson("/api/app/places?group={$this->region->id}")->assertJsonPath('status', 403);
    }

    public function test_a_church_role_gets_the_church_home_and_not_the_overseer_screens(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/app/home')->assertJsonPath('data.place.id', $this->myChurch->id)->assertJsonMissingPath('data.groups');
        $this->getJson('/api/app/places')->assertJsonPath('status', 403);
        $this->getJson('/api/app/compare')->assertJsonPath('status', 403);
    }

    public function test_review_shows_the_change_and_a_note_when_it_jumps(): void
    {
        $this->counts($this->subA, 100, 40, 1, 'approved', (int) now()->year - 1);
        $latest = $this->counts($this->subA, 104, 60);

        Sanctum::actingAs($this->subOverseer);
        $res = $this->getJson("/api/app/review/{$this->subA->id}")->assertOk()
            ->assertJsonPath('data.record.id', $latest->id)
            ->assertJsonPath('data.can.flag', true)
            ->assertJsonPath('data.can.approve', false);   // already approved
        $youth = collect($res->json('data.lines'))->firstWhere('key', 'youth_count');
        $this->assertSame(20, $youth['change']);
        $this->assertSame(50, (int) $youth['percent']);
        $this->assertTrue(collect($res->json('data.notes'))->contains(fn ($n) => str_contains($n, 'Youth (13–35) up 50%')));
    }

    public function test_an_approved_submission_can_be_flagged_then_approved_again(): void
    {
        $row = $this->counts($this->subA, 104, 60);
        Sanctum::actingAs($this->subOverseer);
        $this->postJson("/api/demographics/{$row->id}/flag", ['notes' => 'Youth looks high'])->assertJsonPath('success', true);
        $this->assertSame('flagged', $row->fresh()->status);
        $this->postJson("/api/demographics/{$row->id}/approve")->assertJsonPath('success', true);
        $this->assertSame('approved', $row->fresh()->status);
    }

    public function test_a_region_overseer_without_the_review_rights_cannot_flag(): void
    {
        $this->counts($this->subA, 104, 60);
        Sanctum::actingAs($this->overseer);
        $this->getJson("/api/app/review/{$this->subA->id}")->assertJsonPath('data.can.flag', false);
    }

    public function test_compare_gives_the_groups_a_trend_and_an_estimate(): void
    {
        $this->counts($this->subA, 100, 40, 1, 'approved', (int) now()->year - 1);
        $this->counts($this->subA, 120, 40);
        $this->counts($this->myChurch, 80, 20);

        Sanctum::actingAs($this->overseer);
        $res = $this->getJson('/api/app/compare?period=year')->assertOk()->assertJsonPath('data.period', 'year');
        $this->assertCount(12, $res->json('data.trend'));
        $this->assertCount(3, $res->json('data.forecast'));
        $this->assertSame(200, $res->json('data.totals.members'));
        $sub = collect($res->json('data.rows'))->firstWhere('name', 'Sub A');
        $this->assertSame(120, $sub['members']);
        $this->assertSame(33, $sub['youth_share']);
    }
}
