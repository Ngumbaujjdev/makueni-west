<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Services\Accounting\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Petty cash on its float, month-end close and the board
 * (docs/specs/accounting-spec.md, A2).
 */
class PettyCashAndCloseTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_petty_cash_spends_within_its_box_and_tops_up_exactly_what_was_spent(): void
    {
        $this->receive(20000);
        Sanctum::actingAs($this->treasurer);
        $this->putJson('/api/accounting/petty-cash', ['imprest_float' => 5000, 'custodian_id' => $this->treasurer->id])->assertOk()->assertJsonPath('data.float', 5000);
        $this->postJson('/api/accounting/transfers', ['date' => $this->day(), 'from_account_id' => $this->cash()->id, 'to_account_id' => $this->chart->account('petty_cash')->id, 'amount' => 5000])->assertCreated();

        $this->postJson('/api/accounting/petty-cash/spend', ['date' => $this->day(), 'payee' => 'Duka', 'lines' => [['account_id' => $this->acc('5500')->id, 'amount' => 6000]]])->assertStatus(422);
        $this->postJson('/api/accounting/petty-cash/spend', ['date' => $this->day(), 'payee' => 'Duka', 'lines' => [['account_id' => $this->acc('5500')->id, 'amount' => 800]]])
            ->assertCreated()->assertJsonPath('data.doc_type', 'petty_cash')->assertJsonPath('data.number', fn ($n) => str_contains($n, '/PCV/'));
        $this->postJson('/api/accounting/petty-cash/spend', ['date' => $this->day(), 'payee' => 'Matatu', 'lines' => [['account_id' => $this->acc('5530')->id, 'amount' => 450]]])->assertCreated();

        $st = $this->getJson('/api/accounting/petty-cash')->assertOk()->json('data');
        $this->assertEquals(3750, $st['balance']);
        $this->assertEquals(1250, $st['top_up']);
        $this->assertEquals($st['float'], $st['balance'] + $st['spent_since'], 'cash in hand + vouchers = the float');

        $pv = $this->postJson('/api/accounting/petty-cash/top-up', ['from_account_id' => $this->cash()->id])->assertCreated()->json('data.voucher');
        $this->assertEquals(1250, $pv['amount']);
        $this->assertSame('imprest_topup', $pv['purpose']);
        $this->postJson('/api/accounting/petty-cash/top-up', ['from_account_id' => $this->cash()->id])->assertStatus(422);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $paid = $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => now()->toDateString()])->assertOk()->json('data');
        $this->getJson("/api/accounting/journals/{$paid['journal_id']}")->assertJsonPath('data.doc_type', 'transfer');
        $this->assertEquals(5000, app(Ledger::class)->balance($this->myChurch, $this->chart->account('petty_cash')), 'back to the float');
        $this->assertEquals(1250, app(Ledger::class)->balance($this->myChurch, $this->acc('5500')) + app(Ledger::class)->balance($this->myChurch, $this->acc('5530')));
    }

    public function test_a_month_closes_only_when_proven_in_order_and_only_the_level_above_reopens_it(): void
    {
        $m2 = CarbonImmutable::today()->subMonthsNoOverflow(2)->startOfMonth();
        $m1 = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $this->receive(1000, null, $m2->addDays(4)->toDateString());
        Sanctum::actingAs($this->treasurer);
        $close = fn ($m) => $this->postJson('/api/accounting/periods/close', ['year' => $m->year, 'month' => $m->month]);

        $close($m2)->assertStatus(422)->assertJsonValidationErrors('month');
        $months = collect($this->getJson('/api/accounting/periods?year='.$m2->year)->assertOk()->json('data.months'))->keyBy('month');
        $this->assertNotEmpty($months[$m2->month]['blockers'], 'cash at hand was not counted');

        $this->postJson('/api/accounting/cash-counts', ['account_id' => $this->cash()->id, 'counted_on' => $m2->endOfMonth()->toDateString(), 'counted_total' => 1000])->assertJsonPath('data.status', 'balanced');
        $close($m1)->assertStatus(422);
        $close($m2)->assertOk();
        $this->assertSame('closed', AccountingPeriod::where('territory_id', $this->myChurch->id)->where('month', $m2->month)->value('status'));

        $this->postJson('/api/accounting/receipts', ['date' => $m2->addDays(10)->toDateString(), 'account_id' => $this->cash()->id, 'party_name' => 'X', 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 5]]])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $close(CarbonImmutable::today()->startOfMonth())->assertStatus(422);

        $this->postJson('/api/accounting/periods/reopen', ['year' => $m2->year, 'month' => $m2->month, 'reason' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->regionReader);
        $this->postJson("/api/accounting/periods/reopen?territory_id={$this->myChurch->id}", ['year' => $m2->year, 'month' => $m2->month])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/accounting/periods/reopen?territory_id={$this->myChurch->id}", ['year' => $m2->year, 'month' => $m2->month, 'reason' => 'A receipt was missed'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/accounting/periods/close?territory_id={$this->myChurch->id}", ['year' => $m2->year, 'month' => $m2->month])->assertForbidden();
    }

    public function test_the_board_shows_the_places_below_and_how_far_behind_they_are(): void
    {
        $this->receive(800, null, CarbonImmutable::today()->subMonthsNoOverflow(3)->toDateString());
        Sanctum::actingAs($this->regionReader);
        $rows = collect($this->getJson('/api/accounting/reconciliation-board')->assertOk()->json('data.rows'))->keyBy('place.id');
        $this->assertTrue($rows->has($this->myChurch->id));
        $this->assertTrue($rows->has($this->otherChurch->id));
        $this->assertFalse($rows->has($this->farChurch->id), 'never sideways');
        $mine = $rows[$this->myChurch->id];
        $this->assertSame('late', $mine['state']);
        $this->assertGreaterThanOrEqual(3, $mine['behind']);
        $this->assertEquals(800, $mine['held']);
        $this->assertSame('none', $rows[$this->otherChurch->id]['state']);

        Sanctum::actingAs($this->treasurer);
        $this->getJson('/api/accounting/reconciliation-board')->assertForbidden();
    }
}
