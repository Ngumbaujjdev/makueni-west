<?php

namespace Tests\Feature\Reporting;

use App\Models\Activity;
use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\MessageLog;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\MonthlyReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\User;
use App\Reports\Monthly\MonthlyReportExport;
use App\Reports\Monthly\MonthlyStatusReport;
use App\Reports\ReportContext;
use App\Services\Settings\Settings;
use Database\Seeders\MonthlyReportsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Monthly reports (docs/specs/monthly-reports-spec.md, L4a): figures filled
 * in from what's recorded, frozen when sent; the place above sees, marks as
 * seen and comments; reminders on the right days; the reports build.
 */
class MonthlyReportsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private User $farPastor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->give($this->pastor, 'church', ['read', 'write', 'send']);
        $this->give($this->overseer, 'region', ['read', 'write', 'send', 'below', 'review']);
        $this->give($this->bishop, 'diocese', ['read', 'below', 'review']);
        $this->farPastor = $this->userWithRole('farpastor', 'Test Far Pastor', 'church', $this->farChurch->id, ['church.reports.monthly.read', 'church.reports.monthly.write', 'church.reports.monthly.send']);
        // "Today" is 2 May 2026 - the roles have to have started by then.
        \App\Models\UserTerritoryAssignment::query()->update(['effective_from' => '2020-01-01']);
        $this->travelTo(now()->setDate(2026, 5, 2)->setTime(10, 0));
        config(['app.monthly_reports_from' => '2026-01']);
    }

    private function give(User $user, string $level, array $abilities): void
    {
        $map = ['read' => 'reports.monthly.read', 'write' => 'reports.monthly.write', 'send' => 'reports.monthly.send', 'below' => 'reports.below.read', 'review' => 'reports.below.review'];
        foreach ($abilities as $a) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => "{$level}.{$map[$a]}", 'guard_name' => 'web'], ['action' => $a, 'territory_scope' => $level]));
        }
    }

    /** April 2026 at My Church: a monthly Demographics submission, two Sundays, money, and an event. */
    private function recordApril(): void
    {
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $months = collect(range(1, 12))->mapWithKeys(fn ($n) => [$n => FiscalMonth::firstOrCreate(['number' => $n], ['name' => date('F', mktime(0, 0, 0, $n, 1)), 'short_name' => date('M', mktime(0, 0, 0, $n, 1))])]);
        $base = ['territory_type' => 'church', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $year->id, 'status' => 'approved'];
        ChurchDemographic::create($base + ['fiscal_month_id' => $months[3]->id, 'total_members' => 300]);
        ChurchDemographic::create($base + ['fiscal_month_id' => $months[4]->id, 'total_members' => 312, 'new_members_count' => 12, 'baptisms_count' => 4, 'conversions_count' => 7, 'communion_participants_count' => 180]);
        $sunday = GatheringCategory::where('slug', 'sunday_service')->value('id');
        foreach (['2026-04-05' => 200, '2026-04-12' => 240] as $date => $adults) {
            ChurchAttendanceRecord::create(['territory_type' => 'church', 'territory_id' => $this->myChurch->id, 'service_date' => $date, 'fiscal_year_id' => $year->id, 'fiscal_month_id' => $months[4]->id, 'gathering_category_id' => $sunday, 'adults_count' => $adults, 'youth_count' => 0, 'children_male_count' => 0, 'children_female_count' => 0]);
        }
        Sanctum::actingAs($this->pastor);
        $april = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine, $this->churchLine], 2026, 4);
        $this->postJson('/api/budget-entries', ['budget_id' => $april->id, 'budget_line_id' => $this->incomeLine->id, 'amount' => 5000, 'entry_date' => '2026-04-10', 'description' => 'Tithes'])->assertCreated();
        Activity::create(['kind' => 'event', 'territory_id' => $this->myChurch->id, 'title' => 'Easter Kesha', 'type' => 'youth_kesha', 'audience' => 'everyone', 'open_to' => 'own', 'status' => 'completed',
            'starts_at' => '2026-04-03 15:00:00', 'ends_at' => '2026-04-03 21:00:00', 'registration' => false]);
    }

    public function test_the_figures_are_filled_in_and_frozen_when_sent(): void
    {
        $this->recordApril();
        Sanctum::actingAs($this->pastor);
        $f = $this->getJson('/api/monthly-reports/2026/4')->assertOk()->assertJsonPath('data.status', 'not_started')->assertJsonPath('data.figures_live', true)->json('data.figures');
        $this->assertSame([312, 12, 4, 7, 180], [$f['people']['members'], $f['people']['members_change'], $f['people']['baptisms'], $f['people']['conversions'], $f['people']['communion']]);
        $this->assertSame([2, 220], [$f['attendance']['sundays'], $f['attendance']['average_sunday']]);
        $this->assertEquals(5000, $f['money']['income']);
        $this->assertSame(['Easter Kesha'], array_column($f['events']['ours'], 'title'));

        $this->putJson('/api/monthly-reports/2026/4', ['achievements' => 'A fruitful Easter.', 'pastoral_visits' => 9])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk()->assertJsonPath('message', 'Sent to Region A.')->assertJsonPath('data.figures_live', false);

        // Recorded after sending: the sent report keeps what it had.
        $aprilBudget = \App\Models\Budget::where('territory_id', $this->myChurch->id)->where('period_month', 4)->value('id');
        $this->postJson('/api/budget-entries', ['budget_id' => $aprilBudget, 'budget_line_id' => $this->incomeLine->id, 'amount' => 1000, 'entry_date' => '2026-04-20', 'description' => 'Late tithes'])->assertCreated();
        $this->assertEquals(5000, $this->getJson('/api/monthly-reports/2026/4')->json('data.figures.money.income'));
        $this->putJson('/api/monthly-reports/2026/4', ['achievements' => 'Changed'])->assertStatus(422);

        // A month that hasn't started can't be sent.
        $this->postJson('/api/monthly-reports/2026/6/send')->assertStatus(422);
    }

    public function test_the_place_above_sees_marks_seen_and_comments(): void
    {
        config(['audit.console' => true]);
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/monthly-reports/2026/4', ['challenges' => 'Rain'])->assertOk();
        $id = MonthlyReport::value('id');

        // A draft isn't seen from above.
        Sanctum::actingAs($this->overseer);
        $this->getJson("/api/monthly-reports/{$id}")->assertNotFound();

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $this->postJson('/api/monthly-reports/2026/4/reopen')->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $this->assertTrue($this->overseer->fresh()->notifications->contains(fn ($n) => str_contains($n->data['title'], 'April 2026 report')));

        Sanctum::actingAs($this->overseer);
        $below = $this->getJson('/api/monthly-reports/below?year=2026&month=4')->assertOk()->json('data');
        $this->assertSame(['My Church', 'Other Church'], array_column(array_column($below['rows'], 'place'), 'name'));
        $this->assertSame(1, $below['counts']['waiting']);
        $this->getJson("/api/monthly-reports/{$id}")->assertOk()->assertJsonPath('data.can.seen', true)->assertJsonPath('data.can.write', false);
        $this->postJson("/api/monthly-reports/{$id}/comments", ['body' => 'Praying for you.'])->assertCreated()
            ->assertJsonPath('data.comments', 1)->assertJsonPath('data.thread.0.body', 'Praying for you.')->assertJsonPath('data.thread.0.from_above', true);
        $this->postJson("/api/monthly-reports/{$id}/seen")->assertOk()->assertJsonPath('data.status', 'seen');
        $titles = $this->pastor->fresh()->notifications->pluck('data.body');
        $this->assertTrue($titles->contains('Region A has read it.'));
        $this->assertTrue($titles->contains('Region A: Praying for you.'));

        // Seen can't be taken back; the church answers in the thread.
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/monthly-reports/2026/4/reopen')->assertStatus(422);
        $this->postJson("/api/monthly-reports/{$id}/comments", ['body' => 'Thank you.'])->assertCreated();
        $this->postJson("/api/monthly-reports/{$id}/seen")->assertForbidden();

        // Not another region's church; the diocese sees regions and churches.
        Sanctum::actingAs($this->farPastor);
        $this->getJson("/api/monthly-reports/{$id}")->assertNotFound();
        Sanctum::actingAs($this->bishop);
        $rows = $this->getJson('/api/monthly-reports/below?year=2026&month=4')->assertOk()->json('data.rows');
        $this->assertSame(['region', 'region', 'church', 'church', 'church'], array_column(array_column($rows, 'place'), 'type'));
        $this->postJson('/api/monthly-reports/2026/4/send')->assertForbidden();
    }

    public function test_the_year_shows_each_months_state_against_the_due_day(): void
    {
        app(Settings::class)->setMany($this->diocese, 'diocese', 'reports', ['reports.monthly_due_day' => 10]);
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/monthly-reports/2026/3', ['achievements' => 'x'])->assertOk();
        $this->postJson('/api/monthly-reports/2026/3/send')->assertOk(); // sent on 2 May: after 10 April - late

        $data = $this->getJson('/api/monthly-reports?year=2026')->assertOk()->json('data');
        $m = collect($data['months'])->keyBy('month');
        $this->assertSame(10, $data['due_day']);
        $this->assertSame('2026-05-10', $m[4]['due_on']);
        $this->assertFalse($m[4]['late']); // April: due 10 May, today is 2 May
        $this->assertSame(8, $m[4]['due_in_days']);
        $this->assertTrue($m[2]['late']); // February never started, past 10 March
        $this->assertSame('sent', $m[3]['status']);
        $this->assertFalse($m[3]['on_time']);
        $this->assertFalse($m[6]['open']);
        $this->assertSame(4, $data['figures']['next']['month']);

        // Months before monthly reports began aren't late.
        config(['app.monthly_reports_from' => '2026-03']);
        $m = collect($this->getJson('/api/monthly-reports?year=2026')->json('data.months'))->keyBy('month');
        $this->assertSame(['not_tracked', false], [$m[2]['status'], $m[2]['late']]);
        $this->assertSame('sent', $m[3]['status']);
    }

    public function test_attachments(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/monthly-reports/2026/4', ['testimonies' => 'Healing'])->assertOk();
        $id = MonthlyReport::value('id');
        for ($i = 1; $i <= 5; $i++) {
            $this->post("/api/monthly-reports/{$id}/attachments", ['file' => UploadedFile::fake()->image("photo{$i}.jpg")], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post("/api/monthly-reports/{$id}/attachments", ['file' => UploadedFile::fake()->image('six.jpg')], ['Accept' => 'application/json'])->assertStatus(422);
        $first = MonthlyReport::find($id)->getMedia('attachments')->first();
        $this->deleteJson("/api/monthly-reports/{$id}/attachments/{$first->id}")->assertOk();
        $this->post("/api/monthly-reports/{$id}/attachments", ['file' => UploadedFile::fake()->create('notes.txt', 3, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $media = MonthlyReport::find($id)->getMedia('attachments')->first();

        Sanctum::actingAs($this->overseer);
        $this->get("/api/monthly-reports/{$id}/attachments/{$media->id}")->assertOk();
        Sanctum::actingAs($this->farPastor);
        $this->getJson("/api/monthly-reports/{$id}/attachments/{$media->id}")->assertNotFound();
    }

    public function test_reminders_go_on_the_right_days_once_each(): void
    {
        $this->pastor->forceFill(['phone' => '0712345678'])->save();
        // April's report is due 5 May: reminders on 2, 5 and 8 May.
        $this->artisan('reports:remind', ['--date' => '2026-05-01'])->assertSuccessful();
        $this->assertSame(0, $this->pastor->fresh()->notifications()->count());

        $this->artisan('reports:remind', ['--date' => '2026-05-02'])->assertSuccessful();
        $this->artisan('reports:remind', ['--date' => '2026-05-02'])->assertSuccessful(); // twice - still once
        $this->assertSame(["April's report is due in 3 days"], $this->pastor->fresh()->notifications->pluck('data.title')->all());
        $this->assertSame(1, MessageLog::where('kind', 'report_reminder')->count());
        $this->assertStringContainsString("My Church: April's monthly report is due on Tue 5 May", MessageLog::where('kind', 'report_reminder')->value('body'));

        $this->artisan('reports:remind', ['--date' => '2026-05-05'])->assertSuccessful();
        $this->assertSame(2, $this->pastor->fresh()->notifications()->count());

        // Sent before the late reminder: none comes.
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $this->artisan('reports:remind', ['--date' => '2026-05-08'])->assertSuccessful();
        $this->assertSame(2, $this->pastor->fresh()->notifications()->count());

        // Other Church never sent: it got all three, by app (no one there has a phone).
        $this->assertSame(0, MessageLog::where('territory_id', $this->otherChurch->id)->count());
    }

    public function test_the_calendar_shows_the_report_due_day(): void
    {
        $this->pastor->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => 'church.calendar.events.read', 'guard_name' => 'web'], ['action' => 'read', 'territory_scope' => 'church']));
        Sanctum::actingAs($this->pastor);
        $due = collect($this->getJson('/api/calendar/events?from=2026-05-01&to=2026-05-31&sources[]=due')->assertOk()->json('data'))->firstWhere('title', "April's report due");
        $this->assertSame('2026-05-05', $due['start']);
        $this->assertSame('/church/monthly-reports/report?year=2026&month=4', $due['url']);

        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $this->assertNull(collect($this->getJson('/api/calendar/events?from=2026-05-01&to=2026-05-31&sources[]=due')->json('data'))->firstWhere('title', "April's report due"));
    }

    public function test_the_exports_build(): void
    {
        $this->recordApril();
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/monthly-reports/2026/4', ['achievements' => 'A fruitful Easter.'])->assertOk();
        $this->postJson('/api/monthly-reports/2026/4/send')->assertOk();
        $id = MonthlyReport::value('id');

        $one = (new MonthlyReportExport)->build(new ReportContext($this->myChurch, $this->pastor, ['report_id' => $id]));
        $this->assertSame(['Figures', 'What happened', "In the pastor's words"], array_map(fn ($s) => $s->heading, $one->sections));
        $status = (new MonthlyStatusReport)->build(new ReportContext($this->region, $this->overseer, ['year' => 2026, 'month' => 4]));
        $this->assertSame([['-', 'My Church', 'Church', 'Sent', now()->format('j M Y')], ['-', 'Other Church', 'Church', 'Not started', '-']], $status->sections[0]->rows);
        $this->assertNull((new MonthlyStatusReport)->authorize($this->overseer, $this->region));
        $this->assertNotNull((new MonthlyReportExport)->authorize($this->farPastor, $this->myChurch));
    }

    public function test_the_seeder_reuses_the_placeholders_and_is_idempotent(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-overview"], ['name' => 'Overview', 'territory_scope' => $level, 'is_active' => true]);
        }
        $old = Module::create(['module_group_id' => ModuleGroup::where('slug', 'church-overview')->value('id'), 'name' => 'Church Reports', 'icon' => 'x', 'number' => 37, 'is_active' => false]);
        $oldPage = Submodule::create(['module_id' => $old->id, 'title' => 'Monthly Reporting', 'path' => '/reports/monthly', 'is_active' => true]);
        $senior = Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $bishop = Role::firstOrCreate(['name' => 'Bishop', 'guard_name' => 'web'], ['territory_level' => 'diocese']);

        $this->seed(MonthlyReportsAccessSeeder::class);
        $this->seed(MonthlyReportsAccessSeeder::class);

        $this->assertSame('Monthly reports', $old->fresh()->name);
        $this->assertTrue((bool) $old->fresh()->is_active);
        $this->assertFalse((bool) $oldPage->fresh()->is_active);
        foreach (['church', 'region', 'diocese'] as $level) {
            $this->assertSame(1, Submodule::where('path', "/{$level}/monthly-reports/")->count());
        }
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.reports.monthly.send'));
        $this->assertTrue($bishop->fresh()->hasPermissionTo('diocese.reports.below.review'));
        $this->assertFalse(Permission::where('name', 'diocese.reports.monthly.write')->exists());

        // The way in for those who write their own: "Write our report", holding write. Not the diocese.
        foreach (['church', 'region'] as $level) {
            $write = Submodule::where('path', "/{$level}/monthly-reports/report.php")->sole();
            $this->assertSame('Write our report', $write->title);
            $this->assertTrue((bool) $write->is_active);
            $this->assertSame($write->id, Permission::where('name', "{$level}.reports.monthly.write")->value('submodule_id'));
        }
        $this->assertFalse(Submodule::where('path', '/diocese/monthly-reports/report.php')->exists());
    }
}
