<?php

namespace Tests\Feature\People;

use App\Models\Person;
use App\Models\PersonTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P1 (docs/specs/people-and-care-spec.md): the church's
 * private member register - names stay with the church, the region and
 * diocese see counts, private fields are encrypted and never audited.
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

    public function test_the_pastor_keeps_the_register_and_private_fields_are_encrypted_and_never_audited(): void
    {
        config(['audit.console' => true]); // auditing is off for console runs - switch it on, as in a web request
        Sanctum::actingAs($this->senior);
        $id = $this->postJson('/api/people', [
            'first_name' => 'Mary', 'last_name' => 'Mutua', 'gender' => 'female', 'date_of_birth' => '2000-05-02', 'phone' => '0712 345 678',
            'address' => 'Kima village', 'national_id' => 'ID99887766', 'notes' => 'Prays for the sick', 'next_of_kin_phone' => '0722000111',
            'joined_on' => '2020-03-01', 'how_joined' => 'conversion', 'baptised_on' => '2020-06-01',
        ])->assertCreated()->assertJsonPath('data.phone', '+254712345678')->json('data.id');

        $raw = DB::table('people')->find($id);
        foreach (['address' => 'Kima village', 'national_id' => 'ID99887766', 'notes' => 'Prays for the sick'] as $field => $plain) {
            $this->assertNotSame($plain, $raw->{$field}, "{$field} is encrypted at rest");
        }
        $this->getJson("/api/people/{$id}")->assertOk()->assertJsonPath('data.national_id', 'ID99887766')->assertJsonPath('data.age_band', 'youth');

        $this->putJson("/api/people/{$id}", ['first_name' => 'Mary', 'last_name' => 'Mutua', 'notes' => 'Leads the prayer group'])->assertOk();
        $audits = DB::table('audits')->where('auditable_type', 'person')->where('auditable_id', $id)->get();
        $this->assertGreaterThanOrEqual(2, $audits->count());
        foreach ($audits as $audit) {
            $this->assertStringNotContainsString('Prays for the sick', (string) $audit->new_values.$audit->old_values);
            $this->assertStringNotContainsString('ID99887766', (string) $audit->new_values.$audit->old_values);
        }
        $this->assertStringContainsString('changed notes', collect($this->getJson("/api/people/{$id}/history")->json('data'))->pluck('sentence')->implode('|'));

        $this->postJson("/api/people/{$id}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->assertSame(0, $this->getJson('/api/people')->json('data.total'));
        $this->postJson("/api/people/{$id}/restore")->assertOk();

        $this->postJson("/api/people/{$id}/anonymise")->assertStatus(422);
        $this->postJson("/api/people/{$id}/anonymise", ['confirm' => 'REMOVE'])->assertOk()
            ->assertJsonPath('data.name', 'Removed person')->assertJsonPath('data.phone', null)->assertJsonPath('data.national_id', null);
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
        $this->getJson("/api/people/{$mary->id}/photo?territory_id={$this->myChurch->id}")->assertForbidden();
        $totals = $this->getJson('/api/people/totals')->assertOk()->json('data');
        $this->assertSame(1, $totals['active']);
        $row = collect($totals['rows'])->firstWhere('church.id', $this->myChurch->id);
        $this->assertSame(1, $row['active']);
        $this->assertTrue($row['keeps_register']);
        $json = json_encode($totals);
        foreach (['Mary', 'Mutua', '712345678', '"phone"', '"email"', '"address"', '"notes"'] as $needle) {
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
        $this->person(['phone' => '+254712345678', 'gender' => 'female', 'date_of_birth' => now()->subYears(20)->toDateString(), 'baptised_on' => '2022-01-01']);
        $this->person(['first_name' => 'John', 'last_name' => 'Kioko', 'gender' => 'male', 'date_of_birth' => now()->subYears(70)->toDateString(), 'status' => 'inactive']);
        Sanctum::actingAs($this->senior);

        $this->postJson('/api/people', ['first_name' => 'Ann', 'last_name' => 'Mwende', 'phone' => '0712345678'])
            ->assertStatus(422)->assertJsonPath('duplicate.name', 'Mary Mutua');
        $this->postJson('/api/people', ['first_name' => 'Ann', 'last_name' => 'Mwende', 'phone' => '0712345678', 'confirm_duplicate' => true])->assertCreated();
        $this->assertCount(2, $this->getJson('/api/people/check?phone=+254712345678')->json('data'));

        $this->assertSame(['Ann Mwende', 'John Kioko', 'Mary Mutua'], array_column($this->getJson('/api/people')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?status[]=inactive')->json('data.items'), 'name'));
        $this->assertSame(['Mary Mutua'], array_column($this->getJson('/api/people?age_band=youth')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?age_band=seniors')->json('data.items'), 'name'));
        $this->assertSame(['Mary Mutua'], array_column($this->getJson('/api/people?baptised=1')->json('data.items'), 'name'));
        $this->assertSame(['John Kioko'], array_column($this->getJson('/api/people?q=kio')->json('data.items'), 'name'));
        $this->assertSame(['Mary Mutua'], array_column($this->getJson('/api/people?q=0712345678&status[]=member&gender=female')->json('data.items'), 'name'));
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

    public function test_the_overview_insights_and_photo(): void
    {
        Storage::fake('local');
        $this->person(['gender' => 'female', 'date_of_birth' => now()->subYears(25)->toDateString(), 'joined_on' => now()->toDateString(), 'baptised_on' => '2021-01-01']);
        $this->person(['first_name' => 'Paul', 'gender' => 'male', 'date_of_birth' => now()->subYears(40)->setMonth(now()->month)->toDateString()]);
        Sanctum::actingAs($this->senior);

        $o = $this->getJson('/api/people/overview')->assertOk()->json('data');
        $this->assertSame([2, 1, 1, 50], [$o['active'], $o['new_this_month'], $o['baptised'], $o['baptised_share']]);
        $this->assertCount(12, $o['joins']);
        $this->assertTrue($o['can']['export']);

        $i = $this->getJson('/api/people/insights')->assertOk()->json('data');
        $youth = collect($i['pyramid'])->firstWhere('key', 'youth');
        $this->assertSame([0, 1], [$youth['male'], $youth['female']]);
        $this->assertContains('Paul Mutua', array_column($i['birthdays'], 'name'));
        $this->assertSame(2, $this->getJson('/api/people/register-counts')->json('data.total'));

        $mary = Person::where('first_name', 'Mary')->first();
        $this->postJson("/api/people/{$mary->id}/photo", ['photo' => UploadedFile::fake()->image('mary.jpg', 800, 600)])->assertOk()->assertJsonPath('data.has_photo', true);
        $this->get("/api/people/{$mary->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postJson("/api/people/{$mary->id}/photo", ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->deleteJson("/api/people/{$mary->id}/photo")->assertOk()->assertJsonPath('data.has_photo', false);
    }
}
