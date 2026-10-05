<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\Church;
use App\Models\Diocese;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\SuperAdminConfig;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\CalendarAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The calendar (docs/specs/calendar-spec.md, C1): each place sees its own
 * events and the shared events above it, the CCI calendar on top; only the
 * owner edits; only global admins touch the CCI calendar, and import it.
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private Territory $national;

    private Diocese $diocese;

    private Region $region;

    private Region $otherRegion;

    private Church $church;

    private Church $otherChurch;

    private User $pastor;

    private User $treasurer;

    private User $otherPastor;

    private User $overseer;

    private User $bishop;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->national = Territory::create(['name' => 'Christian Church International Kenya', 'code' => 'T-CCI', 'territory_type' => 'global', 'level' => 0]);
        $this->diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1, 'parent_territory_id' => $this->national->id]);
        $this->region = Region::create(['name' => 'Region A', 'code' => 'T-RA', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->otherRegion = Region::create(['name' => 'Region B', 'code' => 'T-RB', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $this->diocese->id]);
        $this->church = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OT-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->region->id]);

        $this->pastor = $this->user('pastor', 'Test Senior Pastor', 'church', $this->church, ['read', 'manage']);
        $this->treasurer = $this->user('treasurer', 'Test Church Treasurer', 'church', $this->church, ['read']);
        $this->otherPastor = $this->user('otherpastor', 'Test Senior Pastor', 'church', $this->otherChurch, ['read', 'manage']);
        $this->overseer = $this->user('overseer', 'Test Regional Overseer', 'region', $this->region, ['read', 'manage']);
        $this->bishop = $this->user('bishop', 'Test Bishop', 'diocese', $this->diocese, ['read', 'manage']);
        $this->admin = $this->user('admin', 'Test Global Administrator', 'diocese', $this->diocese, []);
        SuperAdminConfig::create(['user_id' => $this->admin->id, 'primary_territory_id' => $this->diocese->id, 'global_access' => true]);
    }

    private function user(string $name, string $roleName, string $level, Territory $at, array $actions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['territory_level' => $level]);
        foreach ($actions as $action) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => "{$level}.calendar.events.{$action}", 'guard_name' => 'web'], ['action' => $action, 'territory_scope' => $level]));
        }
        $user = User::create(['firstname' => 'Test', 'lastname' => ucfirst($name), 'username' => "cal.{$name}", 'email' => "cal.{$name}@example.test", 'password' => bcrypt('x')]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $at->id, 'role_id' => $role->id, 'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    private function event(Territory $owner, string $title, string $on, array $extra = []): CalendarEvent
    {
        return CalendarEvent::create($extra + ['territory_id' => $owner->id, 'title' => $title, 'kind' => 'other', 'starts_on' => $on, 'ends_on' => $extra['ends_on'] ?? $on, 'shared_below' => true]);
    }

    private function titles(User $as, string $query = ''): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson("/api/calendar/events?from=2026-01-01&to=2026-12-31{$query}")->assertOk()->json('data'))->pluck('title')->unique()->sort()->values()->all();
    }

    public function test_each_place_sees_its_own_events_and_the_shared_ones_above_with_the_cci_calendar_on_top(): void
    {
        $this->event($this->national, 'CCI Conference', '2026-08-14');
        $this->event($this->diocese, 'Diocese Synod', '2026-05-02');
        $this->event($this->region, 'Region Rally', '2026-06-06');
        $this->event($this->region, 'Region Committee', '2026-06-07', ['shared_below' => false]);
        $this->event($this->otherRegion, 'Other Region Day', '2026-06-08');
        $this->event($this->church, 'Our Harvest', '2026-09-20', ['shared_below' => false]);
        $this->event($this->otherChurch, 'Their Harvest', '2026-09-21', ['shared_below' => true]);

        $this->assertSame(['CCI Conference', 'Diocese Synod', 'Our Harvest', 'Region Rally'], $this->titles($this->pastor));
        $this->assertSame(['CCI Conference', 'Diocese Synod', 'Region Committee', 'Region Rally'], $this->titles($this->overseer));
        $this->assertSame(['CCI Conference', 'Diocese Synod', 'Region Committee', 'Region Rally', 'Their Harvest'], $this->titles($this->overseer, '&layers[]=cci&layers[]=diocese&layers[]=ours&layers[]=below'));
        $this->assertSame(['CCI Conference'], $this->titles($this->pastor, '&layers[]=cci'));

        Sanctum::actingAs($this->pastor);
        $cci = collect($this->getJson('/api/calendar/events?from=2026-08-01&to=2026-08-31')->json('data'))->firstWhere('title', 'CCI Conference');
        $this->assertSame(['cci', 'Christian Church International Kenya', false], [$cci['layer'], $cci['owner']['name'], $cci['can_edit']]);
        $this->getJson('/api/calendar/events?from=2026-01-01&to=2027-06-01')->assertStatus(422);
    }

    public function test_repeating_events_are_expanded_into_the_range(): void
    {
        $this->event($this->church, 'Youth night', '2026-03-04', ['repeats' => 'weekly', 'repeat_until' => '2026-03-25']);
        $this->event($this->church, 'Retreat', '2025-04-10', ['ends_on' => '2025-04-12', 'repeats' => 'yearly']);
        $this->event($this->church, 'Month end', '2026-01-31', ['repeats' => 'monthly', 'repeat_until' => '2026-04-30']);
        Sanctum::actingAs($this->pastor);
        $all = collect($this->getJson('/api/calendar/events?from=2026-01-01&to=2026-12-31')->json('data'));

        $this->assertSame(['2026-03-04', '2026-03-11', '2026-03-18', '2026-03-25'], $all->where('title', 'Youth night')->pluck('start')->values()->all());
        $retreat = $all->firstWhere('title', 'Retreat');
        $this->assertSame(['2026-04-10', '2026-04-13'], [$retreat['start'], $retreat['end']], 'three days, every year (end is exclusive)');
        $this->assertSame(['starts_on' => '2025-04-10', 'ends_on' => '2025-04-12', 'start_time' => null, 'end_time' => null, 'repeat_until' => null], $retreat['base'], 'the edit form starts from the event, not the occurrence');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $all->where('title', 'Month end')->pluck('start')->values()->all());
    }

    public function test_a_place_manages_only_its_own_events(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/calendar/events', ['title' => 'Harvest Sunday', 'kind' => 'celebration', 'starts_on' => '2026-09-20'])->assertCreated()->json('data.id');
        $this->assertFalse(CalendarEvent::find($id)->shared_below, 'a church event stays with the church unless shared');
        $this->putJson("/api/calendar/events/{$id}", ['title' => 'Harvest Thanksgiving', 'kind' => 'celebration', 'starts_on' => '2026-09-20', 'shared_below' => true])->assertOk();
        $this->assertSame('Harvest Thanksgiving', CalendarEvent::find($id)->title);

        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/calendar/events', ['title' => 'X', 'kind' => 'other', 'starts_on' => '2026-09-20'])->assertForbidden();
        Sanctum::actingAs($this->otherPastor);
        $this->putJson("/api/calendar/events/{$id}", ['title' => 'Hijacked', 'kind' => 'other', 'starts_on' => '2026-09-20'])->assertForbidden();
        Sanctum::actingAs($this->overseer);
        $this->deleteJson("/api/calendar/events/{$id}")->assertForbidden();

        Sanctum::actingAs($this->pastor);
        $this->deleteJson("/api/calendar/events/{$id}")->assertNoContent();
        $this->assertSoftDeleted('calendar_events', ['id' => $id]);
    }

    public function test_only_global_admins_touch_the_cci_calendar(): void
    {
        Sanctum::actingAs($this->bishop);
        $this->postJson('/api/calendar/events', ['title' => 'National day', 'kind' => 'holiday', 'starts_on' => '2026-10-20', 'cci' => true])->assertForbidden();
        $this->getJson('/api/calendar/cci')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/calendar/events', ['title' => 'Mashujaa Day', 'kind' => 'holiday', 'starts_on' => '2026-10-20', 'cci' => true, 'shared_below' => false])->assertCreated()->json('data.id');
        $event = CalendarEvent::find($id);
        $this->assertSame([$this->national->id, true], [(int) $event->territory_id, $event->shared_below], 'the CCI calendar is always shared');

        Sanctum::actingAs($this->bishop);
        $this->putJson("/api/calendar/events/{$id}", ['title' => 'X', 'kind' => 'holiday', 'starts_on' => '2026-10-20'])->assertForbidden();
        $this->assertContains('Mashujaa Day', $this->titles($this->pastor));
    }

    public function test_bad_dates_and_times_are_refused(): void
    {
        Sanctum::actingAs($this->pastor);
        $post = fn (array $body) => $this->postJson('/api/calendar/events', $body + ['title' => 'T', 'kind' => 'meeting', 'starts_on' => '2026-05-10']);
        $post(['ends_on' => '2026-05-09'])->assertStatus(422)->assertJsonValidationErrors('ends_on');
        $post(['all_day' => false])->assertStatus(422)->assertJsonValidationErrors('start_time');
        $post(['all_day' => false, 'start_time' => '10:00', 'end_time' => '09:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
        $post(['repeats' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors('repeat_until');
        $post(['all_day' => false, 'start_time' => '09:00', 'end_time' => '11:30'])->assertCreated();
    }

    public function test_the_cci_calendar_is_imported_from_a_file_checked_row_by_row(): void
    {
        $csv = "title,kind,starts_on,ends_on,all_day,start_time,end_time,location,description,repeats,repeat_until\n"
            ."National Prayer Week,Fasting & prayer,2026-01-05,2026-01-11,yes,,,,,none,\n"
            ."CCI Conference,conference,14/08/2026,17/08/2026,yes,,,Nairobi,,none,\n"
            ."Bishops Council,meeting,2026-03-20,,no,09:00,15:00,HQ,,none,\n"
            .",holiday,2026-12-25,,yes,,,,,none,\n"
            ."Bad dates,holiday,2026-12-25,2026-12-20,yes,,,,,none,\n"
            ."CCI Conference,conference,2026-08-14,,yes,,,,,none,\n";
        $file = fn () => UploadedFile::fake()->createWithContent('cci.csv', $csv);
        Sanctum::actingAs($this->admin);

        $check = $this->post('/api/calendar/cci/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame([3, 2, 1, 0], [$check['valid'], $check['invalid'], $check['duplicates'], $check['created']]);
        $this->assertSame(0, CalendarEvent::count(), 'checking saves nothing');
        $this->assertSame('fasting_prayer', $check['rows'][0]['values']['kind']);
        $this->assertSame('2026-08-14', $check['rows'][1]['values']['starts_on']);

        $this->post('/api/calendar/cci/import', ['file' => $file(), 'commit' => 1], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 3);
        $this->assertSame(3, CalendarEvent::where('territory_id', $this->national->id)->where('source', 'import')->count());
        $this->post('/api/calendar/cci/import', ['file' => $file(), 'commit' => 1], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 0);

        $this->assertSame(['Bishops Council', 'CCI Conference', 'National Prayer Week'], collect($this->getJson('/api/calendar/cci?year=2026')->json('data.events'))->pluck('title')->sort()->values()->all());
        $this->get('/api/calendar/cci/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_the_overview_counts_and_names_the_next_cci_event(): void
    {
        $today = CarbonImmutable::today();
        $this->event($this->national, 'CCI Conference', $today->addDays(40)->toDateString());
        $this->event($this->church, 'Our Picnic', $today->toDateString());
        $this->event($this->diocese, 'Synod', $today->subMonthNoOverflow()->toDateString());
        Sanctum::actingAs($this->pastor);
        $d = $this->getJson('/api/calendar/overview')->assertOk()->json('data');

        $this->assertSame([1, 1, 1], [$d['this_month'], $d['last_month'], $d['ours_upcoming']]);
        $this->assertSame('CCI Conference', $d['next_cci']['title']);
        $this->assertSame(['manage' => true, 'cci' => false], $d['can']);
        $this->assertCount(12, $d['by_month']);
    }

    public function test_the_seeder_builds_a_calendar_page_per_level_and_retires_the_empty_one(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-programs"], ['name' => 'Programs', 'territory_scope' => $level, 'is_active' => true]);
        }
        $old = Module::create(['module_group_id' => ModuleGroup::where('slug', 'diocese-programs')->value('id'), 'name' => 'Diocese Calendar', 'icon' => 'x', 'number' => 9, 'is_active' => true]);
        $oldPage = Submodule::create(['module_id' => $old->id, 'title' => 'Calendar Views', 'path' => '/diocese/calendar/views', 'is_active' => true]);
        $secretary = Role::create(['name' => 'Church Secretary', 'guard_name' => 'web', 'territory_level' => 'church']);
        $usher = Role::create(['name' => 'Usher Coordinator', 'guard_name' => 'web', 'territory_level' => 'church']);

        $this->seed(CalendarAccessSeeder::class);
        $this->seed(CalendarAccessSeeder::class);

        $this->assertSame(1, Submodule::where('path', '/church/calendar/')->count());
        $this->assertTrue(Submodule::where('path', '/diocese/calendar/?tab=cci')->exists());
        $this->assertTrue($secretary->fresh()->hasPermissionTo('church.calendar.events.manage'));
        $this->assertTrue($usher->fresh()->hasPermissionTo('church.calendar.events.read'));
        $this->assertFalse($usher->fresh()->hasPermissionTo('church.calendar.events.manage'));
        // The way in: "New date" (the calendar with its form open), holding manage.
        $new = Submodule::where('path', '/church/calendar/?add=1')->sole();
        $this->assertSame('New date', $new->title);
        $this->assertSame($new->id, Permission::where('name', 'church.calendar.events.manage')->value('submodule_id'));
        $this->assertSame(Submodule::where('path', '/church/calendar/')->value('id'), Permission::where('name', 'church.calendar.events.read')->value('submodule_id'));
        $this->assertFalse((bool) $old->fresh()->is_active);
        $this->assertFalse((bool) $oldPage->fresh()->is_active);
    }

    public function test_events_are_audited(): void
    {
        config(['audit.console' => true]); // auditing is off for console runs - switch it on, as in a web request
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/calendar/events', ['title' => 'Audited', 'kind' => 'other', 'starts_on' => '2026-09-20'])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('audits', ['auditable_type' => 'calendar_event', 'auditable_id' => $id, 'event' => 'created']);
    }
}
