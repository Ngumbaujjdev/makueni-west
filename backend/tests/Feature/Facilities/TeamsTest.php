<?php

namespace Tests\Feature\Facilities;

use App\Models\DutyRota;
use App\Models\DutyTeamMember;
use App\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P5 round 4 (docs/specs/people-and-care-spec.md): the Teams
 * page - who serves on each duty, added from the register or by name, in
 * the order the rota takes turns; people who left are kept but skipped.
 */
class TeamsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $deacon;

    private $otherPastor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        $this->buildBudgetWorld();
        $f = ['church.facilities.facilities.read', 'church.facilities.facilities.book'];
        $settings = ['church.settings.hub.facilities.read', 'church.settings.hub.facilities.update'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$f, 'church.facilities.facilities.manage', ...$settings]);
        $this->deacon = $this->userWithRole('deacon', 'Deacon', 'church', $this->myChurch->id, $f);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, [...$f, 'church.facilities.facilities.manage']);
    }

    private function person(string $name, $church = null, string $status = 'member'): int
    {
        [$first, $last] = explode(' ', $name);

        return Person::create(['territory_id' => ($church ?? $this->myChurch)->id, 'first_name' => $first, 'last_name' => $last, 'status' => $status])->id;
    }

    private function teams(): array
    {
        return $this->getJson('/api/facilities/teams')->assertOk()->json('data');
    }

    private function team(string $duty): array
    {
        return collect($this->teams()['duties'])->firstWhere('key', $duty)['members'];
    }

    public function test_people_are_added_taken_off_and_put_in_turn_order(): void
    {
        Sanctum::actingAs($this->senior);
        $ruth = $this->person('Ruth Mwende');
        $paul = $this->person('Paul Kioko');
        $this->person('Not Serving');

        $this->postJson('/api/facilities/teams/ushering/members', ['person_ids' => [$ruth, $paul], 'names' => ['John Musyoka']])->assertCreated()->assertJsonPath('data.added', 3);
        // Again: nobody new.
        $this->postJson('/api/facilities/teams/ushering/members', ['person_ids' => [$ruth], 'names' => ['john musyoka']])->assertOk()->assertJsonPath('data.added', 0)->assertJsonPath('data.already', 2);
        $this->postJson('/api/facilities/teams/welcome/members', ['person_ids' => [$ruth]])->assertCreated();
        $this->postJson('/api/facilities/teams/nope/members', ['person_ids' => [$ruth]])->assertNotFound();
        $this->postJson('/api/facilities/teams/ushering/members', [])->assertStatus(422);

        $team = $this->team('ushering');
        $this->assertSame(['Ruth Mwende', 'Paul Kioko', 'John Musyoka'], array_column($team, 'name'));
        $this->assertSame([false, false, true], array_column($team, 'typed'));
        $counts = $this->teams()['counts'];
        $this->assertSame([2, 3, 1], [$counts['teams'], $counts['people'], $counts['not_on_a_team']]);

        $ids = array_column($team, 'id');
        $this->putJson('/api/facilities/teams/ushering/order', ['ids' => [$ids[2], $ids[0]]])->assertOk();
        $this->assertSame(['John Musyoka', 'Ruth Mwende', 'Paul Kioko'], array_column($this->team('ushering'), 'name'));
        $this->putJson('/api/facilities/teams/welcome/order', ['ids' => [$ids[0]]])->assertStatus(422);

        $this->deleteJson('/api/facilities/teams/members/'.$ids[1])->assertOk();
        $this->assertSame(['John Musyoka', 'Ruth Mwende'], array_column($this->team('ushering'), 'name'));

        // Their member page: on Welcome and Ushering.
        $mine = collect($this->getJson("/api/facilities/teams/person/{$ruth}")->assertOk()->json('data.duties'))->filter(fn ($d) => $d['member'])->pluck('key')->sort()->values()->all();
        $this->assertSame(['ushering', 'welcome'], $mine);
    }

    public function test_only_managers_change_teams_and_only_at_their_own_church(): void
    {
        $ruth = $this->person('Ruth Mwende');
        $theirs = $this->person('Other Person', $this->otherChurch);
        Sanctum::actingAs($this->senior);
        $this->postJson('/api/facilities/teams/ushering/members', ['person_ids' => [$theirs]])->assertStatus(422);
        $this->postJson('/api/facilities/teams/ushering/members', ['person_ids' => [$ruth]])->assertCreated();
        $id = DutyTeamMember::first()->id;

        Sanctum::actingAs($this->deacon);
        $this->assertCount(1, $this->team('ushering'));
        $this->postJson('/api/facilities/teams/ushering/members', ['names' => ['Someone']])->assertForbidden();
        $this->deleteJson('/api/facilities/teams/members/'.$id)->assertForbidden();
        $this->putJson('/api/facilities/teams/ushering/order', ['ids' => [$id]])->assertForbidden();

        Sanctum::actingAs($this->otherPastor);
        $this->assertSame([], $this->team('ushering'));
        $this->deleteJson('/api/facilities/teams/members/'.$id)->assertNotFound();
        $this->getJson("/api/facilities/teams/person/{$ruth}")->assertNotFound();
    }

    public function test_someone_who_left_is_kept_but_the_rota_skips_them(): void
    {
        Sanctum::actingAs($this->senior);
        $ruth = $this->person('Ruth Mwende');
        $paul = $this->person('Paul Kioko');
        $this->postJson('/api/facilities/teams/ushering/members', ['person_ids' => [$ruth, $paul]])->assertCreated();
        Person::find($paul)->update(['status' => 'transferred_out']);

        $team = $this->team('ushering');
        $this->assertSame([false, true], array_column($team, 'away'));
        $this->assertSame(1, $this->teams()['counts']['people']);

        $sunday = CarbonImmutable::now('Africa/Nairobi')->next(CarbonImmutable::SUNDAY);
        $this->postJson('/api/rota/fill', ['from' => $sunday->toDateString(), 'to' => $sunday->addWeeks(2)->toDateString()])->assertOk();
        $this->assertSame([$ruth], DutyRota::where('duty', 'ushering')->distinct()->pluck('person_id')->all());
        // Their turns show on the team.
        $this->assertSame($sunday->toDateString(), $this->team('ushering')[0]['next']);

        // A duty taken out of Settings takes its team with it.
        $setup = $this->getJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id)->assertOk()->json('data');
        $this->assertSame(2, collect($setup['duties'])->firstWhere('key', 'ushering')['team_count']);
        $this->postJson('/api/facilities/teams/cleaning/members', ['names' => ['Mary Cleaner']])->assertCreated();
        $without = array_values(array_filter($setup['duties'], fn ($d) => $d['key'] !== 'cleaning'));
        $this->putJson('/api/settings/facilities-setup?territory_id='.$this->myChurch->id, ['duties' => $without, 'kinds' => $setup['kinds']])->assertOk();
        $this->assertSame(0, DutyTeamMember::where('duty', 'cleaning')->count());
        $this->assertSame(2, DutyTeamMember::where('duty', 'ushering')->count());
    }

    public function test_the_migration_moves_the_old_teams_in_order(): void
    {
        $ruth = $this->person('Ruth Mwende');
        $migration = require database_path('migrations/2026_10_15_090000_create_duty_team_members_table.php');
        $migration->down();
        $this->myChurch->forceFill(['metadata' => ['facilities_setup' => ['kinds' => [], 'duties' => [
            ['key' => 'ushering', 'label' => 'Ushering', 'icon' => 'ri-door-open-line', 'colour' => 'primary', 'active' => true, 'needed' => 2, 'team' => [['person_id' => null, 'name' => 'John Kioko'], ['person_id' => $ruth, 'name' => 'Ruth Mwende'], ['person_id' => $ruth, 'name' => 'Ruth Mwende']]],
        ]]]])->saveQuietly();

        $migration->up();
        $this->assertSame([['John Kioko', null], [null, $ruth]], DB::table('duty_team_members')->orderBy('position')->get()->map(fn ($r) => [$r->name, $r->person_id])->all());
        $this->assertArrayNotHasKey('team', $this->myChurch->fresh()->metadata['facilities_setup']['duties'][0]);

        $migration->down();
        $this->assertSame(['John Kioko', 'Ruth Mwende'], array_column($this->myChurch->fresh()->metadata['facilities_setup']['duties'][0]['team'], 'name'));
        $migration->up();
    }
}
