<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendPaybillThanks;
use App\Models\BudgetDeduction;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Models\PaymentEvent;
use App\Models\PaymentVoucher;
use App\Models\Remittance;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Periods;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The diocese M-Pesa paybill (docs/specs/accounting-spec.md, A8): a payment
 * by church code lands in both sets of books once; one that matches no place
 * waits to be sorted; "Ask to pay" and its answer; and the monthly settlement
 * that nets the diocese share before paying the church.
 */
class PaybillTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'TestCallbackKey0123456789abcdefABCDEF01';

    private User $dfo;

    private User $bishopA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->otherChurch->update(['code' => 'CCI-MWD-SHR-028']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'prepare', 'pay', 'journal', 'below', 'paybill']));
        $this->bishopA = $this->userWithRole('bishopa', 'Bishop', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'authorise', 'below']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', [
            'paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck_test', 'paybill.consumer_secret' => 'cs_test', 'paybill.passkey' => 'pk_test',
            'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org',
        ], [], [], $this->dfo);
    }

    private function pay(string $ref, float $amount, string $code, ?string $when = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments/daraja/'.self::KEY.'/confirmation', [
            'TransactionType' => 'Pay Bill', 'TransID' => $code, 'TransTime' => $when ?? now('Africa/Nairobi')->format('YmdHis'), 'TransAmount' => number_format($amount, 2, '.', ''),
            'BusinessShortCode' => '600123', 'BillRefNumber' => $ref, 'MSISDN' => '254712345678', 'FirstName' => 'Jane', 'MiddleName' => '', 'LastName' => 'Mutua',
        ]);
    }

    public function test_a_payment_by_church_code_lands_in_both_books_once(): void
    {
        $this->pay('shr 27 t', 1500, 'SJK1ABC234')->assertOk()->assertJsonPath('ResultCode', 0);
        $p = MpesaPayment::firstOrFail();
        $this->assertSame(['posted', $this->myChurch->id, 'T'], [$p->status, $p->territory_id, $p->purpose]);
        $ledger = app(Ledger::class);
        $paybill = \App\Models\AccountingAccount::where('territory_id', $this->diocese->id)->where('mpesa_number', '600123')->firstOrFail();
        $this->assertEquals(1500, $ledger->balance($this->diocese, $paybill));
        $this->assertEquals(1500, $ledger->balance($this->diocese, $this->chart->account('held_for_others')), 'held for the church');
        $this->assertEquals(1500, $ledger->balance($this->myChurch, $this->chart->account('held_by_diocese')));
        $this->assertEquals(1500, $ledger->balance($this->myChurch, $this->acc('4000')), 'the church sees its tithe the same day');
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);
        Bus::assertDispatched(SendPaybillThanks::class);

        // Safaricom sends it again: one payment still.
        $this->pay('shr 27 t', 1500, 'SJK1ABC234')->assertOk();
        $this->assertSame(1, MpesaPayment::count());
        $this->assertEquals(1500, $ledger->balance($this->myChurch, $this->acc('4000')));

        // A wrong key is a 404 and nothing is recorded - but the call is logged.
        $this->postJson('/api/payments/daraja/WRONGKEY/confirmation', ['TransID' => 'X1', 'TransAmount' => '5', 'BusinessShortCode' => '600123', 'BillRefNumber' => 'SHR027'])->assertNotFound();
        $this->assertSame(1, MpesaPayment::count());
        $this->assertSame(1, PaymentEvent::where('status', 'ignored')->count());

        // The church's journal can't be reversed from All documents.
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/journals/'.$p->place_journal_id.'/reverse', ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/accounting/paybill')->assertOk()->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.account_numbers.1.account', 'SHR027T')->assertJsonPath('data.held', 1500);
    }

    public function test_an_unknown_account_waits_and_is_sorted(): void
    {
        $this->pay('XYZ999', 1000, 'SJK2UNKNOWN')->assertOk();
        $p = MpesaPayment::firstOrFail();
        $this->assertSame('to_sort', $p->status);
        $ledger = app(Ledger::class);
        $this->assertEquals(1000, $ledger->balance($this->diocese, $this->chart->account('paybill_to_sort')));

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/paybill/payments/{$p->id}/sort", ['to' => 'place', 'territory_id' => $this->myChurch->id, 'purpose' => 'B'])->assertForbidden();
        Sanctum::actingAs($this->dfo);
        $this->getJson('/api/accounting/paybill')->assertOk()->assertJsonPath('data.to_sort', 1);
        $this->postJson("/api/accounting/paybill/payments/{$p->id}/sort", ['to' => 'place', 'territory_id' => $this->myChurch->id, 'purpose' => 'B'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson("/api/accounting/paybill/payments/{$p->id}/sort", ['to' => 'place', 'territory_id' => $this->myChurch->id, 'purpose' => 'B'])->assertStatus(422);
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->chart->account('paybill_to_sort')));
        $this->assertEquals(1000, $ledger->balance($this->diocese, $this->chart->account('held_for_others')));
        $this->assertSame(\App\Models\AccountingFund::where('code', 'BLD')->value('id'), \App\Models\JournalLine::where('journal_id', $p->fresh()->place_journal_id)->where('credit', '>', 0)->value('fund_id'), 'into the Building fund');

        // The diocese's own code is diocese income.
        $this->pay('MWD OFF', 300, 'SJK3DIOCESE')->assertOk();
        $this->assertEquals(300, $ledger->balance($this->diocese, $this->acc('4010')));
    }

    public function test_ask_to_pay_and_its_answer(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0', 'CustomerMessage' => 'Success. Request accepted for processing']),
        ]);
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/paybill/ask', ['phone' => '0712 345 678', 'amount' => 200, 'purpose' => 'X'])->assertStatus(422);
        $id = $this->postJson('/api/accounting/paybill/ask', ['phone' => '0712 345 678', 'amount' => 200, 'purpose' => 'T'])->assertCreated()->json('data.id');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stkpush') && $r['AccountReference'] === 'SHR027T' && $r['PhoneNumber'] === '254712345678'
            && $r['CallBackURL'] === 'https://api.example.org/api/payments/daraja/'.self::KEY.'/stk');
        $this->postJson('/api/accounting/paybill/ask', ['phone' => '0712345678', 'amount' => 200, 'purpose' => 'T', 'for_id' => $this->otherChurch->id])->assertForbidden();

        $answer = fn (int $code, string $receipt) => $this->postJson('/api/payments/daraja/'.self::KEY.'/stk', ['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => $code, 'ResultDesc' => $code ? 'Request cancelled by user' : 'The service request is processed successfully.',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 200], ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt], ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]],
        ]]]);
        $answer(0, 'SJK9STKPAID')->assertOk()->assertJsonPath('ResultCode', 0);
        $this->assertSame('paid', MpesaRequest::find($id)->status);
        $this->getJson("/api/accounting/paybill/requests/{$id}")->assertOk()->assertJsonPath('data.status', 'paid');
        // Safaricom also sends the C2B confirmation for the same payment: still one.
        $this->pay('SHR027T', 200, 'SJK9STKPAID')->assertOk();
        $this->assertSame(1, MpesaPayment::count());
        $this->assertEquals(200, app(Ledger::class)->balance($this->myChurch, $this->acc('4000')));
    }

    public function test_the_month_is_settled_netting_the_share_and_the_church_confirms(): void
    {
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        $tithe = $this->sharedLine('Diocesan Tithe', 'church');
        $tithe->update(['account_id' => $this->acc('5700')->id]);
        BudgetDeduction::create(['name' => 'Diocese share', 'slug' => 'diocese-share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'applies_to' => 'income',
            'territory_scope' => 'church', 'territory_type' => 'diocese', 'territory_id' => $this->diocese->id, 'applies_to_level' => 'church',
            'budget_line_id' => $tithe->id, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'is_mandatory' => true, 'is_active' => true]);
        $last = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        $month = $last->format('Y-m');
        $this->pay('SHR027T', 10000, 'SJKLASTT01', $last->format('YmdHis'))->assertOk();
        $this->pay('SHR027', 2000, 'SJKLASTO01', $last->format('YmdHis'))->assertOk();
        $ledger = app(Ledger::class);

        Sanctum::actingAs($this->dfo);
        $row = collect($this->getJson("/api/accounting/paybill/settlements?month={$month}")->assertOk()->json('data.preview'))->firstWhere('place.id', $this->myChurch->id);
        $this->assertEquals([12000, 1000, 11000], [$row['held'], $row['share'], $row['net']]);
        $settle = fn () => $this->postJson('/api/accounting/paybill/settlements', ['month' => $month, 'places' => [$this->myChurch->id], 'pay_from_account_id' => $this->cash()->id]);

        // Settled, then cancelled: the netting is undone.
        $settle()->assertCreated();
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->acc('5700')));
        $settle()->assertStatus(422);
        $this->postJson('/api/accounting/paybill/settlements/'.\App\Models\PaybillSettlement::firstOrFail()->id.'/cancel')->assertOk();
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc('5700')));
        $this->assertEquals(12000, $ledger->balance($this->diocese, $this->chart->account('held_for_others')));

        // Settled again, approved and paid; the church confirms it reached its account.
        $settle()->assertCreated();
        $s = \App\Models\PaybillSettlement::where('status', '!=', 'cancelled')->firstOrFail();
        $this->assertEquals(1000, $ledger->balance($this->diocese, $this->acc('4100')), 'the share is the diocese\'s income');
        $owed = collect(app(\App\Services\Accounting\Remittances::class)->owing($this->myChurch, (int) $last->year)[0]['months'])->firstWhere('month', $month);
        $this->assertEquals([1000, 1000, 0], [$owed['due'], $owed['sent'], $owed['owed']], 'Remittances shows the share sent');
        $pv = PaymentVoucher::find($s->remittance->payment_voucher_id);
        Sanctum::actingAs($this->bishopA);
        $this->postJson("/api/accounting/payment-vouchers/{$pv->id}/authorise")->assertOk();
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/accounting/payment-vouchers/{$pv->id}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->assertSame('paid', $s->fresh()->status);
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->chart->account('held_for_others')));

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/remittances/{$s->remittance_id}/confirm", ['into_account_id' => $this->cash()->id, 'received_on' => now()->toDateString()])->assertOk();
        $this->assertSame('confirmed', Remittance::find($s->remittance_id)->status);
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->chart->account('held_by_diocese')), 'nothing left held by the diocese');
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);
    }

    public function test_month_end_warns_about_payments_to_sort(): void
    {
        $this->pay('NOPE', 50, 'SJKSORTME1')->assertOk();
        $warnings = app(Periods::class)->checklist($this->diocese, (int) now()->year, (int) now()->month)['warnings'];
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'still to sort')));
    }
}
