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
 * follow-up - quick Sunday entry (name, phone, area), no SMS to anyone who
 * asked not to be texted or to a demo number, becoming a member,
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
            ['name' => 'Mary Wanjiru Mutua', 'phone' => '0712 345 678', 'area' => 'kasikeu'],
            ['name' => 'John', 'area' => 'Mbuvo'],
            ['name' => 'Demo Visitor', 'phone' => '+254700000001'],
            ['name' => 'Peter Musyoka', 'phone' => '0733000111'],
        ], ['gathering_type_id' => $service->id, 'welcome_sms' => true, 'on' => now('Africa/Nairobi')->subDays(7)->toDateString()])
            ->assertCreated()->json('data');
        $this->assertSame([3, 0, 1, 1], [$r['created'], $r['returning'], $r['members'], $r['sms_sent']], 'only Mary has a real phone - John has none, the demo number is never texted');
        $this->assertSame(1, MessageLog::where('kind', 'visitor')->count());
        $mary = Person::where('first_name', 'Mary')->first();
        $this->assertSame(['Wanjiru Mutua', 'Kasikeu', 'new', 'visitor', 1, true], [$mary->last_name, $mary->area, $mary->stage, $mary->status, $mary->visit_count, $mary->consent_contact]);
        $this->assertFalse(Person::where('first_name', 'John')->first()->consent_contact, 'no phone, nothing to consent to');
        $this->assertSame(0, $member->fresh()->visit_count, "a member's phone isn't a visit");

        // Mary comes back: the same person, a second visit, now "returning".
        $r = $this->sunday([['name' => 'Mary M', 'phone' => '+254712345678']])->assertCreated()->json('data');
        $this->assertSame([0, 1], [$r['created'], $r['returning']]);
        $this->assertSame(1, Person::where('phone', '+254712345678')->count(), 'not duplicated');
        $this->assertSame(['returning', 2], [$mary->fresh()->stage, $mary->fresh()->visit_count]);
        $this->assertSame(2, $this->getJson('/api/visitors/check?phone=0712345678')->json('data.visits'));
        $this->sunday([['name' => 'Mary', 'phone' => '0712345678']])->assertCreated();
        $this->assertSame(2, $mary->fresh()->visit_count, 'one visit a day');

        $this->sunday([['name' => 'Bad', 'phone' => '12']])->assertStatus(422)->assertJsonValidationErrors('rows.0.phone');
        $this->sunday([['name' => 'Tomorrow']], ['on' => now('Africa/Nairobi')->addDay()->toDateString()])->assertStatus(422);

        // The board, its filters and the visitor page.
        $items = $this->getJson('/api/visitors')->assertOk()->json('data.items');
        $this->assertEqualsCanonicalizing(['Mary Wanjiru Mutua', 'John', 'Demo Visitor'], array_column($items, 'name'));
        $this->assertSame(['John'], array_column($this->getJson('/api/visitors?q=mbuvo')->json('data.items'), 'name'), 'search finds the area');
        $this->assertSame(['Mary Wanjiru Mutua'], array_column($this->getJson('/api/visitors?area=Kasikeu')->json('data.items'), 'name'));
        $this->assertSame(['Mary Wanjiru Mutua'], array_column($this->getJson('/api/visitors?stage[]=returning')->json('data.items'), 'name'));
        $page = $this->getJson("/api/visitors/{$mary->id}")->assertOk()->json('data');
        $this->assertCount(2, $page['visit_list']);
        $this->assertArrayNotHasKey('prayer_request', collect($page['visit_list'])->last());
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

    public function test_an_sms_is_refused_to_anyone_who_asked_not_to_be_texted_and_is_logged(): void
    {
        Sanctum::actingAs($this->senior);
        $this->sunday([['name' => 'Yes Please', 'phone' => '0712000444'], ['name' => 'No Thanks', 'phone' => '0712000555'], ['name' => 'Demo One', 'phone' => '0700000002']])->assertCreated();
        $yes = Person::where('first_name', 'Yes')->first();
        $no = Person::where('first_name', 'No')->first();
        $demo = Person::where('first_name', 'Demo')->first();
        $this->putJson("/api/visitors/{$no->id}", ['consent_contact' => false])->assertOk()->assertJsonPath('data.consent', false);
        $this->postJson("/api/visitors/{$demo->id}/sms", ['text' => 'Hello'])->assertStatus(422);

        $this->postJson("/api/visitors/{$no->id}/sms", ['text' => 'Hello'])->assertStatus(422);
        $this->assertFalse($this->getJson("/api/visitors/{$no->id}")->json('data.can.sms'));
        $this->postJson("/api/visitors/{$yes->id}/sms", ['text' => 'We missed you on Sunday'])->assertOk();
        $log = MessageLog::where('kind', 'visitor')->latest('id')->first();
        $this->assertSame('+254712000444', $log->to);
        $this->assertSame('sms', $this->getJson("/api/visitors/{$yes->id}")->json('data.last_followup.type'), 'the SMS is on their follow-up');

        // Messages: our register as an audience - members, and visitors who didn't say no (demo numbers never).
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

        $i = $this->getJson('/api/visitors/insights')->assertOk()->json('data');
        $this->assertSame(1, $i['first_timers']);
        $this->assertSame([1, 1], [collect($i['funnel'])->firstWhere('key', 'member')['count'], array_sum($i['joined_series'])]);
        $this->assertSame(1, array_sum($i['first_series']));
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
        foreach (['Mary', 'Mutua', '712345678', '"phone"', '"area"', '"note"', '"name":"Mary'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        Sanctum::actingAs($this->senior);
        $this->getJson('/api/visitors/totals')->assertForbidden();
    }

    public function test_retention_removes_old_visitors_details_and_leaves_members_alone(): void
    {
        $old = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Old', 'last_name' => 'Visitor', 'phone' => '+254712000777', 'area' => 'Kasikeu', 'status' => 'visitor', 'stage' => 'new', 'first_visit_on' => now()->subMonths(14), 'last_visit_on' => now()->subMonths(13), 'visit_count' => 1]);
        VisitorVisit::create(['person_id' => $old->id, 'territory_id' => $this->myChurch->id, 'on' => now()->subMonths(13), 'first_time' => true]);
        $recent = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Recent', 'last_name' => 'Visitor', 'status' => 'visitor', 'stage' => 'new', 'last_visit_on' => now()->subMonth(), 'visit_count' => 1]);
        $member = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Long', 'last_name' => 'Member', 'status' => 'member', 'stage' => 'member', 'last_visit_on' => now()->subYears(3)]);
        $elsewhere = Person::create(['territory_id' => $this->otherChurch->id, 'first_name' => 'Other', 'last_name' => 'Church', 'status' => 'visitor', 'stage' => 'new', 'last_visit_on' => now()->subYears(2)]);

        $this->artisan('people:retention')->assertSuccessful();
        $this->assertNull($old->fresh()->anonymised_at, 'never, by default');

        app(Settings::class)->setMany($this->myChurch, 'church', 'visitors', ['people.retention_visitor_months' => '12'], [], [], $this->senior);
        $this->artisan('people:retention')->assertSuccessful();
        $old = $old->fresh();
        $this->assertSame(['Removed person', null, null], [$old->name, $old->phone, $old->area]);
        $this->assertSame(1, $old->visit_count, 'the counts stay');
        $this->assertNull($recent->fresh()->anonymised_at);
        $this->assertNull($member->fresh()->anonymised_at, 'members are never touched');
        $this->assertNull($elsewhere->fresh()->anonymised_at, 'their church keeps the default');
    }

    public function test_the_demo_people_are_never_texted_and_come_away_cleanly(): void
    {
        $real = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Real', 'last_name' => 'Member', 'phone' => '+254712999888', 'status' => 'member']);
        $this->seed(\Database\Seeders\PeopleDemoSeeder::class);
        $demo = Person::where('phone', 'like', '+254700000%');
        $this->assertGreaterThan(10, $demo->count());
        $this->assertSame(0, Person::whereNull('phone')->count(), 'every demo person has a demo number');
        $this->assertTrue(\App\Models\VisitorVisit::count() > 0 && \App\Models\VisitorFollowup::count() > 0);

        Sanctum::actingAs($this->senior);
        $church = Person::where('phone', 'like', '+254700000%')->value('territory_id');
        $phones = app(\App\Services\Messages\Audience::class)->register(\App\Models\Territory::find($church), 'members')->pluck('phone')->all();
        $this->assertEmpty(array_filter($phones, fn ($p) => str_starts_with((string) $p, '+254700000')), 'Messages never reaches a demo number');

        $this->seed(\Database\Seeders\PeopleDemoRemoveSeeder::class);
        $this->assertSame(0, Person::where('phone', 'like', '+254700000%')->count());
        $this->assertNotNull($real->fresh(), 'real people stay');
    }

    public function test_many_visitors_at_once_a_sunday_service_and_picked_people_in_messages(): void
    {
        $this->myChurch->forceFill(['metadata' => ['service_times' => [['name' => 'Sunday morning', 'day' => 0, 'start' => '09:00', 'end' => null, 'gathering_type_id' => null, 'language' => null]]]])->save();
        Sanctum::actingAs($this->senior);
        $choices = $this->getJson('/api/visitors/options')->assertOk()->json('data.gathering_choices');
        $this->assertSame('service:Sunday morning', $choices[0]['key']);

        $this->sunday([['name' => 'Ann One', 'phone' => '0712000901'], ['name' => 'Bob Two', 'phone' => '0712000902'], ['name' => 'Cy Three']], ['gathering' => 'service:Sunday morning'])->assertCreated();
        $this->sunday([['name' => 'Nope']], ['gathering' => 'service:Saturday disco'])->assertStatus(422)->assertJsonValidationErrors('gathering');
        $ann = Person::where('first_name', 'Ann')->first();
        $this->assertSame('Sunday morning', $this->getJson("/api/visitors/{$ann->id}")->json('data.visit_list.0.gathering'));
        $this->assertSame('sunday_service', \App\Models\GatheringCategory::find(VisitorVisit::where('person_id', $ann->id)->value('gathering_category_id'))?->slug);

        $ids = Person::whereIn('first_name', ['Ann', 'Bob', 'Cy'])->pluck('id')->all();
        $this->postJson('/api/visitors/bulk', ['ids' => $ids, 'action' => 'assign', 'user_id' => $this->elder->id])->assertOk()->assertJsonPath('data.done', 3);
        $this->assertSame(1, $this->elder->notifications()->count(), 'told once');
        $this->postJson('/api/visitors/bulk', ['ids' => $ids, 'action' => 'stage', 'stage' => 'contacted'])->assertOk()->assertJsonPath('data.done', 3);
        $this->postJson('/api/visitors/bulk', ['ids' => $ids, 'action' => 'stage', 'stage' => 'member'])->assertStatus(422);
        $this->assertSame(['contacted'], Person::whereIn('id', $ids)->pluck('stage')->unique()->values()->all());

        // Messages: picked people, not a whole group - and only those who can be texted.
        $bob = Person::where('first_name', 'Bob')->first();
        $this->putJson("/api/visitors/{$bob->id}", ['consent_contact' => false])->assertOk();
        $r = $this->postJson('/api/messages/preview', ['audience' => ['register' => ['people_ids' => $ids]], 'channel' => 'sms', 'body' => 'Hi'])->assertOk()->json('data');
        $this->assertSame(['Ann One'], $r['names'], 'Bob said no, Cy has no phone');
        $this->assertStringContainsString('3 picked people (2 can', $r['summary']);

        Sanctum::actingAs($this->otherPastor);
        $this->postJson('/api/visitors/bulk', ['ids' => $ids, 'action' => 'archive'])->assertOk()->assertJsonPath('data.done', 0);
        Sanctum::actingAs($this->reader);
        $this->postJson('/api/visitors/bulk', ['ids' => $ids, 'action' => 'archive'])->assertForbidden();
    }
}
