<?php

namespace Tests\Feature\HR;

use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Accounting\BuildsBooks;
use Tests\TestCase;

/**
 * The Staff dashboard (docs/specs/hr-spec.md → Dashboard): what we employ and
 * pay a month, what approved or paid runs came to this year, month by month,
 * what is coming up; the region sees each church below, a church only itself.
 */
class DashboardTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $hrPastor;

    private User $hrOverseer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $hr = fn (string $level, array $abilities) => array_map(fn ($a) => "{$level}.".\App\Support\HrAccess::ABILITIES[$a], $abilities);
        $this->hrPastor = $this->userWithRole('dashpastor', 'Dash Pastor', 'church', $this->myChurch->id, $hr('church', ['read', 'manage']));
        $this->hrOverseer = $this->userWithRole('dashoverseer', 'Dash Overseer', 'region', $this->region->id, $hr('region', ['read', 'below']));
    }

    public function test_figures_months_and_coming_up_add_up(): void
    {
        $month = now()->format('Y-m');
        Sanctum::actingAs($this->hrPastor);
        $this->postJson('/api/hr/staff', ['name' => 'Kioko Mutinda', 'basic_pay' => 12000, 'start_date' => now()->subYears(2)->addDays(10)->toDateString(), 'allowances' => [['name' => 'House', 'amount' => 3000]]])->assertCreated();
        $this->postJson('/api/hr/staff', ['name' => 'Mwikali Nduku', 'basic_pay' => 19000, 'employment_type' => 'contract', 'start_date' => now()->subMonths(3)->toDateString(), 'contract_end' => now()->addDays(20)->toDateString()])->assertCreated();

        Sanctum::actingAs($this->treasurer);
        $run = $this->postJson('/api/accounting/payroll/runs', ['month' => $month])->assertCreated()->json('data.id');
        $draft = $this->getDash($this->hrPastor);
        $this->assertSame(0.0, (float) $draft['figures']['paid'], 'a draft run is not paid');
        PayrollRun::whereKey($run)->update(['status' => 'paid']);

        $d = $this->getDash($this->hrPastor);
        $this->assertSame(2, $d['figures']['staff']);
        $this->assertEquals(34000, $d['figures']['monthly_pay'], 'basic and allowances');
        $this->assertEquals(17000, $d['figures']['average']);
        $this->assertEquals(34000, $d['figures']['paid']);
        $this->assertSame(1, $d['figures']['contracts_ending']);
        $this->assertEquals(34000, collect($d['months'])->firstWhere('month', $month)['gross']);
        $this->assertSame(2, collect($d['months'])->firstWhere('month', $month)['people']);
        $this->assertSame(12, count($d['months']));
        $this->assertSame(1, collect($d['by_type'])->firstWhere('key', 'contract')['count']);
        $this->assertSame(['anniversary', 'contract'], array_column($d['coming_up'], 'kind'), 'two years of service in 10 days, the contract ends in 20');
        $this->assertSame(2, $d['coming_up'][0]['years']);
        $this->assertSame('paid', $d['runs'][0]['status']);
        $this->assertSame([], $d['places'], 'a church sees only itself');
    }

    public function test_the_region_sees_each_place_below_and_a_church_sees_only_its_own(): void
    {
        Sanctum::actingAs($this->hrPastor);
        $this->postJson('/api/hr/staff', ['name' => 'Kioko Mutinda', 'basic_pay' => 12000])->assertCreated();

        $d = $this->getDash($this->hrOverseer);
        $this->assertSame(1, $d['figures']['staff']);
        $this->assertSame([$this->myChurch->id], array_column($d['places'], 'id'));
        $one = $this->getDash($this->hrOverseer, ['place_id' => $this->myChurch->id]);
        $this->assertSame($this->myChurch->id, $one['place_id']);
        $this->assertSame([], $one['places']);
        Sanctum::actingAs($this->hrOverseer);
        $this->getJson('/api/hr/dashboard?place_id='.$this->farChurch->id)->assertForbidden();

        Sanctum::actingAs($this->hrPastor);
        $this->getJson('/api/hr/dashboard?place_id='.$this->otherChurch->id)->assertForbidden();
        $this->getJson('/api/hr/dashboard?territory_id='.$this->otherChurch->id)->assertForbidden();
    }

    private function getDash(User $user, array $query = []): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/hr/dashboard?'.http_build_query($query))->assertOk()->json('data');
    }
}
