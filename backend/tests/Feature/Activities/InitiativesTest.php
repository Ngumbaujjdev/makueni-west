<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivitySession;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\User;
use App\Reports\Activities\ActivitySummaryReport;
use App\Reports\Activities\InitiativeYearReport;
use App\Reports\ReportContext;
use App\Services\Activities\Sessions;
use Database\Seeders\ActivitiesAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Initiatives (docs/specs/events-initiatives-spec.md, L2): programmes that
 * meet over time. Sessions come from how often they meet and keep what was
 * recorded when the schedule changes; places below join with counts; the
 * organiser records attendance per session and how many finished.
 */
class InitiativesTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private User $otherPastor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->grant($this->pastor, 'church', ['read', 'manage', 'register']);
        $this->grant($this->overseer, 'region', ['read', 'manage', 'register', 'below']);
        $this->grant($this->bishop, 'diocese', ['read', 'manage', 'register', 'below']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Test Other Pastor', 'church', $this->otherChurch->id, ['church.initiatives.initiatives.read', 'church.initiatives.initiatives.register']);
    }

    private function grant(User $user, string $level, array $abilities, string $module = 'initiatives'): void
    {
        foreach ($abilities as $a) {
            $name = $a === 'below' ? "{$level}.{$module}.below.read" : "{$level}.{$module}.{$module}.{$a}";
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => $a, 'territory_scope' => $level]));
        }
    }

    private function initiative(array $over = []): array
    {
        return $over + [
            'kind' => 'initiative', 'title' => 'Discipleship Class', 'type' => 'discipleship', 'audience' => 'everyone',
            'starts_at' => '2027-01-04 15:00:00', 'ends_at' => '2027-01-31 17:00:00',
            'frequency' => 'weekly', 'meeting_day' => 3, 'meeting_time' => '18:00', 'open_to' => 'own',
        ];
    }

    private function create(User $as, array $over = []): Activity
    {
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/activities', $this->initiative($over))->assertCreated()->json('data.id');

        return Activity::findOrFail($id);
    }

    private function dates(Activity $a): array
    {
        return $a->sessions()->get()->map(fn ($s) => $s->held_on->toDateString())->all();
    }

    public function test_sessions_come_from_how_often_it_meets(): void
    {
        // Every Wednesday from Monday 4 Jan to 31 Jan.
        $weekly = $this->create($this->pastor);
        $this->assertSame(['2027-01-06', '2027-01-13', '2027-01-20', '2027-01-27'], $this->dates($weekly));
        $this->assertSame([1, 2, 3, 4], $weekly->sessions()->pluck('number')->all());

        $fortnightly = $this->create($this->pastor, ['frequency' => 'fortnightly']);
        $this->assertSame(['2027-01-06', '2027-01-20'], $this->dates($fortnightly));

        // The 31st, or the month's last day when it is shorter.
        $monthly = $this->create($this->pastor, ['frequency' => 'monthly', 'starts_at' => '2027-01-31 09:00:00', 'ends_at' => '2027-04-30 12:00:00']);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $this->dates($monthly));

        $quarterly = $this->create($this->pastor, ['frequency' => 'quarterly', 'starts_at' => '2027-01-10 09:00:00', 'ends_at' => '2027-12-31 12:00:00']);
        $this->assertSame(['2027-01-10', '2027-04-10', '2027-07-10', '2027-10-10'], $this->dates($quarterly));

        $once = $this->create($this->pastor, ['frequency' => 'once', 'starts_at' => '2027-02-02 09:00:00', 'ends_at' => '2027-02-02 12:00:00']);
        $this->assertSame(['2027-02-02'], $this->dates($once));

        $long = $this->create($this->pastor, ['starts_at' => '2027-01-01 09:00:00', 'ends_at' => '2030-12-31 12:00:00']);
        $this->assertSame(Sessions::MAX, $long->sessions()->count());

        // How often it meets is required for an initiative, and never stored on an event.
        $this->postJson('/api/activities', $this->initiative(['frequency' => null]))->assertStatus(422)->assertJsonValidationErrors('frequency');
    }

    public function test_changing_the_schedule_keeps_what_was_recorded(): void
    {
        $a = $this->create($this->pastor, ['starts_at' => '2026-01-05 09:00:00', 'ends_at' => '2026-02-01 12:00:00', 'meeting_day' => 1]);
        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'], $this->dates($a));
        $second = $a->sessions()->get()[1];
        $this->putJson("/api/sessions/{$second->id}", ['adults' => 14, 'youth' => 6, 'topic' => 'Prayer'])->assertOk()
            ->assertJsonPath('data.status', 'held')->assertJsonPath('data.attendance', 20);

        $this->putJson("/api/activities/{$a->id}", $this->initiative(['starts_at' => '2026-01-05 09:00:00', 'ends_at' => '2026-02-01 12:00:00', 'meeting_day' => 1, 'frequency' => 'fortnightly']))->assertOk();

        // 5 and 19 Jan from the new schedule, plus the held 12 Jan session.
        $this->assertSame(['2026-01-05', '2026-01-12', '2026-01-19'], $this->dates($a));
        $this->assertSame('held', ActivitySession::find($second->id)->status);
        $this->assertSame([1, 2, 3], $a->sessions()->pluck('number')->all());
    }

    public function test_only_the_organiser_manages_sessions(): void
    {
        $a = $this->create($this->pastor, ['starts_at' => '2026-01-05 09:00:00', 'ends_at' => '2026-02-01 12:00:00', 'meeting_day' => 1]);
        [$first, $second] = $a->sessions()->get()->all();

        // Add one, remove an untouched one; a held one stays.
        $added = $this->postJson("/api/activities/{$a->id}/sessions", ['held_on' => '2026-01-30', 'topic' => 'Extra'])->assertCreated()->json('data.id');
        $this->assertSame(5, $a->sessions()->count());
        $this->deleteJson("/api/sessions/{$added}")->assertOk();
        $this->putJson("/api/sessions/{$first->id}", ['adults' => 9])->assertOk();
        $this->deleteJson("/api/sessions/{$first->id}")->assertStatus(422);

        // A future session can't be held yet.
        $future = $this->create($this->pastor)->sessions()->first();
        $this->putJson("/api/sessions/{$future->id}", ['adults' => 3])->assertStatus(422)->assertJsonValidationErrors('held_on');

        Sanctum::actingAs($this->otherPastor);
        $this->putJson("/api/sessions/{$second->id}", ['adults' => 5])->assertStatus(403);
        $this->deleteJson("/api/sessions/{$second->id}")->assertStatus(403);
        $this->postJson("/api/activities/{$a->id}/sessions", ['held_on' => '2026-01-30'])->assertStatus(403);

        Sanctum::actingAs($this->pastor);
        $this->getJson("/api/activities/{$a->id}/sessions")->assertOk()
            ->assertJsonPath('data.summary.held', 1)->assertJsonPath('data.summary.total', 4)->assertJsonPath('data.can_manage', true);
    }

    public function test_initiative_and_event_permissions_are_separate(): void
    {
        $eventsOnly = $this->userWithRole('eventsonly', 'Test Events Only', 'church', $this->myChurch->id, ['church.events.events.read', 'church.events.events.manage']);
        Sanctum::actingAs($eventsOnly);
        $this->postJson('/api/activities', $this->initiative())->assertStatus(403);
        $this->getJson('/api/activities?kind=initiative')->assertStatus(403);

        // The pastor here has initiatives only.
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/activities', ['title' => 'Revival', 'type' => 'revival', 'starts_at' => '2027-03-01 09:00:00', 'ends_at' => '2027-03-01 12:00:00', 'open_to' => 'own'])->assertStatus(403);
        $a = $this->create($this->pastor);
        Sanctum::actingAs($eventsOnly);
        $this->getJson("/api/activities/{$a->id}")->assertStatus(403);
    }

    public function test_places_below_join_until_the_last_day_and_the_organiser_records_who_finished(): void
    {
        $a = $this->create($this->overseer, [
            'title' => 'Leaders Training', 'type' => 'leadership_training', 'open_to' => 'below', 'registration' => true,
            'starts_at' => now()->subDays(3)->toDateTimeString(), 'ends_at' => now()->addDays(30)->toDateTimeString(),
        ]);
        $this->postJson("/api/activities/{$a->id}/publish")->assertOk();

        // It has started, but an initiative can still be joined.
        Sanctum::actingAs($this->otherPastor);
        $this->getJson('/api/activities?kind=initiative&scope=invited')->assertOk()->assertJsonCount(1, 'data');
        $reg = $this->postJson("/api/activities/{$a->id}/register", ['leaders' => 4, 'adults' => 2])->assertCreated()->json('data.mine.id');
        $this->putJson("/api/registrations/{$reg}", ['completed' => 3])->assertStatus(403);

        Sanctum::actingAs($this->overseer);
        $this->putJson("/api/registrations/{$reg}", ['completed' => 5])->assertOk()->assertJsonPath('data.completed', 5);
        $this->getJson("/api/activities/{$a->id}/registrations")->assertOk()->assertJsonPath('data.items.0.completed', 5);

        // Once it has ended, joining is closed.
        $a->forceFill(['ends_at' => now()->subDay()])->save();
        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/activities/{$a->id}/register", ['adults' => 1])->assertStatus(422)->assertJsonPath('message', 'Registration for this initiative is closed.');
    }

    public function test_the_overview_and_reports(): void
    {
        config(['audit.console' => true]);
        $a = $this->create($this->overseer, ['type' => 'leadership_training', 'open_to' => 'below', 'registration' => true, 'capacity' => 40,
            'starts_at' => '2026-01-05 09:00:00', 'ends_at' => now()->addDays(30)->toDateTimeString(), 'meeting_day' => 1]);
        $this->postJson("/api/activities/{$a->id}/publish")->assertOk();
        [$first, $second] = $a->sessions()->get()->all();
        $this->putJson("/api/sessions/{$first->id}", ['adults' => 18])->assertOk();
        $this->putJson("/api/sessions/{$second->id}", ['adults' => 22])->assertOk();
        // A church joining with 5 doesn't change who the sessions are for (40).
        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/activities/{$a->id}/register", ['adults' => 5])->assertCreated();
        Sanctum::actingAs($this->overseer);

        $o = $this->getJson('/api/activities/overview?kind=initiative&year=2026')->assertOk()->json('data');
        $this->assertSame(1, $o['active']);
        $this->assertSame(2, $o['sessions_held']);
        // 40 attended of 2 sessions x 40 places.
        $this->assertSame(50, $o['attendance_rate']);
        $this->assertSame(20, $o['average_attendance']);
        $this->assertSame(2, $o['by_month'][0]);
        $this->assertArrayHasKey('weekly', $o['frequencies']);
        $this->assertArrayHasKey('pastors_training', $o['types']);

        $summary = (new ActivitySummaryReport)->build(new ReportContext($this->region, $this->overseer, ['activity_id' => $a->id]));
        $this->assertSame('Sessions', $summary->sections[0]->heading);
        $year = (new InitiativeYearReport)->build(new ReportContext($this->region, $this->overseer, []));
        $this->assertCount(1, $year->sections[0]->rows);
        $this->assertNull((new InitiativeYearReport)->authorize($this->overseer, $this->region));
        $this->assertNotNull((new InitiativeYearReport)->authorize($this->farChurchReader(), $this->region));

        // The history says what people did, not every generated session.
        $sentences = collect($this->getJson("/api/activities/{$a->id}/history")->json('data'))->pluck('sentence');
        $this->assertTrue($sentences->contains('Test Overseer recorded attendance for session 1'));
        $this->assertFalse($sentences->contains(fn ($s) => str_contains($s, 'added session')));
    }

    private function farChurchReader(): User
    {
        return $this->userWithRole('farreader', 'Test Far Reader', 'church', $this->farChurch->id, ['church.events.events.read']);
    }

    public function test_the_seeder_adds_initiatives_and_reuses_the_placeholder(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-programs"], ['name' => 'Programs', 'territory_scope' => $level, 'is_active' => true]);
        }
        $old = Module::create(['module_group_id' => ModuleGroup::where('slug', 'diocese-programs')->value('id'), 'name' => 'Diocese Initiatives Management', 'icon' => 'x', 'number' => 13, 'is_active' => true]);
        $oldPage = Submodule::create(['module_id' => $old->id, 'title' => 'Planning', 'path' => '/diocese/initiatives/planning', 'is_active' => true]);
        $senior = Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);

        $this->seed(ActivitiesAccessSeeder::class);
        $this->seed(ActivitiesAccessSeeder::class);

        $this->assertSame('Initiatives', $old->fresh()->name);
        $this->assertFalse((bool) $oldPage->fresh()->is_active);
        foreach (['church', 'region', 'diocese'] as $level) {
            $this->assertSame(1, Submodule::where('path', "/{$level}/initiatives/")->count());
            $this->assertSame(1, Submodule::where('path', "/{$level}/events/")->count());
        }
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.initiatives.initiatives.manage'));
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.events.events.manage'));
    }
}
