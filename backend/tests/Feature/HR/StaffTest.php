<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HrAllowanceType;
use App\Models\Payslip;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Accounting\BuildsBooks;
use Tests\TestCase;

/**
 * Staff (docs/specs/hr-spec.md): each level's positions, grades and
 * allowances with the diocese's as defaults; staff from a member, a login or
 * a name; transfers made from the level above; the place they were last
 * posted to in a month pays it; the region sees its churches' staff.
 */
class StaffTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $hrPastor;

    private User $hrOverseer;

    private User $hrBishop;

    private User $reader;

    private User $otherPastor;

    private Person $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->hrPastor = $this->userWithRole('hrpastor', 'HR Pastor', 'church', $this->myChurch->id, $this->hr('church', ['read', 'manage', 'setup']));
        $this->hrOverseer = $this->userWithRole('hroverseer', 'HR Overseer', 'region', $this->region->id, $this->hr('region', ['read', 'manage', 'setup', 'below']));
        $this->hrBishop = $this->userWithRole('hrbishop', 'HR Bishop', 'diocese', $this->diocese->id, $this->hr('diocese', ['read', 'manage', 'setup', 'below']));
        $this->reader = $this->userWithRole('hrreader', 'HR Reader', 'church', $this->myChurch->id, $this->hr('church', ['read']));
        $this->otherPastor = $this->userWithRole('hrother', 'HR Other', 'church', $this->otherChurch->id, $this->hr('church', ['read', 'manage', 'setup']));
        $this->member = Person::create(['territory_id' => $this->myChurch->id, 'first_name' => 'Ruth', 'last_name' => 'Mwende', 'phone' => '+254757150682', 'status' => 'member']);
    }

    private function hr(string $level, array $abilities): array
    {
        return array_map(fn ($a) => "{$level}.".\App\Support\HrAccess::ABILITIES[$a], $abilities);
    }

    /** The diocese's grade G2 (10,000-20,000), Caretaker and House 3,000; the region's Transport. */
    private function lists(): array
    {
        Sanctum::actingAs($this->hrBishop);
        $g2 = $this->postJson('/api/hr/setup/grade', ['code' => 'g2', 'name' => 'Grade 2', 'min_pay' => 10000, 'max_pay' => 20000, 'default_pay' => 15000])->assertCreated()->json('data.id');
        $caretaker = $this->postJson('/api/hr/setup/position', ['name' => 'Caretaker', 'grade_id' => $g2])->assertCreated()->json('data.id');
        $this->postJson('/api/hr/setup/position', ['name' => 'Regional Overseer', 'levels' => ['region']])->assertCreated();
        $house = $this->postJson('/api/hr/setup/allowance', ['name' => 'House', 'default_amount' => 3000])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->hrOverseer);
        $transport = $this->postJson('/api/hr/setup/allowance', ['name' => 'Transport', 'default_amount' => 1500])->assertCreated()->json('data.id');

        return compact('g2', 'caretaker', 'house', 'transport');
    }

    public function test_each_level_sets_its_own_lists_with_the_dioceses_as_defaults(): void
    {
        $l = $this->lists();
        Sanctum::actingAs($this->hrPastor);
        $setup = $this->getJson('/api/hr/setup')->assertOk()->json('data');
        $this->assertSame(['House', 'Transport'], array_column($setup['allowances'], 'name'), 'the diocese\'s, then the region\'s');
        $this->assertSame('Test Diocese', $setup['allowances'][0]['owner']['name']);
        $this->assertFalse($setup['allowances'][0]['can']['edit']);
        $this->postJson('/api/hr/setup/allowance', ['name' => 'Airtime', 'default_amount' => 500])->assertCreated();
        $this->assertSame(['Caretaker'], array_column($setup['positions'], 'name'), 'a region\'s own jobs are not a church\'s to set up');
        // Positions meant for a region aren't offered at a church.
        $this->assertSame(['Caretaker'], array_column($this->getJson('/api/hr/options')->json('data.positions'), 'name'));

        // Switched off here; still on at the other church.
        $this->postJson("/api/hr/setup/allowance/{$l['house']}/here", ['on' => false])->assertOk()->assertJsonPath('data.hidden', true);
        $this->assertSame(['Transport', 'Airtime'], array_column($this->getJson('/api/hr/options')->json('data.allowances'), 'name'));
        Sanctum::actingAs($this->otherPastor);
        $this->assertContains('House', array_column($this->getJson('/api/hr/options')->json('data.allowances'), 'name'));

        // Only the owner changes or removes it; one in use is switched off instead.
        Sanctum::actingAs($this->hrPastor);
        $this->putJson("/api/hr/setup/allowance/{$l['house']}", ['name' => 'Housing'])->assertUnprocessable();
        $this->deleteJson("/api/hr/setup/grade/{$l['g2']}")->assertUnprocessable();
        Sanctum::actingAs($this->hrBishop);
        $this->deleteJson("/api/hr/setup/grade/{$l['g2']}")->assertUnprocessable()->assertJsonPath('errors.id.0', 'It is in use (1) - switch it off instead.');
        Sanctum::actingAs($this->reader);
        $this->postJson('/api/hr/setup/allowance', ['name' => 'Lunch'])->assertForbidden();
    }

    public function test_staff_come_from_a_member_a_login_or_a_name(): void
    {
        $l = $this->lists();
        Sanctum::actingAs($this->hrPastor);
        $this->assertSame('Ruth Mwende', $this->getJson('/api/hr/people?q=ruth')->assertOk()->json('data.0.name'));
        $this->postJson('/api/hr/staff', ['person_id' => $this->member->id, 'position_id' => $l['caretaker'], 'basic_pay' => 25000])
            ->assertUnprocessable()->assertJsonPath('errors.basic_pay.0', 'Grade G2 pays KES 10,000 to KES 20,000 a month.');
        $id = $this->postJson('/api/hr/staff', ['person_id' => $this->member->id, 'position_id' => $l['caretaker'], 'allowances' => [['type_id' => $l['transport']]]])
            ->assertCreated()->json('data');
        $this->assertEquals(['Ruth Mwende', '+254757150682', 'Caretaker', 'G2', 15000, 16500], [$id['name'], $id['phone'], $id['position'], $id['grade']['code'], $id['basic_pay'], $id['gross']],
            'name and phone from the member record, grade and usual pay from the position, Transport at its usual amount');
        $this->assertSame('hired', $id['postings'][0]['reason']);

        $login = $this->postJson('/api/hr/staff', ['user_id' => $this->treasurer->id, 'basic_pay' => 18000])->assertCreated()->json('data');
        $this->assertSame([$this->treasurer->id, 'Test Treasurer'], [$login['user']['id'], $login['name']]);
        $this->postJson('/api/hr/staff', ['user_id' => $this->otherTreasurer->id, 'basic_pay' => 18000])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/hr/staff', ['name' => 'Kioko Mutinda', 'position' => 'Night guard', 'basic_pay' => 9000])->assertCreated();
        $this->postJson('/api/hr/staff', ['basic_pay' => 9000])->assertUnprocessable()->assertJsonValidationErrors('name');

        // The region employs a member of a church below; the church's reader can't change anything.
        Sanctum::actingAs($this->hrOverseer);
        $this->getJson("/api/hr/people?q=ruth&church_id={$this->farChurch->id}")->assertUnprocessable();
        $this->postJson('/api/hr/staff', ['person_id' => $this->member->id, 'name' => 'Ruth Mwende', 'basic_pay' => 30000])->assertCreated()->assertJsonPath('data.place.level', 'region');
        Sanctum::actingAs($this->reader);
        $this->assertSame(3, $this->getJson('/api/hr/staff')->assertOk()->json('data.total'));
        $this->putJson("/api/hr/staff/{$id['id']}", ['basic_pay' => 12000])->assertForbidden();
        $this->getJson("/api/hr/staff/{$id['id']}")->assertOk()->assertJsonPath('data.id_number', null);
    }

    public function test_the_region_sees_its_churches_staff_and_another_church_sees_nothing(): void
    {
        Sanctum::actingAs($this->hrPastor);
        $id = $this->postJson('/api/hr/staff', ['name' => 'Kioko Mutinda', 'basic_pay' => 9000, 'id_number' => '12345678'])->assertCreated()->assertJsonPath('data.id_number', '•••• 5678')->json('data.id');
        Sanctum::actingAs($this->hrOverseer);
        $this->assertSame(0, $this->getJson('/api/hr/staff')->json('data.total'));
        $this->assertSame('Kioko Mutinda', $this->getJson('/api/hr/staff?below=1')->assertOk()->json('data.rows.0.name'));
        $this->getJson("/api/hr/staff/{$id}")->assertOk()->assertJsonPath('data.basic_pay', 9000);
        $this->assertSame(9000, $this->getJson('/api/hr/overview')->json('data.below.0.monthly_pay'));
        $this->putJson("/api/hr/staff/{$id}", ['basic_pay' => 1])->assertForbidden();
        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/hr/staff/{$id}")->assertNotFound();
        $this->getJson("/api/hr/staff?territory_id={$this->myChurch->id}")->assertForbidden();
    }

    public function test_a_transfer_from_the_level_above_moves_who_pays_the_month(): void
    {
        $now = now()->format('Y-m');
        $next = now()->addMonth()->format('Y-m');
        Sanctum::actingAs($this->hrPastor);
        $id = $this->postJson('/api/hr/staff', ['name' => 'Pastor Musyoka', 'basic_pay' => 20000, 'start_date' => "{$now}-01", 'allowances' => [['name' => 'House', 'amount' => 5000]]])->assertCreated()->json('data.id');
        $this->postJson("/api/hr/staff/{$id}/transfer", ['to_territory_id' => $this->otherChurch->id, 'date' => "{$next}-10"])->assertForbidden();

        Sanctum::actingAs($this->hrOverseer);
        $this->postJson("/api/hr/staff/{$id}/transfer", ['to_territory_id' => $this->farChurch->id, 'date' => "{$next}-10"])->assertForbidden();
        $this->postJson("/api/hr/staff/{$id}/transfer", ['to_territory_id' => $this->otherChurch->id, 'date' => "{$next}-10", 'note' => 'Posted by the region'])
            ->assertOk()->assertJsonPath('data.place.id', $this->otherChurch->id)->assertJsonPath('data.postings.0.reason', 'transferred');

        // This month My Church pays; next month (moved on the 10th) Other Church pays the whole month.
        Sanctum::actingAs($this->treasurer);
        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => $now])->assertCreated()->json('data');
        $this->assertSame(['Pastor Musyoka'], array_column($run['payslips'], 'name'));
        $this->assertSame(25000.0, (float) $run['gross'], 'basic and the allowance from Staff');
        $this->postJson('/api/accounting/payroll/runs', ['month' => $next])->assertUnprocessable();
        Sanctum::actingAs($this->otherTreasurer);
        $this->postJson('/api/accounting/payroll/runs', ['month' => $next])->assertCreated()->assertJsonPath('data.payslips.0.name', 'Pastor Musyoka');
    }

    public function test_leaving_and_removing(): void
    {
        $now = now()->format('Y-m');
        Sanctum::actingAs($this->hrPastor);
        $paid = $this->postJson('/api/hr/staff', ['name' => 'Mwikali Nduku', 'basic_pay' => 19000, 'start_date' => "{$now}-01"])->assertCreated()->json('data.id');
        $this->postJson("/api/hr/staff/{$paid}/end", ['date' => now()->toDateString(), 'reason' => 'Moved away'])->assertOk()->assertJsonPath('data.postings.0.reason', 'left');

        Sanctum::actingAs($this->treasurer);
        $this->assertContains('Mwikali Nduku', array_column($this->postJson('/api/accounting/payroll/runs', ['month' => $now])->assertCreated()->json('data.payslips'), 'name'), 'paid for the month they leave');
        $this->assertFalse(Employee::find($paid)->paidIn(now()->addMonth()->format('Y-m')), 'not after');

        Sanctum::actingAs($this->hrPastor);
        $never = $this->postJson('/api/hr/staff', ['name' => 'Typed by mistake', 'basic_pay' => 1000])->assertCreated()->json('data.id');
        $this->assertTrue(Payslip::where('employee_id', $paid)->exists());
        $this->deleteJson("/api/hr/staff/{$paid}")->assertUnprocessable();
        $this->deleteJson("/api/hr/staff/{$never}")->assertOk();
        $this->assertNull(Employee::find($never));
    }

    public function test_payroll_still_adds_people_through_staff(): void
    {
        $l = $this->lists();
        Sanctum::actingAs($this->treasurer);
        $id = $this->postJson('/api/accounting/payroll/employees', ['name' => 'Kioko Mutinda', 'position' => 'Caretaker', 'basic_pay' => 12000, 'allowances' => [['name' => 'House', 'amount' => 2000]]])
            ->assertCreated()->json('data.id');
        $this->assertSame(1, \App\Models\StaffPosting::where('employee_id', $id)->count(), 'a posting, as for anyone added in Staff');
        $this->assertTrue(HrAllowanceType::whereKey($l['house'])->exists());
    }

    public function test_the_seeder_adds_the_staff_menu_after_finance_and_grants_once(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            \App\Models\ModuleGroup::firstOrCreate(['slug' => "{$level}-finance"], ['name' => 'Finance', 'territory_scope' => $level, 'order' => 3, 'icon' => 'ri-money-dollar-circle-line', 'is_active' => true]);
            \App\Models\ModuleGroup::firstOrCreate(['slug' => "{$level}-programs"], ['name' => 'Programs', 'territory_scope' => $level, 'order' => 4, 'icon' => 'ri-calendar-line', 'is_active' => true]);
        }
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $this->seed(\Database\Seeders\HrAccessSeeder::class);
        $pastor = \Spatie\Permission\Models\Role::where('name', 'Senior Pastor')->first();
        $this->assertTrue($pastor->hasPermissionTo('church.hr.staff.manage'));
        // The admin takes it away; seeding again doesn't put it back.
        $pastor->revokePermissionTo('church.hr.staff.manage');
        $this->seed(\Database\Seeders\HrAccessSeeder::class);
        $this->assertFalse($pastor->fresh()->hasPermissionTo('church.hr.staff.manage'));

        $staff = \App\Models\ModuleGroup::where('slug', 'church-staff')->firstOrFail();
        $this->assertSame([4, 5], [$staff->order, \App\Models\ModuleGroup::where('slug', 'church-programs')->value('order')], 'right after Finance; Programs moves down one');
        $this->assertSame(['Staff', 'Positions & pay'], \App\Models\Submodule::where('path', 'like', '/church/hr/%')->orderBy('order')->pluck('title')->all());
        $this->assertSame(1, \App\Models\ModuleGroup::where('slug', 'church-staff')->count());
        $this->assertTrue(\App\Models\HrPosition::where('territory_id', $this->diocese->id)->where('name', 'Caretaker')->exists());
        $this->assertSame(['House', 'Transport', 'Responsibility', 'Airtime'], HrAllowanceType::where('territory_id', $this->diocese->id)->orderBy('display_order')->pluck('name')->all());
    }
}
