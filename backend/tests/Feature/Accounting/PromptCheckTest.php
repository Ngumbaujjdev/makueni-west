<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\Gift;
use App\Models\Journal;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Services\Accounting\Ledger;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Asking Safaricom how a prompt went (docs/specs/accounting-spec.md, A8):
 * when the callback never comes (a lost callback, or a computer Safaricom
 * can't reach), the prompt is asked about - paid is recorded once and the
 * callback, if it comes later, only brings the real M-Pesa code; refused is
 * kept; still at the phone waits.
 */
class PromptCheckTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'PromptCallbackKey0123456789abcdefABCDE';

    /** What Safaricom's STK query answers. */
    private array $query = [];

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
        $dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '174379', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_check1', 'CustomerMessage' => 'Success']),
            '*/mpesa/stkpushquery/v1/query' => fn () => $this->query === [] ? Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500) : Http::response($this->query),
        ]);
    }

    private function giveByMpesa(): string
    {
        return $this->postJson('/api/give/SHR027', ['purpose' => 'T', 'amount' => 700, 'method' => 'mpesa', 'phone' => '0712345678', 'name' => 'Ruth'])->assertCreated()->json('data.reference');
    }

    private function later(string $ref, int $seconds = 30): void
    {
        Gift::where('reference', $ref)->update(['created_at' => now()->subSeconds($seconds)]);
        MpesaRequest::query()->update(['created_at' => now()->subSeconds($seconds)]);
        Cache::flush();
    }

    private function stkCallback(string $receipt): void
    {
        $this->postJson('/api/payments/daraja/'.self::KEY.'/stk', ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_check1', 'ResultCode' => 0, 'ResultDesc' => 'ok',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 700], ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt], ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]]]]])->assertOk();
    }

    public function test_a_paid_prompt_is_completed_by_asking_and_the_late_callback_only_brings_the_code(): void
    {
        $ref = $this->giveByMpesa();
        // Still at the PIN: Safaricom says it is being processed - it waits.
        $this->later($ref);
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->query = ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.', 'CheckoutRequestID' => 'ws_CO_check1'];
        $this->later($ref);
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'paid');
        $p = MpesaPayment::firstOrFail();
        $this->assertStringStartsWith('Q-', $p->trans_id);
        $ledger = app(Ledger::class);
        $this->assertEquals(700, $ledger->balance($this->myChurch, $this->acc('4000')));

        // The callback arrives later: the real code is swapped in; nothing is posted twice.
        $this->stkCallback('SKQCHECK01');
        $this->assertSame(1, MpesaPayment::count());
        $this->assertSame('SKQCHECK01', $p->fresh()->trans_id);
        $this->assertSame('SKQCHECK01', Journal::find($p->place_journal_id)->reference);
        $this->assertSame('SKQCHECK01', Gift::where('reference', $ref)->value('provider_ref'));
        $this->assertEquals(700, $ledger->balance($this->myChurch, $this->acc('4000')));
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);
    }

    public function test_still_under_processing_keeps_waiting(): void
    {
        $ref = $this->giveByMpesa();
        // Safaricom's 4999 is an answer, not a refusal: the giver is still at the PIN.
        $this->query = ['ResponseCode' => '0', 'ResultCode' => '4999', 'ResultDesc' => 'The transaction is still under processing'];
        $this->later($ref);
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->later($ref, 200);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('pending', Gift::where('reference', $ref)->value('status'));
        $this->assertSame('pending', MpesaRequest::firstOrFail()->status);

        // Then it is paid.
        $this->query = ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.'];
        $this->later($ref);
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_a_refused_callback_gives_the_reason_in_plain_words(): void
    {
        $ref = $this->giveByMpesa();
        $this->postJson('/api/payments/daraja/'.self::KEY.'/stk', ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_check1', 'ResultCode' => 1037, 'ResultDesc' => 'DS timeout user cannot be reached.']]])->assertOk();
        $this->getJson("/api/give/status/{$ref}")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', "Your phone couldn't be reached - is it on?");
    }

    public function test_the_callback_first_then_asking_changes_nothing(): void
    {
        $ref = $this->giveByMpesa();
        $this->stkCallback('SKQCHECK02');
        $this->query = ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'ok'];
        $this->later($ref);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(1, MpesaPayment::count());
        $this->assertSame('SKQCHECK02', MpesaPayment::first()->trans_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'stkpushquery'));
    }

    public function test_a_refused_prompt_is_kept_and_an_old_one_given_up(): void
    {
        $ref = $this->giveByMpesa();
        $this->query = ['ResponseCode' => '0', 'ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user'];
        $this->later($ref, 200);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(['failed', 'You cancelled the prompt.'], [Gift::where('reference', $ref)->value('status'), Gift::where('reference', $ref)->value('result')]);
        $this->assertSame(0, MpesaPayment::count());

        // A prompt nobody answered for an hour is given up.
        $this->query = [];
        $req = MpesaRequest::create(['territory_id' => $this->myChurch->id, 'account_ref' => 'SHR027T', 'amount' => 50, 'phone' => '254712345678', 'shortcode' => '174379', 'checkout_request_id' => 'ws_CO_old', 'status' => 'pending']);
        MpesaRequest::whereKey($req->id)->update(['created_at' => now()->subMinutes(61)]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('failed', $req->fresh()->status);
    }
}
