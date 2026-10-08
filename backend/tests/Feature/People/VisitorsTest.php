<?php

namespace Tests\Feature\People;

use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\MessageLog;
use App\Models\Person;
use App\Models\VisitorVisit;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P2 (docs/specs/people-and-care-spec.md): visitors and their
 * follow-up - quick Sunday entry, consent before any SMS, becoming a member,
 * counts for the region, and visitors' details removed after the months the
 * church chose.
 */
class VisitorsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $elder;

    private $reader;

    private $otherPastor;

    private $regionLeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $visitors = ['church.visitors.visitors.read', 'church.visitors.visitors.manage'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$visitors, 'church.members.members.read', 'church.members.members.manage', 'church.messages.messages.read', 'church.messages.messages.send']);
        $this->elder = $this->userWithRole('elder', 'Elder', 'church', $this->myChurch->id, [...$visitors, 'church.members.members.read']);
        $this->reader = $this->userWithRole('committee', 'Church Committee Member', 'church', $this->myChurch->id, ['church.visitors.visitors.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, $visitors);
        $this->regionLeader = $this->userWithRole('regionlead', 'Region Lead', 'region', $this->region->id, ['region.visitors.below.read', 'region.messages.messages.read', 'region.messages.messages.send']);
    }

    private function sunday(array $rows, array $over = [])
    {
        return $this->postJson('/api/visitors/batch', $over + ['on' => now('Africa/Nairobi')->toDateString(), 'rows' => $rows]);
    }

    public function test_sunday_entry_adds_new_visitors_and_a_visit_to_people_we_know(): void
    {
        $category = GatheringCategory::create(['name' => 'Services', 'slug' => 'services']);
        $service = GatheringType::create(['gathering_category_id' => $category->id, 'territory_id' => $this->myChurch->id, 'name' => 'Sunday service', 'slug' => 'sunday-service', 'is_active' => true]);
        $member = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Peter', 'last_name' => 'Musyoka', 'phone' => '+254733000111', 'status' => 'member']);
        Sanctum::actingAs($this->senior);

        $r = $this->sunday([
            ['name' => 'Mary Wanjiru Mutua', 'phone' => '0712 345 678', 'first_time' => true, 'how_heard' => 'Invited by a friend', 'consent_contact' => true, 'prayer_request' => 'For a job'],
            ['name' => 'John', 'phone' => '0799000222', 'first_time' => true, 'consent_contact' => false, 'wants_visit' => true],
            ['name' => 'Peter Musyoka', 'phone' => '0733000111'],
        ], ['gathering_type_id' => $service->id, 'welcome_sms' => true, 'on' => now('Africa/Nairobi')->subDays(7)->toDateString()])
            ->assertCreated()->json('data');
        $this->assertSame([2, 0, 1, 1], [$r['created'], $r['returning'], $r['members'], $r['sms_sent']], 'only Mary said yes to contact');
        $this->assertSame(1, MessageLog::where('kind', 'visitor')->count());
        $mary = Person::where('first_name', 'Mary')->first();
        $this->assertSame(['Wanjiru Mutua', 'new', 'visitor', 1], [$mary->last_name, $mary->stage, $mary->status, $mary->visit_count]);
        $this->assertSame(0, $member->fresh()->visit_count, "a member's phone isn't a visit");
        $this->assertNotSame('For a job', DB::table('visitor_visits')->where('person_id', $mary->id)->value('prayer_request'), 'encrypted at rest');

        // Mary comes back: the same person, a second visit, now "returning".
        $r = $this->sunday([['name' => 'Mary M', 'phone' => '+254712345678', 'first_time' => true]])->assertCreated()->json('data');
        $this->assertSame([0, 1], [$r['created'], $r['returning']]);
        $this->assertSame(1, Person::where('phone', '+254712345678')->count(), 'not duplicated');
        $this->assertSame(['returning', 2], [$mary->fresh()->stage, $mary->fresh()->visit_count]);
        $this->assertSame(2, $this->getJson('/api/visitors/check?phone=0712345678')->json('data.visits'));
        $this->sunday([['name' => 'Mary', 'phone' => '0712345678']])->assertCreated();
        $this->assertSame(2, $mary->fresh()->visit_count, 'one visit a day');

        $this->sunday([['name' => 'Bad', 'phone' => '12']])->assertStatus(422)->assertJsonValidationErrors('rows.0.phone');
        $this->sunday([['name' => 'X', 'how_heard' => 'Billboard']])->assertStatus(422);
        $this->sunday([['name' => 'Tomorrow']], ['on' => now('Africa/Nairobi')->addDay()->toDateString()])->assertStatus(422);

        // The board, its filters and the visitor page.
        $items = $this->getJson('/api/visitors')->assertOk()->json('data.items');
        $this->assertEqualsCanonicalizing(['Mary Wanjiru Mutua', 'John'], array_column($items, 'name'));
        $this->assertSame(['John'], array_column($this->getJson('/api/visitors?q=0799')->json('data.items'), 'name'));
        $this->assertSame(['Mary Wanjiru Mutua'], array_column($this->getJson('/api/visitors?stage[]=returning')->json('data.items'), 'name'));
        $page = $this->getJson("/api/visitors/{$mary->id}")->assertOk()->json('data');
        $this->assertCount(2, $page['visit_list']);
        $this->assertSame('For a job', collect($page['visit_list'])->last()['prayer_request']);
        $this->assertSame('Sunday service', collect($page['visit_list'])->last()['gathering']);
    }

    public function test_follow_up_moves_them_on_and_says_who_is_due(): void
    {
        config(['audit.console' => true]);
        Sanctum::actingAs($this->elder);
        $this->sunday([['name' => 'Ann Mwende', 'phone' => '0712000333']], ['on' => now('Africa/Nairobi')->subDays(5)->toDateString()])->assertCreated();
        $ann = Person::where('first_name', 'Ann')->first();

        $o = $this->getJson('/api/visitors/overview')->assertOk()->json('data');
        $this->assertSame(1, $o['first_timers']);
        $this->assertSame([1, 1], [$o['due_count'], $o['overdue']], 'due 3 days after the visit, and late now');
        $this->assertSame(['Ann Mwende'], array_column($this->getJson('/api/visitors?due=1')->json('data.items'), 'name'));

        $this->postJson("/api/visitors/{$ann->id}/followups", ['type' => 'call', 'outcome' => 'reached', 'note' => 'She asked about the choir', 'done_on' => now('Africa/Nairobi')->toDateString(), 'next_on' => now('Africa/Nairobi')->addDays(10)->toDateString()])
            ->assertCreated()->assertJsonPath('data.stage', 'contacted')->assertJsonPath('data.overdue', false);
        $this->assertSame(0, $this->getJson('/api/visitors/overview')->json('data.due_count'), 'the next step is more than a week away');
        $this->assertNotSame('She asked about the choir', DB::table('visitor_followups')->value('note'));

        $this->postJson("/api/visitors/{$ann->id}/stage", ['stage' => 'regular'])->assertOk()->assertJsonPath('data.stage', 'regular');
        $this->postJson("/api/visitors/{$ann->id}/stage", ['stage' => 'member'])->assertStatus(422);
        $this->postJson("/api/visitors/{$ann->id}/assign", ['user_id' => $this->senior->id])->assertOk()->assertJsonPath('data.assigned.id', $this->senior->id);
        $this->assertSame(1, $this->senior->notifications()->count(), 'told they follow her up');
        $this->postJson("/api/visitors/{$ann->id}/assign", ['user_id' => $this->reader->id])->assertStatus(422);

        $history = $this->getJson("/api/visitors/{$ann->id}/history")->assertOk()->json('data');
        $sentences = collect($history)->pluck('sentence')->implode('|');
        $this->assertStringContainsString('recorded their first visit', $sentences);
        $this->assertStringContainsString('logged a follow-up (phone call)', $sentences);
        $this->assertStringNotContainsString('choir', json_encode(DB::table('audits')->get()));

        // The calendar shows the count, never the name.
        $this->postJson("/api/visitors/{$ann->id}/followups", ['type' => 'visit', 'outcome' => 'will_come', 'done_on' => now('Africa/Nairobi')->toDateString(), 'next_on' => now('Africa/Nairobi')->addDay()->toDateString()])->assertCreated();
        $feed = app(\App\Services\Calendar\LifeFeed::class)->occurrences($this->myChurch, now('Africa/Nairobi')->toImmutable()->startOfMonth(), now('Africa/Nairobi')->toImmutable()->addMonth(), [], ['ours'], ['due'], $this->elder);
        $line = collect($feed)->firstWhere('key', 'followups-'.now('Africa/Nairobi')->addDay()->toDateString());
        $this->assertSame('1 visitor follow-up due', $line['title']);
        $this->assertStringNotContainsString('Ann', json_encode($feed));

        Sanctum::actingAs($this->reader);
        $this->getJson("/api/visitors/{$ann->id}")->assertOk()->assertJsonPath('data.can.manage', false);
        $this->postJson("/api/visitors/{$ann->id}/followups", ['type' => 'call', 'outcome' => 'reached', 'done_on' => now()->toDateString()])->assertForbidden();
        $this->sunday([['name' => 'Nope']])->assertForbidden();
    }

    public function test_an_sms_needs_consent_and_is_logged(): void
    {
        Sanctum::actingAs($this->senior);
        $this->sunday([['name' => 'Yes Please', 'phone' => '0712000444', 'consent_contact' => true], ['name' => 'No Thanks', 'phone' => '0712000555']])->assertCreated();
        $yes = Person::where('first_name', 'Yes')->first();
        $no = Person::where('first_name', 'No')->first();

        $this->postJson("/api/visitors/{$no->id}/sms", ['text' => 'Hello'])->assertStatus(422);
        $this->assertFalse($this->getJson("/api/visitors/{$no->id}")->json('data.can.sms'));
        $this->postJson("/api/visitors/{$yes->id}/sms", ['text' => 'We missed you on Sunday'])->assertOk();
        $log = MessageLog::where('kind', 'visitor')->latest('id')->first();
        $this->assertSame('+254712000444', $log->to);
        $this->assertSame('sms', $this->getJson("/api/visitors/{$yes->id}")->json('data.last_followup.type'), 'the SMS is on their follow-up');

        // Messages: our register as an audience - members, and visitors who said yes.
        Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Grace', 'last_name' => 'Member', 'phone' => '+254711222333', 'status' => 'member']);
        $this->assertSame(['members' => 1, 'visitors' => 1], $this->getJson('/api/messages/options')->assertOk()->json('data.register'));
        $names = $this->postJson('/api/messages/preview', ['audience' => ['register' => ['visitors' => true]], 'channel' => 'sms', 'body' => 'Hi'])->assertOk()->json('data.names');
        $this->assertSame(['Yes Please'], $names);
        $this->assertSame(2, $this->postJson('/api/messages/preview', ['audience' => ['register' => ['visitors' => true, 'members' => true]], 'channel' => 'sms', 'body' => 'Hi'])->json('data.people'));
        Sanctum::actingAs($this->regionLeader);
        $this->postJson('/api/messages/preview', ['audience' => ['register' => ['members' => true]], 'channel' => 'sms', 'body' => 'Hi'])->assertStatus(422);
    }

    public function test_became_a_member_turns_the_same_person_into_a_member(): void
    {
        Sanctum::actingAs($this->elder);
        $this->sunday([['name' => 'Ruth Kioko', 'phone' => '0712000666']])->assertCreated();
        $ruth = Person::where('first_name', 'Ruth')->first();
        $this->postJson("/api/visitors/{$ruth->id}/become-member")->assertForbidden();
        $this->assertFalse($this->getJson("/api/visitors/{$ruth->id}")->json('data.can.make_member'));

        Sanctum::actingAs($this->senior);
        $this->postJson("/api/visitors/{$ruth->id}/become-member")->assertOk()->assertJsonPath('data.status', 'member')->assertJsonPath('data.stage', 'member');
        $this->assertSame(1, Person::count(), 'the same row');
        $this->assertSame(['Ruth Kioko'], array_column($this->getJson('/api/people')->json('data.items'), 'name'));
        $this->assertSame('conversion', $this->getJson("/api/people/{$ruth->id}")->json('data.how_joined'));
        $this->assertContains('visited', array_column($this->getJson("/api/people/{$ruth->id}")->json('data.journey'), 'kind'));
        $this->assertSame(1, $this->getJson('/api/visitors/overview')->json('data.became_members'));
        $this->assertSame(['Ruth Kioko'], array_column($this->getJson('/api/visitors')->json('data.items'), 'name'), 'on the board for a while as a member');
        $this->postJson("/api/visitors/{$ruth->id}/become-member")->assertStatus(422);
    }

    public function test_names_stay_home_and_the_region_sees_counts(): void
    {
        Sanctum::actingAs($this->senior);
        $this->sunday([['name' => 'Mary Mutua', 'phone' => '0712345678', 'first_time' => true]])->assertCreated();
        $mary = Person::first();

        Sanctum::actingAs($this->otherPastor);
        $this->assertSame(0, $this->getJson('/api/visitors')->assertOk()->json('data.total'));
        $this->getJson("/api/visitors/{$mary->id}")->assertNotFound();
        $this->postJson("/api/visitors/{$mary->id}/followups", ['type' => 'call', 'outcome' => 'reached', 'done_on' => now()->toDateString()])->assertNotFound();

        Sanctum::actingAs($this->regionLeader);
        $this->getJson('/api/visitors')->assertForbidden();
        $this->getJson("/api/visitors/{$mary->id}?territory_id={$this->myChurch->id}")->assertForbidden();
        $totals = $this->getJson('/api/visitors/totals')->assertOk()->json('data');
        $this->assertSame([1, 1], [$totals['visitors_this_month'], $totals['first_timers_this_month']]);
        $row = collect($totals['rows'])->firstWhere('church.id', $this->myChurch->id);
        $this->assertTrue($row['records_visitors']);
        $json = json_encode($totals);
        foreach (['Mary', 'Mutua', '712345678', '"phone"', '"note"', '"name":"Mary'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        Sanctum::actingAs($this->senior);
        $this->getJson('/api/visitors/totals')->assertForbidden();
    }

    public function test_retention_removes_old_visitors_details_and_leaves_members_alone(): void
    {
        $old = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Old', 'last_name' => 'Visitor', 'phone' => '+254712000777', 'status' => 'visitor', 'stage' => 'new', 'first_visit_on' => now()->subMonths(14), 'last_visit_on' => now()->subMonths(13), 'visit_count' => 1]);
        VisitorVisit::create(['person_id' => $old->id, 'territory_id' => $this->myChurch->id, 'on' => now()->subMonths(13), 'first_time' => true, 'prayer_request' => 'Healing']);
        $recent = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Recent', 'last_name' => 'Visitor', 'status' => 'visitor', 'stage' => 'new', 'last_visit_on' => now()->subMonth(), 'visit_count' => 1]);
        $member = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Long', 'last_name' => 'Member', 'status' => 'member', 'stage' => 'member', 'last_visit_on' => now()->subYears(3)]);
        $elsewhere = Person::create(['territory_id' => $this->otherChurch->id, 'first_name' => 'Other', 'last_name' => 'Church', 'status' => 'visitor', 'stage' => 'new', 'last_visit_on' => now()->subYears(2)]);

        $this->artisan('people:retention')->assertSuccessful();
        $this->assertNull($old->fresh()->anonymised_at, 'never, by default');

        app(Settings::class)->setMany($this->myChurch, 'church', 'visitors', ['people.retention_visitor_months' => '12'], [], [], $this->senior);
        $this->artisan('people:retention')->assertSuccessful();
        $old = $old->fresh();
        $this->assertSame(['Removed person', null], [$old->name, $old->phone]);
        $this->assertNull(VisitorVisit::where('person_id', $old->id)->first()->prayer_request);
        $this->assertSame(1, $old->visit_count, 'the counts stay');
        $this->assertNull($recent->fresh()->anonymised_at);
        $this->assertNull($member->fresh()->anonymised_at, 'members are never touched');
        $this->assertNull($elsewhere->fresh()->anonymised_at, 'their church keeps the default');
    }
}
