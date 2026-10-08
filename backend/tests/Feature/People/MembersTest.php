<?php

namespace Tests\Feature\People;

use App\Models\Person;
use App\Models\PersonTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P1 (docs/specs/people-and-care-spec.md): the church's
 * private member register - a name, phone, area, gender and Sunday school or
 * main church, nothing more; names stay with the church, the region and
 * diocese see counts.
 */
class MembersTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $secretary;

    private $otherPastor;

    private $regionLeader;

    private $dioceseLeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $manage = ['church.members.members.read', 'church.members.members.manage'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$manage, 'church.members.members.export']);
        $this->secretary = $this->userWithRole('secretary', 'Church Secretary', 'church', $this->myChurch->id, ['church.members.members.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, $manage);
        $this->regionLeader = $this->userWithRole('regionlead', 'Region Lead', 'region', $this->region->id, ['region.members.below.read']);
        $this->dioceseLeader = $this->userWithRole('dioceselead', 'Diocese Lead', 'diocese', $this->diocese->id, ['diocese.members.below.read']);
    }

    private function person(array $over = []): Person
    {
        return Person::create($over + ['territory_id' => $this->myChurch->id, 'first_name' => 'Mary', 'last_name' => 'Mutua', 'status' => 'member', 'joined_on' => '2024-01-10']);
    }

    public function test_the_pastor_keeps_a_short_register_and_nothing_more_is_stored(): void
    {
        config(['audit.console' => true]); // auditing is off for console runs - switch it on, as in a web request
        Sanctum::actingAs($this->senior);
        $id = $this->postJson('/api/people', [
            'first_name' => 'Mary', 'last_name' => 'Mutua', 'gender' => 'female', 'phone' => '0712 345 678', 'area' => 'kasikeu', 'congregation' => 'main_church',
            'national_id' => 'ID99887766', 'date_of_birth' => '2000-05-02', 'address' => 'Kima village',
        ])->assertCreated()->assertJsonPath('data.phone', '+254712345678')->assertJsonPath('data.area', 'Kasikeu')->json('data.id');

        $data = $this->getJson("/api/people/{$id}")->assertOk()->json('data');
        foreach (['national_id', 'date_of_birth', 'address', 'email', 'notes', 'has_photo'] as $gone) {
            $this->assertArrayNotHasKey($gone, $data, "{$gone} is no longer kept");
        }
        $this->assertStringNotContainsString('ID99887766', json_encode(DB::table('people')->find($id)));
        $this->assertSame('main_church', $data['congregation']);

        $this->putJson("/api/people/{$id}", ['area' => 'Mbuvo', 'baptised_on' => '2021-03-01'])->assertOk()->assertJsonPath('data.area', 'Mbuvo');
        $this->assertStringContainsString('changed area, baptism date', collect($this->getJson("/api/people/{$id}/history")->json('data'))->pluck('sentence')->implode('|'));

        $this->postJson("/api/people/{$id}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->assertSame(0, $this->getJson('/api/people')->json('data.total'));
        $this->postJson("/api/people/{$id}/restore")->assertOk();

        $this->postJson("/api/people/{$id}/anonymise")->assertStatus(422);
        $this->postJson("/api/people/{$id}/anonymise", ['confirm' => 'REMOVE'])->assertOk()
            ->assertJsonPath('data.name', 'Removed person')->assertJsonPath('data.phone', null)->assertJsonPath('data.area', null);
        $this->assertNotNull(Person::find($id), 'the row stays, so counts stay true');
    }

    public function test_other_churches_the_region_and_the_diocese_never_see_names(): void
    {
        $mary = $this->person(['phone' => '+254712345678']);

        Sanctum::actingAs($this->otherPastor);
        $this->assertSame(0, $this->getJson('/api/people')->assertOk()->json('data.total'), 'their own (empty) register');
        $this->getJson("/api/people/{$mary->id}")->assertNotFound();

        Sanctum::actingAs($this->regionLeader);
        $this->getJson('/api/people')->assertForbidden();
        $this->getJson("/api/people?territory_id={$this->myChurch->id}")->assertForbidden();
        $this->getJson("/api/people/{$mary->id}?territory_id={$this->myChurch->id}")->assertForbidden();
        $totals = $this->getJson('/api/people/totals')->assertOk()->json('data');
        $this->assertSame(1, $totals['active']);
        $row = collect($totals['rows'])->firstWhere('church.id', $this->myChurch->id);
        $this->assertSame(1, $row['active']);
        $this->assertTrue($row['keeps_register']);
        $json = json_encode($totals);
        foreach (['Mary', 'Mutua', '712345678', '"phone"', '"area"', '"email"', '"notes"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        Sanctum::actingAs($this->dioceseLeader);
        $this->assertSame(1, $this->getJson('/api/people/totals')->assertOk()->json('data.active'));
        Sanctum::actingAs($this->senior);
        $this->getJson('/api/people/totals')->assertForbidden();
    }

    public function test_reading_is_not_changing_and_export_needs_its_own_permission(): void
    {
        $mary = $this->person();
        Sanctum::actingAs($this->secretary);
        $this->getJson("/api/people/{$mary->id}")->assertOk()->assertJsonPath('data.can.manage', false);
        $this->putJson("/api/people/{$mary->id}", ['first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
        $this->postJson('/api/people', ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();
        $this->postJson('/api/reports', ['report_key' => 'members.directory', 'format' => 'xlsx', 'territory_id' => $this->myChurch->id])->assertForbidden();
    }

    public function test_a_known_phone_needs_confirming_and_the_filters_find_the_right_people(): void
    {
        $this->person(['phone' => '+254712345678', 'gender' => 'female', 'area' => 'Kasikeu', 'congregation' => 'main_church', 'baptised_on' => '2022-01-01']);
        $this->person(['first_name' => 'John', 'last_name' => 'Kioko', 'gender' => 'male', 'area' => 'Mbuvo', 'congregation' => 'sunday_school', 'status' => 'inactive']);
        Sanctum::actingAs($this->senior);

        $this->postJson('/api/people', ['first_name' => 'Ann', 'last_name' => 'Mwende', 'phone' => '0712345678'])
            ->assertStatus(422)->assertJsonPath('duplicate.name', 'Mary Mutua');
        $this->postJson('/api/people', ['first_name' => 'Ann', 'last_name' => 'Mwende', 'phone' => '0712345678', 'confirm_duplicate' => true])->assertCreated();
        $this->assertCount(2, $this->getJson('/api/people/check?phone=+254712345678')->json('data'));

        $this->assertSame(['Ann Mwende', 'John Kioko', 'Mary Mutua'], array_column($this->getJson('/api/people')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?status[]=inactive')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?congregation=sunday_school')->json('data.items'), 'name'));
        $this->assertSame(['Mary Mutua'], array_column($this->getJson('/api/people?area=Kasikeu')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?q=mbuv')->json('data.items'), 'name'), 'search finds the area');
        $this->assertSame(['Mary Mutua'], array_column($this->getJson('/api/people?baptised=1')->json('data.items'), 'name'));
        $this->assertSame(['Ann Mwende', 'Mary Mutua'], array_column($this->getJson('/api/people?q=0712')->json('data.items'), 'name'), 'part of a phone');
    }

    public function test_a_transfer_out_marks_them_and_tells_the_receiving_church_only_when_asked(): void
    {
        $mary = $this->person(['phone' => '+254712345678']);
        $peter = $this->person(['first_name' => 'Peter', 'last_name' => 'Musyoka']);
        Sanctum::actingAs($this->senior);

        $this->postJson("/api/people/{$mary->id}/transfer-out", ['other_church_id' => $this->otherChurch->id, 'on' => now()->toDateString(), 'notify' => true])
            ->assertOk()->assertJsonPath('data.told', 1)->assertJsonPath('data.person.status', 'transferred_out');
        $this->assertSame(1, $this->otherPastor->notifications()->count());
        $this->assertStringContainsString('Mary Mutua', $this->otherPastor->notifications()->first()->data['title']);

        $this->postJson("/api/people/{$peter->id}/transfer-out", ['other_church_name' => 'AIC Wote', 'on' => now()->toDateString()])->assertOk()->assertJsonPath('data.told', 0);
        $this->assertSame(1, $this->otherPastor->notifications()->count(), 'not told without asking');
        $this->postJson("/api/people/{$peter->id}/transfer-out", ['on' => now()->toDateString()])->assertStatus(422);

        $id = $this->postJson('/api/people/transfer-in', ['first_name' => 'Grace', 'last_name' => 'Ndinda', 'other_church_name' => 'PCEA Machakos', 'on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.how_joined', 'transfer')->assertJsonPath('data.previous_church', 'PCEA Machakos')->json('data.id');
        $this->assertSame(1, PersonTransfer::where('person_id', $id)->where('direction', 'in')->count());

        $t = $this->getJson('/api/people/transfers')->assertOk()->json('data');
        $this->assertSame([1, 2], [$t['in'], $t['out']]);
        $this->assertNotContains($this->myChurch->id, array_column($t['churches'], 'id'), 'pick another church');

        Sanctum::actingAs($this->regionLeader);
        $row = collect($this->getJson('/api/people/totals')->json('data.rows'))->firstWhere('church.id', $this->myChurch->id);
        $this->assertSame([1, 2], [$row['transfers_in'], $row['transfers_out']]);
    }

    public function test_the_overview_insights_and_the_demographics_hint(): void
    {
        $this->person(['gender' => 'female', 'area' => 'Kasikeu', 'congregation' => 'main_church', 'joined_on' => now()->toDateString(), 'baptised_on' => '2021-01-01']);
        $this->person(['first_name' => 'Paul', 'gender' => 'male', 'area' => 'Kasikeu', 'congregation' => 'sunday_school']);
        $this->person(['first_name' => 'Joy', 'gender' => 'female', 'congregation' => 'sunday_school']);
        Sanctum::actingAs($this->senior);

        $o = $this->getJson('/api/people/overview')->assertOk()->json('data');
        $this->assertSame([3, 1, 1, 2], [$o['active'], $o['new_this_month'], $o['baptised'], $o['sunday_school']]);
        $this->assertCount(12, $o['joins']);
        $this->assertTrue($o['can']['export']);

        $i = $this->getJson('/api/people/insights')->assertOk()->json('data');
        $school = collect($i['groups'])->firstWhere('key', 'sunday_school');
        $this->assertSame([1, 1], [$school['male'], $school['female']]);
        $this->assertSame([['area' => 'Kasikeu', 'count' => 2], ['area' => 'Not given', 'count' => 1]], $i['areas']);

        $r = $this->getJson('/api/people/register-counts')->json('data');
        $this->assertSame([3, 1, 2, 1, 1], [$r['total'], $r['male'], $r['female'], $r['sunday_school_male'], $r['sunday_school_female']]);

        Sanctum::actingAs($this->regionLeader);
        $this->assertSame(2, $this->getJson('/api/people/totals')->json('data.sunday_school'));
    }

    public function test_many_members_at_once_and_finding_one_person_to_message(): void
    {
        $a = $this->person(['first_name' => 'Agnes', 'phone' => '+254712000101', 'area' => 'Kasikeu']);
        $b = $this->person(['first_name' => 'Brian', 'phone' => '+254712000102']);
        $demo = $this->person(['first_name' => 'Demo', 'phone' => '+254700000009']);
        $theirs = Person::create(['territory_id' => $this->otherChurch->id, 'first_name' => 'Agnes', 'last_name' => 'Elsewhere', 'phone' => '+254712000103', 'status' => 'member']);

        Sanctum::actingAs($this->secretary);
        $this->postJson('/api/people/bulk', ['ids' => [$a->id], 'action' => 'archive'])->assertForbidden();
        $found = $this->getJson('/api/people/search?q=agn')->assertOk()->json('data');
        $this->assertSame([$a->id], array_column($found, 'id'), 'own church only');
        $this->assertFalse($this->getJson('/api/people/search?q=demo')->json('data.0.can_text'), 'a demo number shows but is never texted');
        $this->assertSame([$a->id], array_column($this->getJson('/api/people/search?q=kasikeu')->json('data'), 'id'), 'by area too');

        Sanctum::actingAs($this->senior);
        $this->postJson('/api/people/bulk', ['ids' => [$a->id, $b->id, $theirs->id], 'action' => 'inactive'])
            ->assertOk()->assertJsonPath('data.done', 2)->assertJsonPath('data.skipped', 1);
        $this->assertSame(['inactive', 'inactive', 'member'], [$a->fresh()->status, $b->fresh()->status, $theirs->fresh()->status]);
        $this->postJson('/api/people/bulk', ['ids' => [$a->id, $demo->id], 'action' => 'archive'])->assertOk()->assertJsonPath('data.done', 2);
        $this->assertNotNull($a->fresh()->archived_at);

        Sanctum::actingAs($this->regionLeader);
        $this->getJson('/api/people/search?q=agn')->assertForbidden();
    }
}
