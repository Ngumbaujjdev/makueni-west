<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\AccountingAccount;
use App\Models\Gift;
use App\Models\MpesaPayment;
use App\Models\PaymentChannel;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A church's own paybill (docs/specs/accounting-spec.md, A10b): set up on
 * Gateways through its own Daraja app or PayHero, keys never shown again;
 * payments into it post straight into the church's books, once per M-Pesa
 * code; a PayHero payment counts only when PayHero itself says it is paid.
 */
class OwnPaybillTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $dfo;

    private User $youth;

    /** What PayHero's status lookup answers. */
    private array $payheroStatus = ['status' => 'QUEUED'];

    private int $prompts = 0;

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
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => 'DioceseCallbackKey0123456789abcdefABCD', 'paybill.callback_base' => 'https://api.example.org'], [], [], $this->dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl' => Http::response(['ResponseDescription' => 'Success']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_own1', 'CustomerMessage' => 'Success']),
            'backend.payhero.co.ke/api/v2/payments' => fn () => Http::response(['success' => true, 'status' => 'QUEUED', 'reference' => 'PHREF'.(++$this->prompts), 'CheckoutRequestID' => 'ws_CO_ph'.$this->prompts], 201),
            'backend.payhero.co.ke/api/v2/transaction-status*' => fn ($r) => Http::response($this->payheroStatus + ['reference' => $r['reference']]),
        ]);
    }

    private function channel(string $provider, array $fields): PaymentChannel
    {
        Sanctum::actingAs($this->dfo);
        $id = $this->postJson('/api/accounting/gateways/channels', ['provider' => $provider, 'territory_id' => $this->myChurch->id, 'number' => '4123456'] + $fields)
            ->assertCreated()->assertJsonPath('data.status', 'off')->assertJsonPath('data.ready', true)->json('data.id');
        $this->putJson("/api/accounting/gateways/channels/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();

        return PaymentChannel::findOrFail($id);
    }

    private function mpesaAccount(): AccountingAccount
    {
        return AccountingAccount::where('territory_id', $this->myChurch->id)->where('cash_kind', 'mpesa')->where('mpesa_number', '4123456')->firstOrFail();
    }

    private function c2b(string $key, string $transId, string $shortcode, string $billRef, float $amount): void
    {
        $this->postJson("/api/payments/daraja/{$key}/confirmation", ['TransactionType' => 'Pay Bill', 'TransID' => $transId, 'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => (string) $amount, 'BusinessShortCode' => $shortcode, 'BillRefNumber' => $billRef, 'MSISDN' => '254712345678', 'FirstName' => 'Mary', 'LastName' => 'Wanza'])
            ->assertOk()->assertJsonPath('ResultCode', 0);
    }

    public function test_own_daraja_payments_post_straight_into_the_church_once(): void
    {
        $ch = $this->channel('daraja', ['environment' => 'sandbox', 'consumer_key' => 'church-ck', 'consumer_secret' => 'church-secret-xyz', 'passkey' => 'church-passkey']);
        $this->assertSame('church-secret-xyz', $ch->secrets()['consumer_secret'], 'kept, encrypted');
        $this->assertStringNotContainsString('church-secret-xyz', (string) $ch->getRawOriginal('credentials'));
        $this->assertSame('1150', substr($this->mpesaAccount()->code, 0, 4), 'its M-Pesa account was made');

        Sanctum::actingAs($this->dfo);
        $gw = $this->getJson('/api/accounting/gateways')->assertOk();
        $this->assertStringNotContainsString('church-secret-xyz', $gw->getContent(), 'keys never come back out');
        $this->assertStringNotContainsString((string) $ch->callback_key, $gw->getContent());
        $this->postJson("/api/accounting/gateways/channels/{$ch->id}/register")->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'registerurl') && $r['ShortCode'] === '4123456'
            && $r['ConfirmationURL'] === "https://api.example.org/api/payments/daraja/{$ch->callback_key}/confirmation");
        $this->app['auth']->forgetGuards();

        // Paid into the church's own paybill: straight into its books, nothing held by the diocese.
        $this->c2b($ch->callback_key, 'OWN0000001', '4123456', 'tithe', 500);
        $this->c2b($ch->callback_key, 'OWN0000001', '4123456', 'tithe', 500);
        $this->c2b($ch->callback_key, 'OWN0000002', '4123456', 'SHR027OFF', 200);
        $this->c2b($ch->callback_key, 'OWN0000003', '600123', 'T', 999);   // not its paybill
        $this->assertSame(2, MpesaPayment::where('channel_id', $ch->id)->count());
        $p = MpesaPayment::where('trans_id', 'OWN0000001')->firstOrFail();
        $this->assertSame(['posted', 'T', $this->myChurch->id], [$p->status, $p->purpose, (int) $p->territory_id]);
        $this->assertNull($p->diocese_journal_id);
        $ledger = app(Ledger::class);
        $this->assertEquals(700, $ledger->balance($this->myChurch, $this->mpesaAccount()));
        $this->assertEquals(500, $ledger->balance($this->myChurch, $this->acc('4000')));
        $this->assertEquals(200, $ledger->balance($this->myChurch, $this->acc('4010')));
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->chart->account('held_for_others')));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced']);

        // The diocese's paybill feed leaves it out; the church sees it.
        Sanctum::actingAs($this->dfo);
        $this->assertCount(0, $this->getJson("/api/accounting/paybill?territory_id={$this->diocese->id}")->assertOk()->json('data.payments'));
        $this->app['auth']->forgetGuards();

        // The giving page sends the prompt through the church's own app, and only its key answers it.
        $page = $this->getJson('/api/give/SHR027')->assertOk()->json('data');
        $this->assertSame('4123456', $page['paybill']['number']);
        $ref = $this->postJson('/api/give/SHR027', ['purpose' => 'B', 'amount' => 300, 'method' => 'mpesa', 'phone' => '0712345678'])->assertCreated()->json('data.reference');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stkpush') && $r['BusinessShortCode'] === '4123456' && str_contains($r['CallBackURL'], "/daraja/{$ch->callback_key}/stk"));
        $callback = ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_own1', 'ResultCode' => 0, 'ResultDesc' => 'ok', 'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => 300], ['Name' => 'MpesaReceiptNumber', 'Value' => 'OWNSTK0001'], ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254712345678],
        ]]]]];
        $this->postJson('/api/payments/daraja/DioceseCallbackKey0123456789abcdefABCD/stk', $callback)->assertOk();
        $this->assertSame('pending', Gift::where('reference', $ref)->value('status'), 'the diocese key can\'t answer a church\'s prompt');
        $this->postJson("/api/payments/daraja/{$ch->callback_key}/stk", $callback)->assertOk();
        $gift = Gift::where('reference', $ref)->firstOrFail();
        $this->assertSame(['paid', 'own_daraja'], [$gift->status, $gift->channel]);
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->mpesaAccount()));
    }

    public function test_a_payhero_gift_counts_only_when_payhero_says_it_is_paid(): void
    {
        $ch = $this->channel('payhero', ['username' => 'ph-user', 'password' => 'ph-pass-secret', 'channel_id' => 911, 'till' => true]);
        $page = $this->getJson('/api/give/SHR027')->assertOk()->json('data');
        $this->assertTrue($page['paybill']['till']);
        $ref = $this->postJson('/api/give/SHR027', ['purpose' => 'T', 'amount' => 1000, 'method' => 'mpesa', 'phone' => '0712345678', 'name' => 'Jane'])->assertCreated()->json('data.reference');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api/v2/payments') && $r['channel_id'] === 911 && $r['amount'] === 1000 && $r['provider'] === 'm-pesa'
            && $r['phone_number'] === '254712345678' && $r['callback_url'] === "https://api.example.org/api/payments/payhero/{$ch->callback_key}" && $r->hasHeader('Authorization'));

        // A forged "success" while PayHero still has it queued: nothing posts.
        $forged = ['status' => true, 'response' => ['CheckoutRequestID' => 'ws_CO_ph1', 'ExternalReference' => 'x', 'MpesaReceiptNumber' => 'FAKE000001', 'ResultCode' => 0, 'Status' => 'Success', 'Amount' => 1000]];
        $this->postJson('/api/payments/payhero/wrong-key-wrong-key-wrong-key', $forged)->assertNotFound();
        $this->postJson("/api/payments/payhero/{$ch->callback_key}", $forged)->assertOk();
        $this->assertSame('pending', Gift::where('reference', $ref)->value('status'));
        $this->assertSame(0, MpesaPayment::count());

        // PayHero has it paid: posted once, under PayHero's M-Pesa code.
        $this->payheroStatus = ['success' => true, 'status' => 'SUCCESS', 'provider_reference' => 'PHMPESA001', 'amount' => 1000];
        $this->postJson("/api/payments/payhero/{$ch->callback_key}", $forged)->assertOk();
        $this->postJson("/api/payments/payhero/{$ch->callback_key}", $forged)->assertOk();
        $gift = Gift::where('reference', $ref)->firstOrFail();
        $this->assertSame(['paid', 'payhero', 'PHMPESA001'], [$gift->status, $gift->channel, $gift->provider_ref]);
        $this->assertSame(1, MpesaPayment::where('trans_id', 'PHMPESA001')->where('channel_id', $ch->id)->count());
        $ledger = app(Ledger::class);
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->mpesaAccount()));
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->acc('4000')));
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->chart->account('held_for_others')));

        // A prompt whose callback never came: the sweep asks PayHero.
        $this->payheroStatus = ['status' => 'FAILED', 'ResultDesc' => 'Request cancelled by user'];
        $ref2 = $this->postJson('/api/give/SHR027', ['purpose' => 'O', 'amount' => 50, 'method' => 'mpesa', 'phone' => '0712345678'])->assertCreated()->json('data.reference');
        Gift::where('reference', $ref2)->update(['created_at' => now()->subMinutes(11)]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(['failed', 'Request cancelled by user'], [Gift::where('reference', $ref2)->value('status'), Gift::where('reference', $ref2)->value('result')]);
    }

    public function test_only_the_diocese_finance_officer_sets_up_a_churchs_paybill(): void
    {
        Sanctum::actingAs($this->youth);
        $this->postJson('/api/accounting/gateways/channels', ['provider' => 'payhero', 'territory_id' => $this->myChurch->id, 'number' => '4123456', 'username' => 'u', 'password' => 'p', 'channel_id' => 1])->assertForbidden();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->dfo);
        $this->postJson('/api/accounting/gateways/channels', ['provider' => 'payhero', 'territory_id' => $this->diocese->id, 'number' => '4123456', 'username' => 'u', 'password' => 'p', 'channel_id' => 1])->assertNotFound();
        $this->postJson('/api/accounting/gateways/channels', ['provider' => 'daraja', 'territory_id' => $this->myChurch->id, 'number' => '41-23'])->assertUnprocessable()
            ->assertJsonValidationErrors(['number', 'consumer_key', 'consumer_secret', 'passkey', 'environment']);
        // Changing it keeps the keys left blank.
        $id = $this->postJson('/api/accounting/gateways/channels', ['provider' => 'payhero', 'territory_id' => $this->myChurch->id, 'number' => '4123456', 'username' => 'u', 'password' => 'p', 'channel_id' => 5])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/accounting/gateways/channels/{$id}", ['password' => 'new-pass', 'username' => ''])->assertOk()->assertJsonPath('data.keys.password', true);
        $this->assertSame(['u', 'new-pass', '5'], [PaymentChannel::find($id)->secrets()['username'], PaymentChannel::find($id)->secrets()['password'], (string) PaymentChannel::find($id)->secrets()['channel_id']]);
    }
}
