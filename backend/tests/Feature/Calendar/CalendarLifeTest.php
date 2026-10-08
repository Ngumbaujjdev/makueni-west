<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Church life on the calendar (docs/specs/calendar-spec.md, C3): events,
 * initiative sessions, our services and our due dates are read where they
 * live and merged into the calendar feed - and into the .ics download.
 */
class CalendarLifeTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->give($this->pastor, ['church.calendar.events.read', 'church.events.events.read', 'church.events.events.manage', 'church.initiatives.initiatives.read', 'church.initiatives.initiatives.manage']);
        $this->give($this->overseer, ['region.calendar.events.read', 'region.events.events.read', 'region.events.events.manage', 'region.events.below.read', 'region.initiatives.initiatives.manage']);
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            [$level] = explode('.', $name);
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => $level]));
        }
    }

    private function activity(User $as, array $fields, bool $publish = true): int
    {
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/activities', $fields + ['type' => 'other', 'open_to' => 'own'])->assertCreated()->json('data.id');
        if ($publish) {
            $this->postJson("/api/activities/{$id}/publish")->assertOk();
        }

        return $id;
    }

    private function feed(User $as, string $from, string $to, array $extra = []): array
    {
        Sanctum::actingAs($as);

        return $this->getJson("/api/calendar/events?from={$from}&to={$to}".($extra ? '&'.http_build_query($extra) : ''))->assertOk()->json('data');
    }

    public function test_the_feed_merges_events_sessions_services_and_calendar_events(): void
    {
        CalendarEvent::create(['territory_id' => $this->myChurch->id, 'title' => 'Elders meeting', 'kind' => 'meeting', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'all_day' => true, 'repeats' => 'none', 'shared_below' => false, 'source' => 'manual']);
        $this->myChurch->forceFill(['metadata' => ['service_times' => [['name' => 'Sunday service', 'day' => 0, 'start' => '09:00', 'end' => '11:00']]]])->save();
        // 06:00 UTC is 09:00 in Nairobi.
        $revival = $this->activity($this->pastor, ['title' => 'Revival', 'type' => 'revival', 'starts_at' => '2026-11-07 06:00:00', 'ends_at' => '2026-11-07 13:00:00']);
        $study = $this->activity($this->pastor, ['kind' => 'initiative', 'title' => 'Bible Study', 'type' => 'bible_study', 'frequency' => 'weekly', 'meeting_day' => 3, 'meeting_time' => '18:00',
            'starts_at' => '2026-11-02 15:00:00', 'ends_at' => '2026-11-18 17:00:00']);
        $rally = $this->activity($this->overseer, ['title' => 'Region Rally', 'type' => 'conference', 'open_to' => 'below', 'starts_at' => '2026-11-14 06:00:00', 'ends_at' => '2026-11-14 12:00:00']);

        $items = collect($this->feed($this->pastor, '2026-11-01', '2026-11-30', ['layers' => ['region', 'ours']]));
        $by = fn ($source) => $items->where('source', $source)->values();

        $this->assertSame(['Elders meeting'], $by('calendar')->pluck('title')->all());
        $this->assertSame(['2026-11-01T09:00', '2026-11-08T09:00', '2026-11-15T09:00', '2026-11-22T09:00', '2026-11-29T09:00'], $by('services')->pluck('start')->all());
        $events = $by('events')->keyBy('title');
        $this->assertSame('2026-11-07T09:00', $events['Revival']['start']);
        $this->assertSame('ours', $events['Revival']['layer']);
        $this->assertSame("/church/events/event?id={$revival}", $events['Revival']['url']);
        $this->assertSame('region', $events['Region Rally']['layer']);
        $this->assertSame("/church/events/event?id={$rally}", $events['Region Rally']['url']);
        $this->assertSame(['2026-11-04T18:00', '2026-11-11T18:00', '2026-11-18T18:00'], $by('sessions')->pluck('start')->all());
        $this->assertSame("/church/initiatives/initiative?id={$study}", $by('sessions')->first()['url']);
        $this->assertFalse($by('events')->first()['can_edit']);

        // sources[] narrows; a calendar kind means calendar events only.
        $only = collect($this->feed($this->pastor, '2026-11-01', '2026-11-30', ['sources' => ['events']]));
        $this->assertSame(['events'], $only->pluck('source')->unique()->values()->all());
        $meetings = collect($this->feed($this->pastor, '2026-11-01', '2026-11-30', ['kinds' => ['meeting']]));
        $this->assertSame(['Elders meeting'], $meetings->pluck('title')->all());
    }

    public function test_each_place_sees_only_what_it_may(): void
    {
        $this->activity($this->overseer, ['title' => 'Region Draft', 'open_to' => 'below', 'starts_at' => '2026-11-10 06:00:00', 'ends_at' => '2026-11-10 08:00:00'], false);
        $cancelled = $this->activity($this->overseer, ['title' => 'Called Off', 'open_to' => 'below', 'starts_at' => '2026-11-11 06:00:00', 'ends_at' => '2026-11-11 08:00:00']);
        $this->postJson("/api/activities/{$cancelled}/cancel")->assertOk();
        $farRegion = $this->userWithRole('faroverseer', 'Test Far Overseer', 'region', $this->otherRegion->id, ['region.events.events.read', 'region.events.events.manage']);
        $this->activity($farRegion, ['title' => 'Other Region Day', 'open_to' => 'below', 'starts_at' => '2026-11-12 06:00:00', 'ends_at' => '2026-11-12 08:00:00']);
        $this->activity($this->pastor, ['title' => 'Our Draft', 'starts_at' => '2026-11-13 06:00:00', 'ends_at' => '2026-11-13 08:00:00'], false);

        $titles = collect($this->feed($this->pastor, '2026-11-01', '2026-11-30'))->where('source', 'events')->pluck('title')->all();
        $this->assertSame(['Our Draft'], $titles);

        // The region sees its churches' events only with "below".
        $this->activity($this->pastor, ['title' => 'Church Day', 'starts_at' => '2026-11-15 06:00:00', 'ends_at' => '2026-11-15 08:00:00']);
        $this->assertNotContains('Church Day', collect($this->feed($this->overseer, '2026-11-01', '2026-11-30'))->pluck('title'));
        $below = collect($this->feed($this->overseer, '2026-11-01', '2026-11-30', ['layers' => ['ours', 'below']]))->firstWhere('title', 'Church Day');
        $this->assertSame('below', $below['layer']);

        // Without Events and Initiatives read, none of those come through.
        $reader = $this->userWithRole('calreader', 'Test Calendar Reader', 'church', $this->myChurch->id, ['church.calendar.events.read']);
        $this->assertSame([], collect($this->feed($reader, '2026-11-01', '2026-11-30'))->whereIn('source', ['events', 'sessions'])->all());
    }

    public function test_due_dates_are_the_share_still_to_send_and_next_months_budget(): void
    {
        Sanctum::actingAs($this->bishop);
        $this->postJson('/api/budget-settings/deductions', ['name' => 'Diocese share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'basis' => 'all', 'applies_to_level' => 'church', 'new_line_name' => 'Diocese share'])->assertCreated();
        Sanctum::actingAs($this->pastor);
        $january = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $this->churchLine]);
        $this->postJson('/api/budget-entries', ['budget_id' => $january->id, 'budget_line_id' => $this->incomeLine->id, 'amount' => 400, 'entry_date' => '2026-01-10', 'description' => 'Tithes'])->assertCreated();

        $due = collect($this->feed($this->pastor, '2026-01-01', '2026-01-31'))->where('source', 'due')->values();
        $this->assertCount(1, $due);
        $this->assertSame('Diocese share for January 2026', $due[0]['title']);
        $this->assertSame('2026-01-31', $due[0]['start']);
        $this->assertSame('late', $due[0]['status']);
        $this->assertSame('danger', $due[0]['tone']);
        $this->assertSame('KES 40.00 still to send to Test Diocese · late', $due[0]['description']);
        $this->assertSame('/church/budget/contributions.php', $due[0]['url']);

        // Next month's budget, on the 25th, until one covers it. The test
        // users' roles start the day before the real today; travelling back
        // to 5 October would put them before their roles began (403 once the
        // real date passed 6 October 2026), so their roles start earlier.
        \App\Models\UserTerritoryAssignment::query()->update(['effective_from' => '2026-01-01']);
        $this->travelTo(now()->setDate(2026, 10, 5));
        $prepare = collect($this->feed($this->pastor, '2026-10-01', '2026-10-31'))->where('source', 'due')->values();
        $this->assertSame(['Prepare November 2026\'s budget'], $prepare->pluck('title')->all());
        $this->assertSame('2026-10-25', $prepare[0]['start']);
        $this->budgetFor($this->myChurch, 'draft', [$this->incomeLine], 2026, 11);
        $this->assertCount(0, collect($this->feed($this->pastor, '2026-10-01', '2026-10-31'))->where('source', 'due'));

        // Only our own place: the region doesn't see the church's.
        $this->give($this->overseer, ['region.budgets.budgets.read']);
        $this->assertCount(0, collect($this->feed($this->overseer, '2026-01-01', '2026-01-31', ['layers' => ['ours', 'below']]))->where('title', 'Diocese share for January 2026'));
    }

    public function test_the_ics_download(): void
    {
        CalendarEvent::create(['territory_id' => $this->myChurch->id, 'title' => 'Elders, deacons; and all', 'kind' => 'meeting', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'all_day' => true, 'repeats' => 'none', 'shared_below' => false, 'source' => 'manual']);
        $this->activity($this->pastor, ['title' => 'Revival', 'starts_at' => '2026-11-07 06:00:00', 'ends_at' => '2026-11-07 13:00:00']);

        Sanctum::actingAs($this->pastor);
        $res = $this->get('/api/calendar/ics?from=2026-11-01&to=2026-11-30&sources[]=calendar&sources[]=events', ['Accept' => 'text/calendar'])->assertOk();
        $this->assertStringStartsWith('text/calendar', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="my-church-calendar.ics"', $res->headers->get('Content-Disposition'));
        $body = $res->getContent();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $this->assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261103\r\nDTEND;VALUE=DATE:20261104", $body);
        $this->assertStringContainsString('SUMMARY:Elders\\, deacons\\; and all', $body);
        $this->assertStringContainsString("DTSTART:20261107T090000\r\nDTEND:20261107T160000", $body);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }
}
