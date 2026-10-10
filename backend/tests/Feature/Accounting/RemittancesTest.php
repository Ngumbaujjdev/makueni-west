<?php

namespace Tests\Feature\Accounting;

use App\Models\BudgetDeduction;
use App\Models\BudgetLine;
use App\Models\JournalLine;
use App\Models\PaymentVoucher;
use App\Models\Remittance;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Periods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Remittances between levels (docs/specs/accounting-spec.md, A6): the share
 * due is worked out from the church's books, sent by a voucher, and confirmed
 * into the diocese's books - each place writing only in its own.
 */
class RemittancesTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $dfo;

    private User $bishopA;

    private BudgetLine $titheLine;

    private BudgetDeduction $share;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        $this->titheLine = $this->sharedLine('Diocesan Tithe', 'church');
        $this->titheLine->update(['account_id' => $this->acc('5700')->id]);
        $this->share = BudgetDeduction::create([
            'name' => 'Diocese share', 'slug' => 'diocese-share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'applies_to' => 'income',
            'territory_scope' => 'church', 'territory_type' => 'diocese', 'territory_id' => $this->diocese->id, 'applies_to_level' => 'church',
            'budget_line_id' => $this->titheLine->id, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'is_mandatory' => true, 'is_active' => true,
        ]);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'prepare', 'pay', 'journal', 'below']));
        $this->bishopA = $this->userWithRole('bishopa', 'Bishop', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'authorise', 'below']));

        // Tithes in January and February; an offering, which the share isn't charged on.
        Sanctum::actingAs($this->treasurer);
        foreach ([[1, '4000', 50000], [2, '4000', 30000], [2, '4010', 20000]] as [$m, $code, $amount]) {
            $this->postJson('/api/accounting/receipts', ['date' => $this->day($m, 10), 'account_id' => $this->cash()->id, 'party_name' => 'Members', 'lines' => [['account_id' => $this->acc($code)->id, 'amount' => $amount]]])->assertCreated();
        }
    }

    /** The church sends Jan + Feb, the pastor authorises, the treasurer pays. */
    private function sendAndPay(): Remittance
    {
        Sanctum::actingAs($this->treasurer);
        $id = $this->postJson('/api/accounting/remittances', ['kind' => 'share', 'budget_deduction_id' => $this->share->id, 'pay_from_account_id' => $this->cash()->id,
            'lines' => [['month' => $this->month(1), 'amount' => 5000], ['month' => $this->month(2), 'amount' => 3000]]])->assertCreated()->json('data.id');
        $r = Remittance::find($id);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/authorise")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/pay", ['paid_on' => now()->toDateString()])->assertOk();

        return $r->fresh();
    }

    private function month(int $m): string
    {
        return substr($this->day($m, 10), 0, 7);
    }

    public function test_the_share_is_worked_out_sent_confirmed_queried_and_answered(): void
    {
        Sanctum::actingAs($this->treasurer);
        $owing = $this->getJson('/api/accounting/remittances')->assertOk()->json('data.owing.0');
        $this->assertSame('Diocese share', $owing['name']);
        $this->assertSame($this->diocese->id, $owing['to']['id']);
        $this->assertEquals(5000, collect($owing['months'])->firstWhere('month', $this->month(1))['due']);
        $this->assertEquals(3000, collect($owing['months'])->firstWhere('month', $this->month(2))['due'], 'the offering is not charged');
        $this->postJson('/api/accounting/remittances', ['kind' => 'share', 'budget_deduction_id' => $this->share->id, 'pay_from_account_id' => $this->cash()->id, 'lines' => [['month' => now()->addMonth()->format('Y-m'), 'amount' => 10]]])->assertStatus(422);

        $r = $this->sendAndPay();
        $this->assertSame('sent', $r->status);
        $this->assertSame('remittance', PaymentVoucher::find($r->payment_voucher_id)->purpose);
        $ledger = app(Ledger::class);
        $this->assertEquals(8000, $ledger->balance($this->myChurch, $this->acc('5700')));
        $owing = $this->getJson('/api/accounting/remittances')->json('data.owing.0');
        $this->assertEquals(0, $owing['owed']);
        $this->assertEquals(8000, $owing['sent']);

        // The diocese confirms it into its own books, with the church on the line.
        Sanctum::actingAs($this->dfo);
        $in = $this->getJson('/api/accounting/remittances')->assertOk()->json('data.coming_in.0');
        $this->assertTrue($in['can']['confirm']);
        $this->postJson("/api/accounting/remittances/{$r->id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->subDays(2)->toDateString()])->assertStatus(422);
        $this->postJson("/api/accounting/remittances/{$r->id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->toDateString()])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertEquals(8000, $ledger->balance($this->diocese, $this->acc('4100')));
        $this->assertSame($this->myChurch->id, JournalLine::where('territory_id', $this->diocese->id)->where('account_id', $this->acc('4100')->id)->value('for_territory_id'));
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc('4100')), 'nothing written in the church\'s books');

        // The church can't take its payment back once it is confirmed.
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/reverse", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/accounting/remittances/{$r->id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->toDateString()])->assertForbidden();

        // A mistaken confirmation is undone; queried; answered; confirmed again.
        Sanctum::actingAs($this->dfo);
        $this->postJson('/api/accounting/journals/'.$r->fresh()->received_journal_id.'/reverse', ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/accounting/remittances/{$r->id}/unconfirm", ['reason' => 'Wrong account'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->acc('4100')));
        $this->postJson("/api/accounting/remittances/{$r->id}/query", ['reason' => 'Not on our statement'])->assertOk()->assertJsonPath('data.status', 'queried');
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/remittances/{$r->id}/answer", ['answer' => 'Sent by M-Pesa, code QWE123'])->assertOk()->assertJsonPath('data.status', 'sent');
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/accounting/remittances/{$r->id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->toDateString()])->assertOk();
        $this->assertTrue($ledger->trialBalance($this->diocese)['balanced']);
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);
    }

    public function test_the_board_and_statement_and_who_sees_what(): void
    {
        $r = $this->sendAndPay();
        Sanctum::actingAs($this->dfo);
        $rows = collect($this->getJson('/api/accounting/remittances/board')->assertOk()->json('data.rows'))->keyBy('place.id');
        $this->assertEquals(8000, $rows[$this->myChurch->id]['due']);
        $this->assertEquals(8000, $rows[$this->myChurch->id]['in_transit']);
        $this->assertEquals(0, $rows[$this->myChurch->id]['owed']);
        $this->assertTrue($rows->has($this->farChurch->id), 'every church owes the share, even with nothing due yet');
        $this->assertFalse($rows->has($this->region->id), 'the rule is for churches');
        $st = $this->getJson("/api/accounting/remittances/statement?place={$this->myChurch->id}")->assertOk()->json('data');
        $this->assertEquals(5000, collect($st['months'])->firstWhere('month', $this->month(1))['sent']);
        $this->assertCount(1, $st['remittances']);

        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/remittances/{$r->id}")->assertNotFound();
        Sanctum::actingAs($this->treasurer);
        $this->getJson('/api/accounting/remittances/board')->assertForbidden();
        Sanctum::actingAs($this->regionReader);
        $this->getJson('/api/accounting/remittances/board')->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson("/api/accounting/remittances/statement?place={$this->farChurch->id}")->assertNotFound();
    }

    public function test_support_goes_down_and_a_cancelled_voucher_cancels_it(): void
    {
        Sanctum::actingAs($this->dfo);
        $this->postJson('/api/accounting/receipts', ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'Gift', 'lines' => [['account_id' => $this->acc('4010')->id, 'amount' => 50000]]])->assertCreated();
        $id = $this->postJson('/api/accounting/remittances', ['kind' => 'support', 'to_territory_id' => $this->myChurch->id, 'amount' => 15000, 'purpose' => 'Roof repairs after the storm', 'pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data.id');
        $r = Remittance::find($id);
        $this->assertSame('support', $r->kind);
        Sanctum::actingAs($this->bishopA);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/authorise")->assertOk();
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertEquals(15000, app(Ledger::class)->balance($this->diocese, $this->acc('5600')));

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/remittances/{$id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->toDateString()])->assertOk();
        $this->assertEquals(15000, app(Ledger::class)->balance($this->myChurch, $this->acc('4110')));

        // Support only goes to a place below; a cancelled voucher cancels its remittance.
        Sanctum::actingAs($this->regionReader);
        $this->postJson('/api/accounting/remittances', ['kind' => 'support', 'to_territory_id' => $this->farChurch->id, 'amount' => 100, 'purpose' => 'x', 'pay_from_account_id' => $this->cash()->id])->assertStatus(422);
        $id = $this->postJson('/api/accounting/remittances', ['kind' => 'support', 'to_territory_id' => $this->myChurch->id, 'amount' => 100, 'purpose' => 'Bibles', 'pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data.id');
        $this->postJson('/api/accounting/payment-vouchers/'.Remittance::find($id)->payment_voucher_id.'/cancel')->assertOk();
        $this->assertSame('cancelled', Remittance::find($id)->status);
    }

    public function test_month_end_warns_about_money_in_transit_and_the_share_owed(): void
    {
        $periods = app(Periods::class);
        $feb = $periods->checklist($this->myChurch, (int) now()->year, 2)['warnings'];
        $this->assertTrue(collect($feb)->contains(fn ($w) => str_contains($w, 'KES 3,000.00 of the February share is not sent')));
        $this->sendAndPay();
        $now = $periods->checklist($this->myChurch, (int) now()->year, (int) now()->month)['warnings'];
        $this->assertTrue(collect($now)->contains(fn ($w) => str_contains($w, 'not confirmed received')));
        $this->assertTrue(collect($periods->checklist($this->diocese, (int) now()->year, (int) now()->month)['warnings'])->contains(fn ($w) => str_contains($w, 'waiting for you to confirm')));
    }
}
