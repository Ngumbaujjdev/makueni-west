<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\BudgetEntry;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\User;
use App\Reports\Activities\ActivitySummaryReport;
use App\Reports\Activities\ActivityYearReport;
use App\Reports\ReportContext;
use Database\Seeders\ActivitiesAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Events (docs/specs/events-initiatives-spec.md, L1b): a place creates and
 * publishes its events; they reach everyone below, selected places, or a
 * church's own region; invited places register counts (not people); only
 * the organiser changes the event, sees who's coming and records fees.
 */
class EventsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private User $treasurer;

    private User $otherPastor;

    private User $farPastor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->grant($this->pastor, 'church', ['read', 'manage', 'register']);
        $this->grant($this->overseer, 'region', ['read', 'manage', 'register', 'below']);
        $this->grant($this->bishop, 'diocese', ['read', 'manage', 'register', 'below']);
        $this->treasurer = $this->userWithRole('treasurer', 'Test Treasurer', 'church', $this->myChurch->id, ['church.events.events.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Test Other Pastor', 'church', $this->otherChurch->id, ['church.events.events.read', 'church.events.events.register']);
        $this->farPastor = $this->userWithRole('farpastor', 'Test Far Pastor', 'church', $this->farChurch->id, ['church.events.events.read', 'church.events.events.register']);
    }

    private function grant(User $user, string $level, array $abilities): void
    {
        $map = ['read' => 'events.events.read', 'manage' => 'events.events.manage', 'register' => 'events.events.register', 'below' => 'events.below.read'];
        foreach ($abilities as $a) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => "{$level}.{$map[$a]}", 'guard_name' => 'web'], ['action' => $a, 'territory_scope' => $level]));
        }
    }

    private function event(array $over = []): array
    {
        return $over + [
            'title' => 'Youth Convention', 'type' => 'youth_convention', 'audience' => 'youth',
            'starts_at' => now()->addDays(30)->setTime(9, 0)->toDateTimeString(), 'ends_at' => now()->addDays(32)->setTime(16, 0)->toDateTimeString(),
            'venue' => 'Wote', 'open_to' => 'below', 'registration' => true, 'register_by' => now()->addDays(20)->toDateString(), 'fee_per_person' => 500,
        ];
    }

    private function published(User $as, array $over = []): int
    {
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/activities', $this->event($over))->assertCreated()->json('data.id');
        $this->postJson("/api/activities/{$id}/publish")->assertOk();

        return $id;
    }

    private function invitedTitles(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/activities?scope=invited&year='.now()->addDays(30)->year)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_a_place_creates_and_publishes_its_events_and_a_reader_cannot(): void
    {
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/activities', $this->event(['type' => 'revival', 'open_to' => 'own']))->assertForbidden();

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/activities', $this->event(['type' => 'youth_convention', 'open_to' => 'own']))->assertStatus(422)->assertJsonValidationErrors('type');
        $id = $this->postJson('/api/activities', $this->event(['title' => 'Harvest', 'type' => 'fundraising', 'open_to' => 'own', 'registration' => true]))->assertCreated()->json('data.id');
        $this->assertFalse(Activity::find($id)->registration, 'an event for our church only takes no registrations');
        $this->postJson("/api/activities/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson("/api/activities/{$id}/publish")->assertStatus(422);
        $this->assertSame(['Harvest'], collect($this->getJson('/api/activities?year='.now()->addDays(30)->year)->json('data'))->pluck('title')->all());
    }

    public function test_an_event_reaches_everyone_below_selected_places_or_a_churchs_own_region(): void
    {
        $this->published($this->bishop, ['title' => 'Diocese Convention']);
        $this->published($this->bishop, ['title' => 'Region A Retreat', 'open_to' => 'selected', 'invitees' => [$this->region->id]]);
        $this->published($this->pastor, ['title' => 'Our Revival', 'type' => 'revival', 'open_to' => 'region']);
        Sanctum::actingAs($this->overseer);
        $this->postJson('/api/activities', $this->event(['title' => 'Region Draft']))->assertCreated();

        $this->assertSame(['Diocese Convention', 'Region A Retreat'], $this->invitedTitles($this->pastor));
        $this->assertSame(['Diocese Convention', 'Our Revival', 'Region A Retreat'], $this->invitedTitles($this->otherPastor));
        $this->assertSame(['Diocese Convention'], $this->invitedTitles($this->farPastor), 'not the other region, not a draft, not another region\'s church event');

        Sanctum::actingAs($this->bishop);
        $this->assertSame(['Our Revival'], collect($this->getJson('/api/activities?scope=below&year='.now()->addDays(30)->year)->json('data'))->pluck('title')->all(), 'published events below - not the region\'s draft');
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/activities?scope=below')->assertForbidden();
        $this->postJson('/api/activities', $this->event(['open_to' => 'selected', 'invitees' => [$this->farChurch->id]]))->assertStatus(422);
    }

    public function test_publishing_tells_the_leaders_who_can_register(): void
    {
        $this->published($this->overseer, ['title' => 'Region Rally']);

        $this->assertSame(1, $this->otherPastor->notifications()->count());
        $this->assertSame(1, $this->pastor->notifications()->count());
        $this->assertSame(0, $this->farPastor->notifications()->count(), 'another region');
        $this->assertSame(0, $this->treasurer->notifications()->count(), 'read only - not a registering leader');
        $n = $this->otherPastor->notifications()->first()->data;
        $this->assertSame(['invitation', 'Region Rally'], [$n['kind'], $n['title']]);
        $this->assertStringContainsString('/church/events/event?id=', $n['url']);
    }

    public function test_an_invited_church_registers_counts_and_the_organiser_sees_them(): void
    {
        $id = $this->published($this->bishop);

        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 0, 'adults' => 0])->assertStatus(422);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 20, 'adults' => 3, 'leaders' => 2, 'names' => 'Choir'])->assertCreated();
        $reg = ActivityRegistration::where('activity_id', $id)->firstOrFail();
        $this->assertSame([25, '12500.00'], [$reg->expected(), (string) $reg->fee_due]);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 30])->assertOk();
        $this->assertSame(1, ActivityRegistration::count(), 'a second registration updates the first');
        $this->assertSame(1, $this->bishop->notifications()->count() >= 1 ? 1 : 0, 'the organiser is told');
        $this->putJson("/api/registrations/{$reg->id}", ['fee_paid' => 1000])->assertForbidden();

        Sanctum::actingAs($this->farPastor);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 5])->assertCreated();
        Sanctum::actingAs($this->pastor);
        $this->getJson("/api/activities/{$id}/registrations")->assertForbidden();

        Sanctum::actingAs($this->bishop);
        $d = $this->getJson("/api/activities/{$id}/registrations")->assertOk()->json('data');
        $this->assertSame([2, 35], [$d['totals']['places'], $d['totals']['expected']]);
        $this->assertEqualsCanonicalizing(['Region A', 'Region B'], array_column($d['items'], 'region'));
        $this->putJson("/api/registrations/{$reg->id}", ['fee_paid' => 15000])->assertOk()->assertJsonPath('data.fee_paid', 15000);

        Activity::whereKey($id)->update(['register_by' => now()->subDay()->toDateString()]);
        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 40])->assertStatus(422);
    }

    public function test_only_the_organiser_changes_an_event(): void
    {
        $id = $this->published($this->overseer);

        foreach ([$this->pastor, $this->bishop] as $user) {
            Sanctum::actingAs($user);
            $this->putJson("/api/activities/{$id}", $this->event(['title' => 'Hijacked']))->assertForbidden();
            $this->postJson("/api/activities/{$id}/cancel")->assertForbidden();
        }
        Sanctum::actingAs($this->farPastor);
        $this->getJson("/api/activities/{$id}")->assertNotFound();

        Sanctum::actingAs($this->overseer);
        $this->postJson("/api/activities/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->putJson("/api/activities/{$id}", $this->event())->assertStatus(422);
    }

    public function test_money_comes_from_the_organisers_budget_entries_tagged_with_the_event(): void
    {
        $id = $this->published($this->pastor, ['type' => 'fundraising', 'open_to' => 'own', 'planned_income' => 50000]);
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $this->churchLine], (int) now()->year, (int) now()->month);
        $book = app(\App\Services\Budgets\BudgetBook::class);
        foreach ([[$this->incomeLine, 12000], [$this->churchLine, 3000]] as [$line, $amount]) {
            $e = $book->record($this->pastor, $budget, ['budget_line_id' => $line->id, 'amount' => $amount, 'entry_date' => now()->toDateString(), 'description' => 'Harvest']);
            $e->forceFill(['activity_id' => $id])->save();
        }
        $book->record($this->pastor, $budget, ['budget_line_id' => $this->incomeLine->id, 'amount' => 999, 'entry_date' => now()->toDateString(), 'description' => 'Not the event']);

        Sanctum::actingAs($this->pastor);
        $m = $this->getJson("/api/activities/{$id}/money")->assertOk()->json('data');
        $this->assertEquals([12000, 3000, 9000, 50000], [$m['in'], $m['out'], $m['net'], $m['planned_income']]);
        $this->assertCount(2, $m['entries']);
        $this->assertSame(2, BudgetEntry::where('activity_id', $id)->count());

        // "Record money" goes to the budget in use on the event's day - else today's.
        $this->assertSame($budget->id, $m['budget_in_use']['id']);
        $day = now()->addDays(30);
        $thatMonth = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine], (int) $day->year, (int) $day->month);
        $this->assertSame($thatMonth->id, $this->getJson("/api/activities/{$id}/money")->json('data.budget_in_use.id'));

        $other = $this->published($this->overseer, ['title' => 'Not ours']);
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/budget-entries', ['budget_id' => $budget->id, 'budget_line_id' => $this->incomeLine->id, 'amount' => 10, 'entry_date' => now()->toDateString(), 'description' => 'x', 'activity_id' => $other])
            ->assertStatus(422)->assertJsonValidationErrors('activity_id');
    }

    public function test_the_reports_build(): void
    {
        $id = $this->published($this->bishop);
        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/activities/{$id}/register", ['youth' => 20])->assertCreated();

        $summary = (new ActivitySummaryReport)->build(new ReportContext($this->diocese, $this->bishop, ['activity_id' => $id]));
        $this->assertSame('Youth Convention', $summary->title);
        $this->assertSame('20', $summary->tiles[1]['value']);
        $year = (new ActivityYearReport)->build(new ReportContext($this->diocese, $this->bishop, []));
        $this->assertCount(1, $year->sections[0]->rows);
        $this->assertNull((new ActivitySummaryReport)->authorize($this->bishop, $this->diocese));
        $this->assertNotNull((new ActivitySummaryReport)->authorize($this->farPastor, $this->diocese));
    }

    public function test_the_seeder_reuses_the_placeholder_modules_and_grants_by_role(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-programs"], ['name' => 'Programs', 'territory_scope' => $level, 'is_active' => true]);
        }
        $old = Module::create(['module_group_id' => ModuleGroup::where('slug', 'diocese-programs')->value('id'), 'name' => 'Diocese Events Management', 'icon' => 'x', 'number' => 14, 'is_active' => true]);
        $oldPage = Submodule::create(['module_id' => $old->id, 'title' => 'Event Planning', 'path' => '/diocese/events/planning', 'is_active' => true]);
        $senior = Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $treasurer = Role::firstOrCreate(['name' => 'Church Treasurer', 'guard_name' => 'web'], ['territory_level' => 'church']);

        $this->seed(ActivitiesAccessSeeder::class);
        $this->seed(ActivitiesAccessSeeder::class);

        $this->assertSame('Events', $old->fresh()->name);
        $this->assertFalse((bool) $oldPage->fresh()->is_active);
        $this->assertSame(1, Submodule::where('path', '/diocese/events/')->count());
        $this->assertSame(1, Submodule::where('path', '/church/events/')->count());
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.events.events.manage'));
        $this->assertTrue($treasurer->fresh()->hasPermissionTo('church.events.events.read'));
        $this->assertFalse($treasurer->fresh()->hasPermissionTo('church.events.events.register'));
    }

    public function test_attendance_can_only_be_tagged_with_the_churchs_own_event(): void
    {
        $theirs = $this->published($this->overseer, ['title' => 'Region Rally']);
        $this->pastor->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => 'attendancemanagement.specialeventsattendance.create', 'guard_name' => 'web']));
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/attendance', [
            'territory_id' => $this->myChurch->id, 'service_date' => now()->subDay()->toDateString(),
            'gathering_category_id' => \App\Models\GatheringCategory::where('slug', 'special_event')->value('id'),
            'event_name' => 'Region Rally', 'adults_count' => 10, 'activity_id' => $theirs,
        ])->assertStatus(422)->assertJsonPath('errors.activity_id.0', "That event isn't one of this church's.");
    }
}
