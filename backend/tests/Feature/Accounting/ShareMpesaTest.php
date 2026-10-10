<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendPaybillThanks;
use App\Models\BudgetDeduction;
use App\Models\MpesaPayment;
use App\Models\PaymentVoucher;
use App\Models\Remittance;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Paybill;
use App\Services\Accounting\Remittances;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paying a share by M-Pesa (docs/specs/accounting-spec.md, A6b): only an
 * authorised share voucher to the diocese, whole shillings, through the
 * diocese paybill ({code}DS); Safaricom's answer pays the voucher and the
 * diocese confirms it, once; a payment that can't be matched waits To sort.
 */
class ShareMpesaTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'ShareCallbackKey0123456789abcdefABCDEF';

    private User $dfo;

    private BudgetDeduction $share;

    private int $prompts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'prepare', 'pay', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $this->dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        $tithe = $this->sharedLine('Diocesan Tithe', 'church');
        $tithe->update(['account_id' => $this->acc('5700')->id]);
        $this->share = BudgetDeduction::create(['name' => 'Diocese share', 'slug' => 'diocese-share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'applies_to' => 'income',
            'territory_scope' => 'church', 'territory_type' => 'diocese', 'territory_id' => $this->diocese->id, 'applies_to_level' => 'church',
            'budget_line_id' => $tithe->id, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'is_mandatory' => true, 'is_active' => true]);
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/receipts', ['date' => now()->toDateString(), 'account_id' => $this->cash()->id, 'party_name' => 'Members',
            'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 50000]]])->assertCreated();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/stkpush/v1/processrequest' => fn () => Http::response(['MerchantRequestID' => 'm-'.(++$this->prompts), 'CheckoutRequestID' => 'ws_CO_share'.$this->prompts, 'CustomerMessage' => 'Success']),
        ]);
    }

    /** A share for this month, its voucher authorised by the pastor. */
    private function authorisedShare(float $amount = 5000): Remittance
    {
        Sanctum::actingAs($this->treasurer);
        $id = $this->postJson('/api/accounting/remittances', ['kind' => 'share', 'budget_deduction_id' => $this->share->id, 'pay_from_account_id' => $this->cash()->id,
            'lines' => [['month' => now()->format('Y-m'), 'amount' => $amount]]])->assertCreated()->json('data.id');
        $r = Remittance::findOrFail($id);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$r->payment_voucher_id}/authorise")->assertOk();
        $this->app['auth']->forgetGuards();

        return $r->fresh();
    }

    private function stk(string $checkout, string $receipt, float $amount, int $code = 0): void
    {
        $this->postJson('/api/payments/daraja/'.self::KEY.'/stk', ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm', 'CheckoutRequestID' => $checkout, 'ResultCode' => $code,
            'ResultDesc' => $code ? 'Request cancelled by user' : 'ok', 'CallbackMetadata' => $code ? null : ['Item' => [['Name' => 'Amount', 'Value' => $amount], ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]]]]])->assertOk();
    }

    public function test_a_prompted_share_is_paid_and_confirmed_in_both_books_once(): void
    {
        // Not before its voucher is authorised.
        Sanctum::actingAs($this->treasurer);
        $id = $this->postJson('/api/accounting/remittances', ['kind' => 'share', 'budget_deduction_id' => $this->share->id, 'pay_from_account_id' => $this->cash()->id,
            'lines' => [['month' => now()->format('Y-m'), 'amount' => 5000]]])->assertCreated()->json('data.id');
        $this->assertFalse($this->getJson("/api/accounting/remittances/{$id}")->json('data.can.pay_mpesa'));
        $this->postJson("/api/accounting/remittances/{$id}/mpesa", ['phone' => '0712345678'])->assertUnprocessable()->assertJsonValidationErrors('remittance');
        Sanctum::actingAs($this->authoriser);
        $this->postJson('/api/accounting/payment-vouchers/'.Remittance::find($id)->payment_voucher_id.'/authorise')->assertOk();

        // Someone who can't pay vouchers can't prompt it.
        $this->postJson("/api/accounting/remittances/{$id}/mpesa", ['phone' => '0712345678'])->assertForbidden();

        Sanctum::actingAs($this->treasurer);
        $this->assertTrue($this->getJson("/api/accounting/remittances/{$id}")->json('data.can.pay_mpesa'));
        $req = $this->postJson("/api/accounting/remittances/{$id}/mpesa", ['phone' => '0712 345 678'])->assertCreated()->assertJsonPath('data.account_ref', 'SHR027DS')->json('data.id');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stkpush') && $r['BusinessShortCode'] === '600123' && $r['Amount'] === 5000 && $r['AccountReference'] === 'SHR027DS');

        // Safaricom's answer - twice - pays it once and confirms it.
        $this->stk('ws_CO_share1', 'SHAREMP001', 5000);
        $this->stk('ws_CO_share1', 'SHAREMP001', 5000);
        $rem = Remittance::findOrFail($id);
        $pv = PaymentVoucher::findOrFail($rem->payment_voucher_id);
        $this->assertSame(['confirmed', 'paid', 'SHAREMP001', 'mpesa'], [$rem->status, $pv->status, $pv->reference, $pv->method]);
        $this->assertSame((int) $this->treasurer->id, (int) $pv->paid_by, 'paid by whoever sent the prompt');
        $this->assertSame(1, MpesaPayment::where('trans_id', 'SHAREMP001')->count());
        $this->assertSame('paid', \App\Models\MpesaRequest::find($req)->status);
        $ledger = app(Ledger::class);
        $paybill = app(Paybill::class)->account('600123');
        $this->assertEquals(5000, $ledger->balance($this->myChurch, $this->acc('5700')));
        $this->assertEquals(45000, $ledger->balance($this->myChurch, $this->cash()));
        $this->assertEquals(5000, $ledger->balance($this->diocese, $this->acc('4100')));
        $this->assertEquals(5000, $ledger->balance($this->diocese, $paybill));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);
        $owed = collect(app(Remittances::class)->owing($this->myChurch, (int) now()->year)[0]['months'])->firstWhere('month', now()->format('Y-m'));
        $this->assertEquals([5000, 5000, 0], [$owed['due'], $owed['sent'], $owed['owed']]);
        Bus::assertNotDispatched(SendPaybillThanks::class);

        // The church's paybill page isn't giving; the diocese sees it as a share.
        $this->assertCount(0, $this->getJson('/api/accounting/paybill')->assertOk()->json('data.payments'));
        Sanctum::actingAs($this->dfo);
        $this->assertSame('Diocese share', $this->getJson("/api/accounting/paybill?territory_id={$this->diocese->id}")->json('data.payments.0.purpose_label'));
    }

    public function test_cents_a_cancelled_prompt_and_a_cancelled_voucher(): void
    {
        $cents = $this->authorisedShare(1234.50);
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/remittances/{$cents->id}/mpesa", ['phone' => '0712345678'])->assertUnprocessable();

        $r = $this->authorisedShare(1000);
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/remittances/{$r->id}/mpesa", ['phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson("/api/accounting/remittances/{$r->id}/mpesa", ['phone' => '0712345678'])->assertCreated();
        $this->stk('ws_CO_share1', '', 0, 1032);
        $this->assertSame('waiting', $r->fresh()->status, 'not paid - nothing changes');

        // Prompted again, but the voucher is cancelled before the PIN: the money waits To sort.
        $this->postJson("/api/accounting/remittances/{$r->id}/mpesa", ['phone' => '0712345678'])->assertCreated();
        PaymentVoucher::whereKey($r->payment_voucher_id)->update(['status' => 'cancelled']);
        Remittance::whereKey($r->id)->update(['status' => 'cancelled']);
        $this->stk('ws_CO_share2', 'SHAREMP002', 1000);
        $p = MpesaPayment::where('trans_id', 'SHAREMP002')->firstOrFail();
        $this->assertSame('to_sort', $p->status);
        $this->assertStringContainsString('match it under Remittances', $p->note);
        $this->assertEquals(1000, app(Ledger::class)->balance($this->diocese, $this->chart->account('paybill_to_sort')));
    }

    public function test_a_share_typed_by_hand_is_matched_or_waits(): void
    {
        $r = $this->authorisedShare(3000);
        $c2b = fn (string $id, string $ref, float $amount) => $this->postJson('/api/payments/daraja/'.self::KEY.'/confirmation', ['TransID' => $id, 'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => (string) $amount, 'BusinessShortCode' => '600123', 'BillRefNumber' => $ref, 'MSISDN' => '254712345678', 'FirstName' => 'Church'])->assertOk();
        $c2b('SHARETYPE1', 'shr027 ds', 2999);   // the wrong amount: waits
        $this->assertSame('to_sort', MpesaPayment::where('trans_id', 'SHARETYPE1')->value('status'));
        $this->assertSame('waiting', $r->fresh()->status);
        $c2b('SHARETYPE2', 'SHR027DS', 3000);    // its share: paid and confirmed
        $this->assertSame('posted', MpesaPayment::where('trans_id', 'SHARETYPE2')->value('status'));
        $this->assertSame('confirmed', $r->fresh()->status);
        $this->assertSame('SHARETYPE2', PaymentVoucher::find($r->payment_voucher_id)->reference);
    }
}
