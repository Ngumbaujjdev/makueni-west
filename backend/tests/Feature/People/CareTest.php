<?php

namespace Tests\Feature\People;

use App\Models\CareRecord;
use App\Models\Person;
use App\Services\Calendar\LifeFeed;
use App\Services\Reports\MonthlyFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P3 (docs/specs/people-and-care-spec.md): pastoral care -
 * confidential notes stay with their author and the Senior Pastor, names
 * and notes never leave the church, and the care given feeds the monthly
 * report and the calendar without a name.
 */
class CareTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $associate;

    private $elder;

    private $deacon;

    private $otherPastor;

    private $regionLeader;

    private Person $mary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $care = ['church.pastoral.care.read', 'church.pastoral.care.manage'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$care, 'church.pastoral.care.confidential', 'church.members.members.manage']);
        $this->associate = $this->userWithRole('associate', 'Associate Pastor', 'church', $this->myChurch->id, $care);
        $this->elder = $this->userWithRole('elder', 'Elder', 'church', $this->myChurch->id, $care);
        $this->deacon = $this->userWithRole('deacon', 'Deacon', 'church', $this->myChurch->id, ['church.pastoral.care.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, $care);
        $this->regionLeader = $this->userWithRole('regionlead', 'Region Lead', 'region', $this->region->id, ['region.pastoral.below.read']);
        $this->mary = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Mary', 'last_name' => 'Mutua', 'phone' => '+254712345678', 'status' => 'member']);
    }

    private function today(): string
    {
        return now('Africa/Nairobi')->toDateString();
    }

    public function test_counselling_is_confidential_to_its_author_and_the_senior_pastor(): void
    {
        config(['audit.console' => true]);
        Sanctum::actingAs($this->elder);
        $id = $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'counselling', 'on' => $this->today(), 'note' => 'Marriage troubles', 'confidential' => false])
            ->assertCreated()->assertJsonPath('data.confidential', true)->assertJsonPath('data.status', 'open')->json('data.id');
        $this->assertNotSame('Marriage troubles', DB::table('care_records')->value('note'), 'encrypted at rest');
        $this->assertStringNotContainsString('Marriage', json_encode(DB::table('audits')->get()), 'never in the audits');
        $this->postJson("/api/care/{$id}/contacts", ['on' => $this->today(), 'type' => 'visit', 'note' => 'Second talk'])->assertCreated();

        $this->getJson("/api/care/{$id}")->assertJsonPath('data.note', 'Marriage troubles');
        Sanctum::actingAs($this->senior);
        $this->getJson("/api/care/{$id}")->assertJsonPath('data.note', 'Marriage troubles')->assertJsonPath('data.contacts.0.note', 'Second talk');
        Sanctum::actingAs($this->associate);
        $this->getJson("/api/care/{$id}")->assertOk()->assertJsonPath('data.note', null)->assertJsonPath('data.note_hidden', true)->assertJsonPath('data.contacts.0.note', null);
        $this->assertNull(collect($this->getJson('/api/care')->json('data.items'))->firstWhere('id', $id)['note']);
        $this->putJson("/api/care/{$id}", ['note' => 'Changed'])->assertForbidden();
        $this->postJson("/api/care/{$id}/contacts", ['on' => $this->today(), 'type' => 'call'])->assertForbidden();

        // A visit is done the day it's recorded; one with a next step stays open.
        Sanctum::actingAs($this->elder);
        $this->postJson('/api/care', ['person_name' => 'A neighbour', 'type' => 'home_visit', 'on' => $this->today()])->assertCreated()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.who', 'A neighbour');
        $this->postJson('/api/care', ['type' => 'home_visit', 'on' => $this->today()])->assertStatus(422)->assertJsonValidationErrors('person_name');
        Sanctum::actingAs($this->deacon);
        $this->postJson('/api/care', ['person_name' => 'X', 'type' => 'prayer', 'on' => $this->today()])->assertForbidden();
    }

    public function test_names_and_notes_stay_home_and_the_region_sees_counts(): void
    {
        Sanctum::actingAs($this->senior);
        $id = $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'home_visit', 'on' => $this->today(), 'note' => 'Prayed for her son'])->assertCreated()->json('data.id');
        $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'hospital', 'hospital' => 'Makueni Referral', 'on' => $this->today()])->assertCreated();

        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/care/{$id}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/care')->json('data.total'));
        $this->getJson("/api/people/{$this->mary->id}/care")->assertNotFound();
        $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'home_visit', 'on' => $this->today()])->assertStatus(422);

        Sanctum::actingAs($this->regionLeader);
        $this->getJson('/api/care')->assertForbidden();
        $t = $this->getJson('/api/care/totals')->assertOk()->json('data');
        $this->assertSame([2, 1], [$t['visits_this_month'], $t['in_hospital']]);
        $json = json_encode($t);
        foreach (['Mary', 'Mutua', 'Prayed', 'Makueni Referral', '"note"', '"who"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        // Removing Mary's details removes her care notes too; the counts stay.
        Sanctum::actingAs($this->senior);
        $this->postJson("/api/people/{$this->mary->id}/anonymise", ['confirm' => 'REMOVE'])->assertOk();
        $this->assertNull(CareRecord::find($id)->note);
        $this->assertSame(2, CareRecord::where('territory_id', $this->myChurch->id)->count());
    }

    public function test_hospital_prayer_bulk_and_the_hooks(): void
    {
        Sanctum::actingAs($this->senior);
        $h = $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'hospital', 'hospital' => 'Makueni Referral', 'on' => now('Africa/Nairobi')->subDays(9)->toDateString()])->assertCreated()->json('data.id');
        $list = $this->getJson('/api/care/hospital')->assertOk()->json('data');
        $this->assertSame([9, true], [$list[0]['days_since_visit'], $list[0]['overdue']], 'past the 7 days');
        $this->postJson("/api/care/{$h}/contacts", ['on' => $this->today(), 'type' => 'visit'])->assertCreated();
        $this->assertSame(0, $this->getJson('/api/care/hospital')->json('data.0.days_since_visit'), 'the newest visit counts');
        $this->postJson("/api/care/{$h}/discharge")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame([], $this->getJson('/api/care/hospital')->json('data'));

        $p = $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'prayer', 'on' => $this->today(), 'note' => 'For a job'])->json('data.id');
        $this->postJson("/api/care/{$h}/close", ['status' => 'answered'])->assertStatus(422);
        $this->postJson("/api/care/{$p}/close", ['status' => 'answered', 'testimony' => 'She got the job', 'share_testimony' => true])->assertOk()->assertJsonPath('data.status', 'answered');
        $this->assertSame('answered', $this->getJson('/api/care/prayer')->json('data.0.status'));

        $month = (int) now('Africa/Nairobi')->month;
        $figures = app(MonthlyFigures::class)->for($this->myChurch, (int) now('Africa/Nairobi')->year, $month);
        $this->assertGreaterThanOrEqual(1, $figures['pastoral']['visits']);
        $this->assertSame(['She got the job'], $figures['pastoral']['testimonies']);

        $c = $this->postJson('/api/care', ['person_id' => $this->mary->id, 'type' => 'concern', 'on' => $this->today(), 'next_on' => now('Africa/Nairobi')->addDay()->toDateString()])->json('data.id');
        $feed = app(LifeFeed::class)->occurrences($this->myChurch, now('Africa/Nairobi')->toImmutable()->startOfMonth(), now('Africa/Nairobi')->toImmutable()->addMonth(), [], ['ours'], ['due'], $this->senior);
        $line = collect($feed)->firstWhere('key', 'care-'.now('Africa/Nairobi')->addDay()->toDateString());
        $this->assertSame('1 pastoral visit due', $line['title']);
        $this->assertStringNotContainsString('Mary', json_encode($feed));

        $theirs = CareRecord::create(['territory_id' => $this->otherChurch->id, 'person_name' => 'X', 'type' => 'concern', 'status' => 'open', 'on' => $this->today()]);
        $this->postJson('/api/care/bulk', ['ids' => [$c, $theirs->id], 'action' => 'close'])->assertOk()->assertJsonPath('data.done', 1);
        $this->assertSame('open', $theirs->fresh()->status);
        $this->postJson('/api/care/bulk', ['ids' => [$c], 'action' => 'assign', 'user_id' => $this->elder->id])->assertOk();
        $this->assertContains($this->elder->id, CareRecord::find($c)->carers->pluck('id')->all());

        $o = $this->getJson('/api/care/overview')->assertOk()->json('data');
        $this->assertSame(1, $o['prayer_answered']);
        $this->assertCount(12, $o['visits_series']);
        $this->assertCount(3, $this->getJson("/api/people/{$this->mary->id}/care")->json('data'), "Mary's Care tab: hospital, prayer, concern");
    }
}
