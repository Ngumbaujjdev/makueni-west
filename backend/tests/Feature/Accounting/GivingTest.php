<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\BudgetDeduction;
use App\Models\Gift;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Remittances;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Online giving (docs/specs/accounting-spec.md, A10a): the public giving
 * page, a card gift split at source to the church's Paystack subaccount and
 * completed once from a signed webhook, the diocese holding a gift for a
 * church without one, an M-Pesa gift through the paybill, and payouts.
 */
class GivingTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const SECRET = 'sk_test_givingsecret';

    private User $dfo;

    private User $youth;

    private \App\Models\AccountingAccount $churchBank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendGiftReceipt::class, SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->otherChurch->update(['code' => 'CCI-MWD-SHR-028']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'prepare', 'pay', 'paybill', 'gateways']));
        $this->youth = $this->userWithRole('youth', 'Youth Leader', 'church', $this->myChurch->id, $this->perms('church', ['request']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'giving', ['giving.paystack_secret' => self::SECRET, 'giving.paystack_public' => 'pk_test_x'], [], [], $this->dfo);
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => 'GivingCallbackKey0123456789abcdefABCD', 'paybill.callback_base' => 'https://api.example.org'], [], [], $this->dfo);
        // The diocese share: 10% of tithes.
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        $tithe = $this->sharedLine('Diocesan Tithe', 'church');
        $tithe->update(['account_id' => $this->acc('5700')->id]);
        BudgetDeduction::create(['name' => 'Diocese share', 'slug' => 'diocese-share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'applies_to' => 'income',
            'territory_scope' => 'church', 'territory_type' => 'diocese', 'territory_id' => $this->diocese->id, 'applies_to_level' => 'church',
            'budget_line_id' => $tithe->id, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'is_mandatory' => true, 'is_active' => true]);
        $this->churchBank = $this->chart->addPlaceAccount($this->myChurch, 'bank', ['name' => 'Equity - main', 'account_number' => '0123456789']);
    }

    private function fakePaystack(array $verify = []): void
    {
        Http::fake([
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['code' => '68', 'name' => 'Equity Bank', 'active' => true], ['code' => '68', 'name' => 'Equity Bank', 'active' => true]]]),
            'api.paystack.co/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_church27']]),
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc']]),
            'api.paystack.co/transaction/verify/*' => fn ($request) => Http::response(['status' => true, 'data' => $verify + ['reference' => basename($request->url())]]),
            'api.paystack.co/settlement*' => Http::response(['status' => true, 'data' => [['id' => 777, 'status' => 'success', 'effective_amount' => 88500, 'settlement_date' => now()->toDateString()]]]),
        ]);
    }

    private function webhook(array $payload, ?string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/payments/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) $secret)], $body);
    }

    private function setUpSubaccount(): void
    {
        Sanctum::actingAs($this->dfo);
        $id = $this->postJson('/api/accounting/gateways/channels', ['territory_id' => $this->myChurch->id, 'bank_code' => '68', 'account_number' => '0123456789', 'account_name' => 'CCI Church 27', 'settles_into_id' => $this->churchBank->id])
            ->assertCreated()->assertJsonPath('data.status', 'off')->json('data.id');
        $this->putJson("/api/accounting/gateways/channels/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();
    }

    public function test_a_card_gift_is_split_at_source_and_completed_once(): void
    {
        $this->fakePaystack(['status' => 'success', 'amount' => 100000, 'currency' => 'KES', 'fees' => 1500, 'channel' => 'card', 'paid_at' => now()->toIso8601String(),
            'subaccount' => ['subaccount_code' => 'ACCT_church27'], 'fees_split' => ['paystack' => 1500, 'integration' => 10000, 'subaccount' => 88500]]);
        $this->setUpSubaccount();

        $page = $this->getJson('/api/give/SHR027')->assertOk()->json('data');
        $this->assertSame('My Church', $page['place']['name']);
        $this->assertTrue($page['methods']['paystack']);
        $ref = $this->postJson('/api/give/shr-027', ['purpose' => 'T', 'amount' => 1000, 'method' => 'paystack', 'name' => 'Jane Mutua', 'phone' => '0712345678', 'email' => 'jane@example.test'])
            ->assertCreated()->assertJsonPath('data.payment_url', 'https://checkout.paystack.com/abc')->json('data.reference');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transaction/initialize') && $r['amount'] === 100000 && $r['subaccount'] === 'ACCT_church27'
            && $r['transaction_charge'] === 10000 && $r['bearer'] === 'subaccount' && $r['reference'] === $ref);

        // Not signed by Paystack: refused, nothing paid.
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $ref]], 'sk_wrong')->assertStatus(401);
        $this->assertSame('pending', Gift::first()->status);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $ref]])->assertOk();
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $ref]])->assertOk();
        $gift = Gift::firstOrFail();
        $this->assertSame('paid', $gift->status);
        $this->assertEquals([15, 100, 885], [(float) $gift->fee, (float) $gift->split, (float) $gift->net]);
        $ledger = app(Ledger::class);
        $this->assertEquals(885, $ledger->balance($this->myChurch, $this->chart->account('online_clearing')));
        $this->assertEquals(15, $ledger->balance($this->myChurch, $this->acc('5800')));
        $this->assertEquals(100, $ledger->balance($this->myChurch, $this->acc('5700')), 'the diocese share, split off at source');
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->acc('4000')), 'the tithe in full, once');
        $this->assertEquals(100, $ledger->balance($this->diocese, $this->acc('4100')));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);
        $owed = collect(app(Remittances::class)->owing($this->myChurch, (int) now()->year)[0]['months'])->firstWhere('month', now()->format('Y-m'));
        $this->assertEquals([100, 100, 0], [$owed['due'], $owed['sent'], $owed['owed']], 'Remittances shows the share sent');
        Bus::assertDispatchedTimes(SendGiftReceipt::class, 1);
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.place', 'My Church');
        $this->assertStringNotContainsString('885', $this->getJson('/api/give/SHR027')->getContent(), 'the public page shows no figures');
    }

    public function test_each_option_opens_paystack_on_its_own_channel(): void
    {
        $this->fakePaystack();
        $give = fn (array $d) => $this->postJson('/api/give/SHR027', $d + ['purpose' => 'T', 'amount' => 500, 'method' => 'paystack', 'name' => 'Jane Mutua', 'phone' => '0712345678']);
        $sent = fn (string $ref) => collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn ($r) => str_contains($r->url(), 'transaction/initialize') && $r['reference'] === $ref);

        // M-Pesa and Airtel open on mobile money; their receipt comes by SMS, so no email is needed.
        $ref = $give(['pay' => 'mpesa'])->assertCreated()->json('data.reference');
        $this->assertSame(['mobile_money'], $sent($ref)['channels']);
        $this->assertSame(config('mail.from.address'), $sent($ref)['email']);
        $this->assertNull(Gift::where('reference', $ref)->value('giver_email'));
        $ref = $give(['pay' => 'airtel', 'phone' => '0733123456'])->assertCreated()->json('data.reference');
        $this->assertSame(['mobile_money'], $sent($ref)['channels']);
        $give(['pay' => 'airtel', 'phone' => '12345'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'Please enter your Airtel Money number, e.g. 0733 345 678.');
        $give(['pay' => 'mpesa', 'amount' => 300000])->assertStatus(422)->assertJsonPath('errors.amount.0', 'Give between KES 10 and 250,000 by M-Pesa.');

        // Card needs an email (Paystack's receipt) and opens on card only.
        $give(['pay' => 'card'])->assertStatus(422)->assertJsonValidationErrors('email');
        $ref = $give(['pay' => 'card', 'email' => 'jane@example.test'])->assertCreated()->json('data.reference');
        $this->assertSame(['card'], $sent($ref)['channels']);

        // Pesalink has no channel of its own: Paystack's page opens with them all.
        $ref = $give(['pay' => 'pesalink', 'email' => 'jane@example.test'])->assertCreated()->json('data.reference');
        $this->assertArrayNotHasKey('channels', $sent($ref)->data());
        $give(['pay' => 'bitcoin', 'email' => 'jane@example.test'])->assertStatus(422)->assertJsonValidationErrors('pay');
    }

    public function test_how_it_was_paid_is_read_from_paystack(): void
    {
        $r = app(\App\Services\Accounting\GiftReceipt::class);
        $gift = fn (array $raw, ?string $result = null, string $method = 'paystack') => new Gift(['method' => $method, 'result' => $result ?? ($raw['channel'] ?? null), 'raw' => $raw]);
        $this->assertSame('mpesa', $r->paidWith($gift([], null, 'mpesa')));
        $this->assertSame('mpesa', $r->paidWith($gift(['channel' => 'mobile_money', 'authorization' => ['bank' => 'M-PESA', 'brand' => 'M-pesa']])));
        $this->assertSame('airtel', $r->paidWith($gift(['channel' => 'mobile_money', 'authorization' => ['bank' => 'Airtel Money', 'brand' => 'Airtel']])));
        $this->assertSame('card', $r->paidWith($gift(['channel' => 'card', 'authorization' => ['bank' => 'Equity Bank', 'brand' => 'visa']])));
        $this->assertSame('bank', $r->paidWith($gift(['channel' => 'bank_transfer'])));
        $this->assertSame('Airtel Money', $r->paidWithLabel($gift(['channel' => 'mobile_money', 'authorization' => ['bank' => 'Airtel Money']])));
    }

    public function test_without_a_subaccount_the_diocese_holds_it_and_the_sweep_completes_it(): void
    {
        $this->fakePaystack(['status' => 'success', 'amount' => 100000, 'currency' => 'KES', 'fees' => 2000, 'channel' => 'mobile_money', 'paid_at' => now()->toIso8601String()]);
        $ref = $this->postJson('/api/give/SHR028', ['purpose' => 'O', 'amount' => 1000, 'method' => 'paystack', 'name' => 'Peter Musyoka', 'phone' => '0722000111', 'email' => 'peter@example.test'])->assertCreated()->json('data.reference');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transaction/initialize') && ! isset($r['subaccount']) && $r['email'] === 'peter@example.test');
        Gift::where('reference', $ref)->update(['created_at' => now()->subMinutes(11)]);
        $old = Gift::create(['reference' => 'GFT-OLD', 'territory_id' => $this->otherChurch->id, 'purpose' => 'O', 'amount' => 50, 'method' => 'paystack', 'status' => 'pending']);
        Gift::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('paid', Gift::where('reference', $ref)->value('status'));
        $this->assertSame('abandoned', $old->fresh()->status);
        $ledger = app(Ledger::class);
        $this->assertEquals(980, $ledger->balance($this->diocese, $this->chart->account('held_for_others')), 'held for the church, after the fee');
        $this->assertEquals(980, $ledger->balance($this->otherChurch, $this->chart->account('held_by_diocese')));
        $this->assertEquals(20, $ledger->balance($this->otherChurch, $this->acc('5800')));
        $this->assertEquals(1000, $ledger->balance($this->otherChurch, $this->acc('4010')));
    }

    public function test_an_mpesa_gift_goes_through_the_paybill(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-9', 'CheckoutRequestID' => 'ws_CO_9', 'ResponseCode' => '0', 'CustomerMessage' => 'Success']),
        ]);
        $this->postJson('/api/give/SHR027', ['purpose' => 'T', 'amount' => 500, 'method' => 'mpesa', 'phone' => '12345'])->assertStatus(422);
        $ref = $this->postJson('/api/give/SHR027', ['purpose' => 'T', 'amount' => 500, 'method' => 'mpesa', 'phone' => '0712 345 678', 'name' => 'Mary'])->assertCreated()->json('data.reference');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stkpush') && $r['AccountReference'] === 'SHR027T');
        $this->postJson('/api/payments/daraja/GivingCallbackKey0123456789abcdefABCD/stk', ['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm-9', 'CheckoutRequestID' => 'ws_CO_9', 'ResultCode' => 0, 'ResultDesc' => 'ok',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 500], ['Name' => 'MpesaReceiptNumber', 'Value' => 'SJKGIFT001'], ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]],
        ]]])->assertOk();
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertNotNull($this->getJson("/api/give/status/{$ref}")->json('data.receipt'));
        $this->assertEquals(500, app(Ledger::class)->balance($this->myChurch, $this->acc('4000')));
    }

    public function test_payouts_are_recorded_once_and_who_sees_what(): void
    {
        $this->fakePaystack();
        $this->setUpSubaccount();
        $this->artisan('payments:settlements')->assertSuccessful();
        $this->artisan('payments:settlements')->assertSuccessful();
        $ledger = app(Ledger::class);
        $this->assertEquals(885, $ledger->balance($this->myChurch, $this->churchBank), 'the payout, once');
        $this->assertEquals(-885, $ledger->balance($this->myChurch, $this->chart->account('online_clearing')));

        $this->getJson('/api/give/NOPE99')->assertNotFound();
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/gateways')->assertForbidden();
        Sanctum::actingAs($this->treasurer);
        $this->getJson('/api/accounting/gateways')->assertForbidden();
        $this->getJson('/api/accounting/giving')->assertOk()->assertJsonPath('data.code', 'SHR027')->assertJsonPath('data.paystack.channel.status', 'active');
        $this->assertStringEndsWith('/give.php?c=SHR027', $this->getJson('/api/accounting/giving')->json('data.link'));
        Sanctum::actingAs($this->dfo);
        $this->getJson('/api/accounting/gateways')->assertOk()->assertJsonPath('data.ready', true);
    }

    public function test_the_giver_must_say_who_they_are(): void
    {
        $this->fakePaystack();
        $base = ['purpose' => 'T', 'amount' => 500];
        // M-Pesa: a name and the M-Pesa number.
        $this->postJson('/api/give/SHR027', $base + ['method' => 'mpesa', 'phone' => '0712345678'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/give/SHR027', $base + ['method' => 'mpesa', 'name' => 'Ruth Mwende'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        // Card: a name, a phone and an email - Paystack gets the giver's own email, never a made-up one.
        $this->postJson('/api/give/SHR027', $base + ['method' => 'paystack', 'name' => 'Ruth Mwende', 'phone' => '0712345678'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/give/SHR027', $base + ['method' => 'paystack', 'name' => 'Ruth Mwende', 'email' => 'ruth@example.test'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/give/SHR027', $base + ['method' => 'paystack', 'name' => 'Ruth Mwende', 'phone' => '+44 7700 900123', 'email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, Gift::count(), 'nothing is started until the details are right');
        $this->postJson('/api/give/SHR027', $base + ['method' => 'paystack', 'name' => 'Ruth Mwende', 'phone' => '+44 7700 900123', 'email' => 'ruth@example.test'])->assertCreated();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transaction/initialize') && $r['email'] === 'ruth@example.test');
        $this->assertSame(['Ruth Mwende', '+447700900123'], [Gift::first()->giver_name, Gift::first()->giver_phone], 'a phone from abroad is fine for card');
    }
}
