<?php

namespace Tests\Feature\People;

use App\Models\ChurchAttendanceRecord;
use App\Models\ChurchDemographic;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Ministry;
use App\Models\MinistryLeader;
use App\Models\Person;
use App\Services\People\Ministries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P4 (docs/specs/people-and-care-spec.md): ministries - the
 * standard six once per church, a ministry's own leader looks after its
 * members and nothing of another's, its gathering's attendance comes from
 * Attendance, and the region sees counts only.
 */
class MinistriesTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $youthLeader;

    private $otherPastor;

    private $regionLeader;

    private Person $mary;

    private Person $john;

    protected function setUp(): void
    {
        parent::setUp();
        // Before any person is saved: saving one loads the ministry models, and auditing is wired when a model first loads.
        config(['audit.console' => true]);
        $this->buildBudgetWorld();
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, ['church.ministries.ministries.read', 'church.ministries.ministries.manage', 'church.members.members.read']);
        $this->youthLeader = $this->userWithRole('youthlead', 'Youth Leader', 'church', $this->myChurch->id, ['church.ministries.ministries.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, ['church.ministries.ministries.read', 'church.ministries.ministries.manage']);
        $this->regionLeader = $this->userWithRole('regionlead', 'Region Lead', 'region', $this->region->id, ['region.ministries.below.read']);
        $this->mary = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Mary', 'last_name' => 'Mutua', 'phone' => '+254712345678', 'gender' => 'female', 'status' => 'member']);
        $this->john = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'John', 'last_name' => 'Kioko', 'phone' => '+254712345679', 'gender' => 'male', 'status' => 'member']);
    }

    private function ministry(string $kind): Ministry
    {
        return Ministry::where('territory_id', $this->myChurch->id)->where('standard', $kind)->firstOrFail();
    }

    public function test_the_standard_six_come_once_and_link_their_gathering(): void
    {
        $youthService = GatheringType::create(['territory_id' => $this->myChurch->id, 'gathering_category_id' => GatheringCategory::where('slug', 'ministry_gathering')->value('id'), 'name' => 'Youth Service', 'slug' => 'youth-service', 'is_active' => true]);
        Sanctum::actingAs($this->senior);
        $o = $this->getJson('/api/ministries/overview')->assertOk()->json('data');
        $this->assertSame(['Youth', 'Women', 'Men', 'Children & Sunday school', 'Music & choir', 'Prayer'], array_column($o['items'], 'name'));
        $this->getJson('/api/ministries/overview')->assertOk();
        app(Ministries::class)->ensure($this->myChurch);
        $this->assertSame(6, Ministry::where('territory_id', $this->myChurch->id)->count(), 'never twice');
        $this->assertSame($youthService->id, $this->ministry('youth')->gathering_type_id, 'Youth links to "Youth Service"');

        // A standard one is switched off, not removed - and isn't made again.
        $men = $this->ministry('men');
        $this->deleteJson("/api/ministries/{$men->id}")->assertStatus(422);
        $this->putJson("/api/ministries/{$men->id}", ['active' => false])->assertOk();
        $this->assertCount(5, $this->getJson('/api/ministries/overview')->json('data.items'));
        $this->assertCount(6, $this->getJson('/api/ministries/overview?all=1')->json('data.items'));

        // One of our own.
        $id = $this->postJson('/api/ministries', ['name' => 'Ushers', 'kind' => 'other', 'meets_day' => 6, 'meets_time' => '16:00'])->assertCreated()->assertJsonPath('data.meets', 'Saturdays 4:00 pm')->json('data.id');
        $this->postJson('/api/ministries', ['name' => 'Ushers', 'kind' => 'other'])->assertStatus(422);
        $this->deleteJson("/api/ministries/{$id}")->assertOk();
    }

    public function test_a_ministry_leader_looks_after_their_own_ministry_only(): void
    {
        config(['audit.console' => true]);
        Sanctum::actingAs($this->senior);
        $this->getJson('/api/ministries/overview')->assertOk();
        $youth = $this->ministry('youth');
        $women = $this->ministry('women');
        $this->putJson("/api/ministries/{$youth->id}/leaders", ['leaders' => [['user_id' => $this->youthLeader->id, 'role' => 'leader'], ['person_id' => $this->john->id, 'role' => 'assistant']]])
            ->assertOk()->assertJsonCount(2, 'data.leaders');

        Sanctum::actingAs($this->youthLeader);
        $this->getJson("/api/ministries/{$youth->id}")->assertJsonPath('data.can.roster', true)->assertJsonPath('data.can.manage', false);
        $this->assertSame([$this->john->id, $this->mary->id], array_column($this->getJson("/api/ministries/{$youth->id}/candidates")->assertOk()->json('data'), 'id'));
        $this->postJson("/api/ministries/{$youth->id}/members", ['person_ids' => [$this->mary->id, $this->john->id]])->assertOk()->assertJsonPath('data.added', 2);
        $this->assertSame([], $this->getJson("/api/ministries/{$youth->id}/candidates")->json('data'), 'nobody left to add');
        $this->getJson("/api/ministries/{$women->id}/candidates")->assertForbidden();
        $this->postJson("/api/ministries/{$youth->id}/members", ['person_ids' => [$this->mary->id]])->assertOk()->assertJsonPath('data.already', 1);
        $this->postJson("/api/ministries/{$youth->id}/members/remove", ['person_ids' => [$this->john->id]])->assertOk()->assertJsonPath('data.removed', 1);
        $this->putJson("/api/ministries/{$youth->id}", ['meets_day' => 5, 'meets_time' => '17:00'])->assertOk();
        $this->putJson("/api/ministries/{$youth->id}", ['name' => 'Teens'])->assertForbidden();
        $this->postJson("/api/ministries/{$women->id}/members", ['person_ids' => [$this->mary->id]])->assertForbidden();
        $this->putJson("/api/ministries/{$women->id}", ['meets_day' => 2])->assertForbidden();
        $this->putJson("/api/ministries/{$youth->id}/leaders", ['leaders' => []])->assertForbidden();
        $this->postJson('/api/ministries', ['name' => 'Mine', 'kind' => 'other'])->assertForbidden();

        $this->assertSame([['id' => $this->mary->id]], array_map(fn ($m) => ['id' => $m['id']], $this->getJson("/api/ministries/{$youth->id}/members")->json('data')));
        $history = collect($this->getJson("/api/ministries/{$youth->id}/history")->json('data'))->pluck('sentence')->all();
        $this->assertContains('Test Youthlead added Mary Mutua', $history);
        $this->assertContains('Test Youthlead took John Kioko out', $history);

        // The members list knows who serves where.
        Sanctum::actingAs($this->senior);
        $people = $this->getJson("/api/people?ministry={$youth->id}")->assertOk()->json('data.items');
        $this->assertSame([$this->mary->id], array_column($people, 'id'));
        $this->assertSame('Youth', $people[0]['ministries'][0]['name']);
        $this->assertSame([$this->john->id], array_column($this->getJson('/api/people?ministry=none')->json('data.items'), 'id'));
        $this->assertSame(1, $this->getJson('/api/ministries/insights')->json('data.not_serving'));

        // Leaving the register takes them out of the ministry.
        $this->mary->anonymise();
        $this->assertSame([], $this->getJson("/api/ministries/{$youth->id}/members")->json('data'));
        $this->assertSame(0, MinistryLeader::where('person_id', $this->mary->id)->count());
    }

    public function test_gatherings_come_from_attendance_and_the_region_sees_counts(): void
    {
        Sanctum::actingAs($this->senior);
        $this->getJson('/api/ministries/overview')->assertOk();
        $choir = GatheringType::create(['territory_id' => $this->myChurch->id, 'gathering_category_id' => GatheringCategory::where('slug', 'ministry_gathering')->value('id'), 'name' => 'Choir Practice', 'slug' => 'choir-practice', 'is_active' => true]);
        $music = $this->ministry('music');
        $this->getJson("/api/ministries/{$music->id}/gatherings")->assertOk()->assertJsonPath('data.linked', false)->assertJsonPath('data.detail', null);
        $this->putJson("/api/ministries/{$music->id}", ['gathering_type_id' => $choir->id])->assertOk();
        $today = now('Africa/Nairobi');
        $year = FiscalYear::create(['year' => $today->year, 'start_date' => "{$today->year}-01-01", 'end_date' => "{$today->year}-12-31", 'is_active' => true]);
        $month = FiscalMonth::firstOrCreate(['number' => $today->month], ['name' => $today->format('F'), 'short_name' => $today->format('M')]);
        ChurchAttendanceRecord::create(['territory_type' => 'church', 'territory_id' => $this->myChurch->id, 'service_date' => $today->toDateString(), 'fiscal_year_id' => $year->id, 'fiscal_month_id' => $month->id,
            'gathering_category_id' => $choir->gathering_category_id, 'gathering_type_id' => $choir->id, 'adults_count' => 20, 'youth_count' => 5, 'children_male_count' => 0, 'children_female_count' => 0]);
        $detail = $this->getJson("/api/ministries/{$music->id}/gatherings")->assertOk()->json('data.detail');
        $this->assertSame(1, $detail['summary']['times']);
        $this->assertSame(25, $detail['summary']['average']);
        $this->postJson("/api/ministries/{$music->id}/members", ['person_ids' => [$this->mary->id]])->assertOk();

        // Another church can't see it, or add our people to theirs.
        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/ministries/{$music->id}")->assertNotFound();
        $this->getJson('/api/ministries/overview')->assertOk();
        $theirs = Ministry::where('territory_id', $this->otherChurch->id)->where('standard', 'music')->first();
        $this->postJson("/api/ministries/{$theirs->id}/members", ['person_ids' => [$this->mary->id]])->assertStatus(422);
        $this->putJson("/api/ministries/{$theirs->id}", ['gathering_type_id' => $choir->id])->assertStatus(422);

        Sanctum::actingAs($this->regionLeader);
        $this->getJson('/api/ministries/overview')->assertForbidden();
        $t = $this->getJson('/api/ministries/totals')->assertOk()->json('data');
        $mine = collect($t['rows'])->firstWhere('church.id', $this->myChurch->id);
        $this->assertSame([6, 1, 1, 25], [$mine['ministries'], $mine['serving'], $mine['gatherings_this_month'], $mine['average_this_month']]);
        foreach (['Mary', 'Mutua', '+2547', '"name":"Music'] as $needle) {
            $this->assertStringNotContainsString($needle, json_encode($t));
        }
    }

    public function test_sunday_school_children_are_kept_in_their_ministry_and_the_monthly_figure_shows(): void
    {
        config(['audit.console' => true]);
        Sanctum::actingAs($this->senior);
        $child = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Joy', 'last_name' => 'Mutua', 'gender' => 'female', 'congregation' => 'sunday_school', 'status' => 'member']);
        $this->getJson('/api/ministries/overview')->assertOk();
        $children = $this->ministry('children');
        $this->assertSame([['id' => $child->id, 'auto' => true]], array_map(fn ($m) => ['id' => $m['id'], 'auto' => $m['auto']], $this->getJson("/api/ministries/{$children->id}/members")->json('data')), 'back-filled from the register');

        // A new Sunday-school child goes straight in; hand removal leaves them; leaving Sunday school takes them out.
        $tom = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Tom', 'last_name' => 'Kioko', 'gender' => 'male', 'congregation' => 'sunday_school', 'status' => 'member']);
        $this->assertCount(2, $this->getJson("/api/ministries/{$children->id}/members")->json('data'));
        $this->postJson("/api/ministries/{$children->id}/members/remove", ['person_ids' => [$tom->id]])->assertOk()->assertJsonPath('data.removed', 0)->assertJsonPath('data.kept', 1);
        $tom->update(['congregation' => 'main_church']);
        $this->assertSame([$child->id], array_column($this->getJson("/api/ministries/{$children->id}/members")->json('data'), 'id'));
        $history = collect($this->getJson("/api/ministries/{$children->id}/history")->json('data'))->pluck('sentence')->all();
        $this->assertContains('Test Senior added Tom Kioko', $history);
        $this->assertContains('Test Senior took Tom Kioko out', $history);

        // The church's own monthly figure for the kind - the latest approved report.
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $months = collect([7, 8])->mapWithKeys(fn ($n) => [$n => FiscalMonth::firstOrCreate(['number' => $n], ['name' => date('F', mktime(0, 0, 0, $n, 1)), 'short_name' => date('M', mktime(0, 0, 0, $n, 1))])]);
        $base = ['territory_type' => 'church', 'territory_id' => $this->myChurch->id, 'fiscal_year_id' => $year->id];
        ChurchDemographic::create($base + ['fiscal_month_id' => $months[7]->id, 'status' => 'approved', 'youth_count' => 40, 'sunday_school_male_count' => 10, 'sunday_school_female_count' => 12]);
        ChurchDemographic::create($base + ['fiscal_month_id' => $months[8]->id, 'status' => 'approved', 'youth_count' => 45]);
        ChurchDemographic::create($base + ['fiscal_month_id' => $months[8]->id, 'status' => 'draft', 'youth_count' => 99]);
        $this->getJson("/api/ministries/{$this->ministry('youth')->id}")->assertJsonPath('data.demographic.value', 45)->assertJsonPath('data.demographic.period', 'Aug 2026');
        $this->getJson("/api/ministries/{$this->ministry('music')->id}")->assertJsonPath('data.demographic', null);
    }
}
