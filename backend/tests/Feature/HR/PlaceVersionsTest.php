<?php

namespace Tests\Feature\HR;

use App\Models\Person;
use App\Models\User;
use App\Support\HrAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Accounting\BuildsBooks;
use Tests\TestCase;

/**
 * Our version (docs/specs/hr-spec.md): a church or region gives a diocese
 * position its own pay package, a grade its own range, an allowance its own
 * amount - the nearest place's version wins - and the person's own page shows
 * their payslips and totals.
 */
class PlaceVersionsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $hrPastor;

    private User $hrOverseer;

    private User $hrBishop;

    private User $hrOther;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $hr = fn (string $level, array $a) => array_map(fn ($x) => "{$level}.".HrAccess::ABILITIES[$x], $a);
        $this->hrPastor = $this->userWithRole('hrpastor', 'HR Pastor', 'church', $this->myChurch->id, $hr('church', ['read', 'manage', 'setup']));
        $this->hrOverseer = $this->userWithRole('hroverseer', 'HR Overseer', 'region', $this->region->id, $hr('region', ['read', 'manage', 'setup', 'below']));
        $this->hrBishop = $this->userWithRole('hrbishop', 'HR Bishop', 'diocese', $this->diocese->id, $hr('diocese', ['read', 'manage', 'setup', 'below']));
        $this->hrOther = $this->userWithRole('hrother', 'HR Other', 'church', $this->otherChurch->id, $hr('church', ['read', 'manage']));
    }

    /** The diocese: Senior Pastor, grade P1 (20,000-60,000), House and Transport. */
    private function diocese(): array
    {
        Sanctum::actingAs($this->hrBishop);
        $p1 = $this->postJson('/api/hr/setup/grade', ['code' => 'P1', 'name' => 'Pastors', 'min_pay' => 20000, 'max_pay' => 60000, 'default_pay' => 25000])->json('data.id');
        $pastor = $this->postJson('/api/hr/setup/position', ['name' => 'Senior Pastor', 'levels' => ['church'], 'grade_id' => $p1])->json('data.id');
        $house = $this->postJson('/api/hr/setup/allowance', ['name' => 'House', 'default_amount' => 3000])->json('data.id');
        $transport = $this->postJson('/api/hr/setup/allowance', ['name' => 'Transport', 'default_amount' => 1000])->json('data.id');

        return compact('p1', 'pastor', 'house', 'transport');
    }

    public function test_the_nearest_version_wins_and_removing_ours_falls_back(): void
    {
        $d = $this->diocese();
        // The region's version: 28,000 with House.
        Sanctum::actingAs($this->hrOverseer);
        $this->putJson("/api/hr/setup/position/{$d['pastor']}/ours", ['grade_id' => $d['p1'], 'default_pay' => 28000, 'allowances' => [['type_id' => $d['house']]]])->assertOk()->assertJsonPath('data.ours', true);
        $this->putJson("/api/hr/setup/allowance/{$d['house']}/ours", ['default_amount' => 4000])->assertOk();

        // The church gets the region's.
        Sanctum::actingAs($this->hrPastor);
        $detail = $this->getJson("/api/hr/setup/position/{$d['pastor']}")->assertOk()->json('data');
        $this->assertSame(['Region A', 28000, false], [$detail['version_from']['name'], (int) $detail['default_pay'], $detail['ours']]);
        $this->assertEquals([['type_id' => $d['house'], 'name' => 'House', 'amount' => 4000, 'follows' => true]], $detail['allowances'], 'House at the region\'s amount');
        $this->assertNull($detail['our_version']);

        // Our own: 30,000 with House 5,000 and Transport; out of the grade's range is refused.
        $this->putJson("/api/hr/setup/position/{$d['pastor']}/ours", ['grade_id' => $d['p1'], 'default_pay' => 70000])->assertUnprocessable()->assertJsonValidationErrors('default_pay');
        $this->putJson("/api/hr/setup/position/{$d['pastor']}/ours", ['grade_id' => $d['p1'], 'default_pay' => 30000, 'duties' => 'Leads the church',
            'allowances' => [['type_id' => $d['house'], 'amount' => 5000], ['type_id' => $d['transport']]]])->assertOk();
        $detail = $this->getJson("/api/hr/setup/position/{$d['pastor']}")->json('data');
        $this->assertSame([30000, 'Leads the church', 'Region A'], [(int) $detail['default_pay'], $detail['duties'], $detail['from_above']['from']['name']]);
        $this->assertEquals([5000, 1000], array_column($detail['allowances'], 'amount'));

        // Adding someone with the position takes our package.
        $e = $this->postJson('/api/hr/staff', ['name' => 'Pastor Musyoka', 'position_id' => $d['pastor']])->assertCreated()->json('data');
        $this->assertSame(['P1', 30000, 36000], [$e['grade']['code'], (int) $e['basic_pay'], (int) $e['gross']]);
        $this->assertSame(1, $this->getJson("/api/hr/setup/position/{$d['pastor']}")->json('data.in_use'));
        $this->assertSame('Pastor Musyoka', $this->getJson("/api/hr/setup/position/{$d['pastor']}")->json('data.holders.0.name'));

        // Back to the region's.
        $this->deleteJson("/api/hr/setup/position/{$d['pastor']}/ours")->assertOk()->assertJsonPath('data.ours', false);
        $this->assertSame(28000, (int) $this->getJson("/api/hr/setup/position/{$d['pastor']}")->json('data.default_pay'));

        // Another church gets the region's too, never ours; one without setup can't set its own.
        Sanctum::actingAs($this->hrOther);
        $this->assertSame(28000, (int) $this->getJson("/api/hr/setup/position/{$d['pastor']}")->json('data.default_pay'));
        $this->putJson("/api/hr/setup/position/{$d['pastor']}/ours", ['default_pay' => 25000])->assertForbidden();
        // The owner changes its own row, not a version of it.
        Sanctum::actingAs($this->hrBishop);
        $this->putJson("/api/hr/setup/position/{$d['pastor']}/ours", ['default_pay' => 25000])->assertUnprocessable();
    }

    public function test_the_person_page_shows_their_payslips_and_member_record(): void
    {
        $member = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Ruth', 'last_name' => 'Mwende', 'phone' => '+254757150682', 'status' => 'member']);
        Sanctum::actingAs($this->hrPastor);
        $id = $this->postJson('/api/hr/staff', ['person_id' => $member->id, 'basic_pay' => 15000, 'start_date' => now()->format('Y-m').'-01'])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->treasurer);
        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated()->json('data.id');
        \App\Models\PayrollRun::whereKey($run)->update(['status' => 'paid']);

        Sanctum::actingAs($this->hrPastor);
        $p = $this->getJson("/api/hr/staff/{$id}")->assertOk()->json('data');
        $this->assertSame([1, 15000], [count($p['payslips']), (int) $p['payslips'][0]['net']]);
        $this->assertSame([15000, 1], [(int) $p['totals']['this_year'], $p['totals']['months']]);
        $this->assertNull($p['member_link'], 'HR alone doesn\'t open the member register');
    }
}
