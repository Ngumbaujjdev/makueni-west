<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\MpesaPayment;
use App\Models\PaymentClaim;
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
 * "I paid by Pay Bill - here's my M-Pesa code" (docs/specs/accounting-spec.md,
 * A10f): a code we have is answered at once; otherwise Safaricom is asked and
 * a completed payment into our paybill is recorded once for the place and
 * purpose claimed; refused ones say why; without an API operator the claim
 * waits for the treasurer; Pull records the payments whose callback was lost.
 */
class PaybillClaimsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'ClaimCallbackKey0123456789abcdefABCDEF';

    private User $dfo;

    private array $pull = [];

    private int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendGiftReceipt::class, SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'below', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '174379', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $this->dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/transactionstatus/v1/query' => fn () => Http::response(['OriginatorConversationID' => 'orig-'.(++$this->asked), 'ConversationID' => 'conv-'.$this->asked, 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']),
            '*/pulltransactions/v1/query' => fn () => Http::response(['ResponseRefID' => 'x', 'ResponseCode' => '1000', 'Response' => [$this->pull]]),
        ]);
    }

    private function operator(): void
    {
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.initiator_name' => 'testapi', 'paybill.security_credential' => 'base64-credential'], [], [], $this->dfo);
    }

    private function claim(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/give/SHR027/claim', ['code' => $code, 'purpose' => 'T', 'name' => 'Joshua John', 'phone' => '0757150682']);
    }

    private function safaricomAnswers(array $over = [], array $params = []): void
    {
        $p = $params + ['ReceiptNo' => 'SJK1ABC234', 'Amount' => 1500, 'DebitPartyName' => '254757150682 - JOSHUA JOHN', 'CreditPartyName' => '174379 - Makueni West Diocese',
            'TransactionStatus' => 'Completed', 'FinalisedTime' => now('Africa/Nairobi')->format('YmdHis')];
        $this->postJson('/api/payments/daraja/'.self::KEY.'/status-result', ['Result' => $over + ['ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => 'orig-'.$this->asked, 'ConversationID' => 'conv-'.$this->asked,
            'ResultParameters' => ['ResultParameter' => collect($p)->map(fn ($v, $k) => ['Key' => $k, 'Value' => $v])->values()->all()]]])->assertOk();
    }

    public function test_a_claimed_code_is_checked_with_safaricom_and_recorded_once(): void
    {
        $this->operator();
        $this->postJson('/api/give/SHR027/claim', ['code' => 'nope', 'purpose' => 'T', 'name' => 'Joshua John', 'phone' => '0757150682'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/give/SHR027/claim', ['code' => 'SJK1ABC234', 'purpose' => 'T'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $id = $this->claim('sjk1 abc234')->assertCreated()->assertJsonPath('data.status', 'checking')->json('data.id');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transactionstatus') && $r['TransactionID'] === 'SJK1ABC234' && $r['Initiator'] === 'testapi' && $r['PartyA'] === '174379'
            && $r['SecurityCredential'] === 'base64-credential' && str_ends_with($r['ResultURL'], '/status-result'));
        // The same code again doesn't ask Safaricom twice.
        $this->assertSame($id, $this->claim('SJK1ABC234')->json('data.id'));

        $this->safaricomAnswers();
        $this->safaricomAnswers();
        $claim = PaymentClaim::findOrFail($id);
        $this->assertSame(['confirmed', 1500.0], [$claim->status, (float) $claim->amount]);
        $p = MpesaPayment::where('trans_id', 'SJK1ABC234')->firstOrFail();
        $this->assertSame([1, 'posted', 'SHR027T', 'JOSHUA JOHN', 'T'], [MpesaPayment::count(), $p->status, $p->bill_ref, $p->payer_name, $p->purpose]);
        $this->assertEquals(1500, app(Ledger::class)->balance($this->myChurch, $this->acc('4000')), 'in the church\'s books like any paybill payment');
        $this->getJson("/api/give/claim/{$id}?code=SJK1ABC234")->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.amount', 1500);
        $this->getJson("/api/give/claim/{$id}?code=OTHER12345")->assertNotFound();

        // A code we already have is answered at once - no question to Safaricom.
        Http::fake(['*' => Http::response([], 500)]);
        $this->claim('SJK1ABC234')->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.amount', 1500);
    }

    public function test_safaricom_says_no_and_why(): void
    {
        $this->operator();
        $id = $this->claim('SJK1NOT001')->json('data.id');
        $this->safaricomAnswers(['ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.']);
        $this->assertSame(['failed', 'The initiator information is invalid.'], [PaymentClaim::find($id)->status, PaymentClaim::find($id)->result]);

        PaymentClaim::query()->delete();
        $id = $this->claim('SJK1ABC234')->json('data.id');
        $this->safaricomAnswers([], ['CreditPartyName' => '999999 - Someone Else']);
        $this->assertSame(['failed', 'That payment wasn\'t made to our paybill.'], [PaymentClaim::find($id)->status, PaymentClaim::find($id)->result]);
        $this->assertSame(0, MpesaPayment::count());
    }

    public function test_without_an_operator_it_waits_for_the_treasurer_who_sees_and_checks_it(): void
    {
        $id = $this->claim('SJK1WAIT01')->assertCreated()->assertJsonPath('data.status', 'waiting')->json('data.id');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'transactionstatus'));

        Sanctum::actingAs($this->treasurer);
        $row = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('data.rows'))->firstWhere('key', "claim-{$id}");
        $this->assertSame(['waiting', 'SJK1WAIT01'], [$row['status'], $row['code']]);
        // Once the operator is set, the treasurer asks Safaricom from Transactions.
        $this->operator();
        $this->postJson("/api/accounting/transactions/claim/{$id}/check")->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transactionstatus') && $r['TransactionID'] === 'SJK1WAIT01');
        $this->postJson('/api/accounting/transactions/check-code', ['code' => 'SJK1OWN001', 'purpose' => 'O'])->assertCreated()->assertJsonPath('data.status', 'checking');
        Sanctum::actingAs($this->otherTreasurer);
        $this->postJson("/api/accounting/transactions/claim/{$id}/check")->assertNotFound();
    }

    public function test_pull_records_only_the_payments_we_dont_have(): void
    {
        $this->artisan('payments:pull')->expectsOutputToContain('Pull is off')->assertSuccessful();
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.environment' => 'production', 'paybill.pull_number' => '0757150682'], [], [], $this->dfo);
        MpesaPayment::create(['trans_id' => 'SJKHAVE001', 'kind' => 'c2b', 'shortcode' => '174379', 'amount' => 100, 'paid_at' => now(), 'status' => 'to_sort']);
        $this->pull = [
            ['transactionId' => 'SJKHAVE001', 'trxDate' => now()->toDateTimeString(), 'msisdn' => 254757150682, 'sender' => 'JOSHUA', 'transactiontype' => 'c2b-pay-bill-debit', 'billreference' => 'SHR027T', 'amount' => '100'],
            ['transactionId' => 'SJKLOST001', 'trxDate' => now()->toDateTimeString(), 'msisdn' => 254757150682, 'sender' => 'JOSHUA', 'transactiontype' => 'c2b-pay-bill-debit', 'billreference' => 'SHR027OFF', 'amount' => '250'],
        ];
        $this->artisan('payments:pull')->expectsOutputToContain('recorded 1 new')->assertSuccessful();
        $lost = MpesaPayment::where('trans_id', 'SJKLOST001')->firstOrFail();
        $this->assertSame(['posted', 'O', 2], [$lost->status, $lost->purpose, MpesaPayment::count()]);
    }
}
