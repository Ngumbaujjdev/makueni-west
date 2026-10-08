<?php

namespace Tests\Feature\Facilities;

use App\Models\DutyRota;
use App\Models\MaintenanceJob;
use App\Models\Person;
use App\Models\ReportRun;
use App\Reports\Facilities\BoughtInYearReport;
use App\Reports\Facilities\RepairsCostReport;
use App\Reports\Facilities\RoomByRoomReport;
use App\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P5 round 3 (docs/specs/people-and-care-spec.md): Settings >
 * Facilities - each church's duties with their teams and its kinds of
 * equipment - the rota filled from the teams in turn, and the asset reports
 * (room by room, bought in a year, repairs and their cost).
 */
class SetupTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $deacon;

    private $otherPastor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        Storage::fake('local');
        $this->buildBudgetWorld();
        $f = ['church.facilities.facilities.read', 'church.facilities.facilities.book'];
        $settings = ['church.settings.hub.facilities.read', 'church.settings.hub.facilities.update'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$f, 'church.facilities.facilities.manage', 'church.facilities.facilities.export', ...$settings]);
        $this->deacon = $this->userWithRole('deacon', 'Deacon', 'church', $this->myChurch->id, [...$f, 'church.settings.hub.facilities.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, [...$f, 'church.facilities.facilities.manage', ...$settings]);
    }

    private function person(string $name): int
    {
        [$first, $last] = explode(' ', $name);

        return Person::create(['territory_id' => $this->myChurch->id, 'first_name' => $first, 'last_name' => $last, 'status' => 'member'])->id;
    }

    private function loadSetup(): array
    {
        return $this->getJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id)->assertOk()->json('data');
    }

    public function test_duties_with_teams_and_kinds_are_each_churches_own(): void
    {
        Sanctum::actingAs($this->senior);
        $data = $this->loadSetup();
        $this->assertSame(['ushering', 'welcome', 'sound', 'security', 'cleaning'], array_column($data['duties'], 'key'));
        $this->assertFalse($data['custom']);

        $ruth = $this->person('Ruth Mwende');
        $duties = $data['duties'];
        $duties[0]['team'] = [['person_id' => $ruth], ['name' => 'John Kioko'], ['person_id' => $ruth]];
        $duties[0]['needed'] = 2;
        $duties[] = ['label' => 'Media', 'icon' => 'ri-camera-line', 'colour' => 'info', 'active' => true, 'needed' => 1, 'team' => [['name' => 'Grace']]];
        $kinds = [...$data['kinds'], ['label' => 'Decorations', 'icon' => 'ri-gift-line', 'colour' => 'pink']];
        $saved = $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $duties, 'kinds' => $kinds])->assertOk()->json('data');
        $this->assertSame('media', end($saved['duties'])['key']);
        $this->assertSame([['person_id' => $ruth, 'name' => 'Ruth Mwende'], ['person_id' => null, 'name' => 'John Kioko']], array_map(fn ($p) => ['person_id' => $p['person_id'], 'name' => $p['name']], $saved['duties'][0]['team']));
        $this->assertSame('decorations', end($saved['kinds'])['key']);

        // The pages use them: a new kind can be picked, a new duty can be put on the rota.
        $this->postJson('/api/equipment', ['name' => 'Banners', 'category' => 'decorations'])->assertCreated()->assertJsonPath('data.category_label', 'Decorations');
        $this->assertContains('media', array_column($this->getJson('/api/facilities/options')->json('data.duties'), 'key'));
        $this->putJson('/api/rota', ['on' => '2026-11-01', 'service' => 'Main service', 'duty' => 'media', 'people' => [['name' => 'Grace']]])->assertOk();

        // A kind in use can't go; a duty on the rota stays, switched off.
        $without = array_values(array_filter($saved['kinds'], fn ($k) => $k['key'] !== 'decorations'));
        $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $saved['duties'], 'kinds' => $without])->assertStatus(422);
        $noMedia = array_values(array_filter($saved['duties'], fn ($d) => $d['key'] !== 'media'));
        $after = $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $noMedia, 'kinds' => $saved['kinds']])->assertOk()->json('data.duties');
        $this->assertFalse(collect($after)->firstWhere('key', 'media')['active']);
        $this->assertNotContains('media', array_column($this->getJson('/api/facilities/options')->json('data.duties'), 'key'));

        // Others: a deacon reads but can't change; another church has its own (the defaults).
        Sanctum::actingAs($this->deacon);
        $this->loadSetup();
        $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $saved['duties'], 'kinds' => $saved['kinds']])->assertForbidden();
        Sanctum::actingAs($this->otherPastor);
        $this->assertNotContains('decorations', array_column($this->getJson('/api/settings/facilities-setup?territory_id='.$this->otherChurch->id)->assertOk()->json('data.kinds'), 'key'));
        $this->postJson('/api/equipment', ['name' => 'Banners', 'category' => 'decorations'])->assertStatus(422);
    }

    public function test_the_rota_fills_from_the_teams_in_turn_and_leaves_filled_duties_alone(): void
    {
        Sanctum::actingAs($this->senior);
        $data = $this->loadSetup();
        $duties = array_map(fn ($d) => $d['key'] === 'ushering' ? ['team' => [['name' => 'A Usher'], ['name' => 'B Usher'], ['name' => 'C Usher']], 'needed' => 2] + $d : $d, $data['duties']);
        $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $duties, 'kinds' => $data['kinds']])->assertOk();

        $sunday = CarbonImmutable::now('Africa/Nairobi')->next(CarbonImmutable::SUNDAY);
        $next = $sunday->addWeek();
        $this->putJson('/api/rota', ['on' => $next->toDateString(), 'service' => 'Sunday service', 'duty' => 'ushering', 'people' => [['name' => 'Kept Person']]])->assertOk();
        $this->postJson('/api/rota/fill', ['from' => $sunday->toDateString(), 'to' => $next->toDateString()])->assertOk()->assertJsonPath('data.added', 2);

        $first = DutyRota::where('on', $sunday->toDateString())->where('duty', 'ushering')->pluck('name')->all();
        $this->assertCount(2, $first);
        $this->assertSame(['Kept Person'], DutyRota::where('on', $next->toDateString())->where('duty', 'ushering')->pluck('name')->all());
        // Again: nothing to fill; the week after takes the next turn.
        $this->postJson('/api/rota/fill', ['from' => $sunday->toDateString(), 'to' => $next->toDateString()])->assertJsonPath('data.added', 0);
        $later = $next->addWeek();
        $this->postJson('/api/rota/fill', ['from' => $later->toDateString(), 'to' => $later->toDateString()])->assertJsonPath('data.added', 2);
        $this->assertNotSame($first, DutyRota::where('on', $later->toDateString())->where('duty', 'ushering')->pluck('name')->all());

        Sanctum::actingAs($this->deacon);
        $this->postJson('/api/rota/fill', ['from' => $sunday->toDateString(), 'to' => $next->toDateString()])->assertForbidden();
    }

    public function test_the_asset_reports(): void
    {
        Sanctum::actingAs($this->senior);
        $hall = $this->postJson('/api/rooms', ['name' => 'Hall'])->assertCreated()->json('data.id');
        $chairs = $this->postJson('/api/equipment', ['name' => 'Chairs', 'category' => 'furniture', 'room_id' => $hall, 'quantity' => 50, 'value' => 600, 'bought_on' => now()->startOfYear()->addDays(40)->toDateString()])->json('data.id');
        $this->postJson('/api/equipment', ['name' => 'Kettle', 'category' => 'kitchen', 'value' => 2000, 'bought_on' => now()->subYears(2)->toDateString()])->assertCreated();
        MaintenanceJob::create(['territory_id' => $this->myChurch->id, 'equipment_id' => $chairs, 'title' => 'Broken legs', 'priority' => 'normal', 'status' => 'done', 'cost' => 1500, 'done_on' => now()->toDateString()]);
        MaintenanceJob::create(['territory_id' => $this->myChurch->id, 'room_id' => $hall, 'title' => 'Roof leaks', 'priority' => 'urgent', 'status' => 'reported']);

        $ctx = new ReportContext($this->myChurch, $this->senior);
        $rooms = (new RoomByRoomReport)->build($ctx)->sections;
        $this->assertSame(['Hall', 'Not kept in a room'], array_map(fn ($s) => $s->heading, $rooms));
        $this->assertSame(['Chairs', 50, '-'], [$rooms[0]->rows[0][1], $rooms[0]->rows[0][3], $rooms[0]->rows[0][5]]);

        $bought = (new BoughtInYearReport)->build($ctx);
        $this->assertSame(['Chairs'], array_column($bought->sections[0]->rows, 2));
        $this->assertSame('KES 30,000', $bought->tiles[0]['value']);

        $repairs = (new RepairsCostReport)->build($ctx);
        $this->assertSame(['1', 'KES 1,500', '1', '1'], array_column($repairs->tiles, 'value'));
        $this->assertSame('Room: Hall', $repairs->sections[2]->rows[0][2]);

        foreach (['facilities.rooms' => 'pdf', 'facilities.bought' => 'xlsx', 'facilities.repairs' => 'pdf'] as $key => $format) {
            $uuid = $this->postJson('/api/reports', ['report_key' => $key, 'format' => $format, 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $format === 'pdf' ? 'all' : null])->assertStatus(202)->json('data.uuid');
            $run = ReportRun::where('uuid', $uuid)->firstOrFail();
            $this->assertSame(ReportRun::STATUS_READY, $run->status, (string) $run->error);
        }
        Sanctum::actingAs($this->deacon);
        $this->postJson('/api/reports', ['report_key' => 'facilities.rooms', 'format' => 'xlsx', 'territory_id' => $this->myChurch->id])->assertForbidden();
    }
}
