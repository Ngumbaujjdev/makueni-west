<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\Gift;
use App\Models\MpesaRequest;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transactions (docs/specs/accounting-spec.md, A10e): every attempt to pay -
 * gifts, prompts that aren't gifts, paybill payments typed by hand - with
 * the failed ones and why; each place sees its own, the diocese all; a
 * waiting one can be checked again by whoever writes receipts there.
 */
class TransactionsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'TxCallbackKey0123456789abcdefABCDEFGH';

    private User $dfo;

    private array $query = [];

    private int $prompts = 0;

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
        $this->otherChurch->update(['code' => 'CCI-MWD-SHR-028']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'below', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '174379', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $this->dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/stkpush/v1/processrequest' => fn () => Http::response(['MerchantRequestID' => 'm', 'CheckoutRequestID' => 'ws_CO_tx'.(++$this->prompts), 'CustomerMessage' => 'Success']),
            '*/mpesa/stkpushquery/v1/query' => fn () => $this->query === [] ? Http::response(['errorMessage' => 'The transaction is being processed'], 500) : Http::response($this->query),
        ]);
    }

    private function gift(string $code, int $amount): string
    {
        return $this->postJson("/api/give/{$code}", ['purpose' => 'T', 'amount' => $amount, 'method' => 'mpesa', 'phone' => '0757150682', 'name' => 'Joshua John'])->assertCreated()->json('data.reference');
    }

    private function stk(string $checkout, int $code, string $desc, ?string $receipt = null, int $amount = 0): void
    {
        $this->postJson('/api/payments/daraja/'.self::KEY.'/stk', ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm', 'CheckoutRequestID' => $checkout, 'ResultCode' => $code, 'ResultDesc' => $desc,
            'CallbackMetadata' => $receipt ? ['Item' => [['Name' => 'Amount', 'Value' => $amount], ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt], ['Name' => 'TransactionDate', 'Value' => (int) now('Africa/Nairobi')->format('YmdHis')], ['Name' => 'PhoneNumber', 'Value' => 254757150682]]] : null]]])->assertOk();
    }

    public function test_every_attempt_shows_with_why_it_failed_and_each_place_sees_its_own(): void
    {
        $paid = $this->gift('SHR027', 500);
        $this->stk('ws_CO_tx1', 0, 'ok', 'TXPAID0001', 500);
        $failed = $this->gift('SHR027', 300);
        $this->stk('ws_CO_tx2', 1037, 'DS timeout user cannot be reached.');
        $this->gift('SHR028', 200);   // the other church - still waiting
        // A paybill payment typed by hand to an account nobody has: waits to be sorted, at the diocese.
        $this->postJson('/api/payments/daraja/'.self::KEY.'/confirmation', ['TransID' => 'TXTYPED001', 'TransTime' => now('Africa/Nairobi')->format('YmdHis'), 'TransAmount' => '150',
            'BusinessShortCode' => '174379', 'BillRefNumber' => 'NOPE', 'MSISDN' => '254757150682', 'FirstName' => 'Joshua'])->assertOk();

        Sanctum::actingAs($this->treasurer);
        $d = $this->getJson('/api/accounting/transactions')->assertOk()->json('data');
        $this->assertSame(2, $d['total'], 'only its own two gifts');
        $this->assertSame([500.0, 1, 1, 50], [(float) $d['stats']['paid'], $d['stats']['paid_count'], $d['stats']['failed'], $d['stats']['success_rate']]);
        $row = collect($d['rows'])->firstWhere('reference', $failed);
        $this->assertSame(['failed', "Your phone couldn't be reached - is it on?", 'mpesa'], [$row['status'], $row['reason'], $row['method']]);
        $this->assertSame('TXPAID0001', collect($d['rows'])->firstWhere('reference', $paid)['code']);
        $this->assertSame(1, $this->getJson('/api/accounting/transactions?status=failed')->json('data.total'));
        $this->assertSame(1, $this->getJson('/api/accounting/transactions?q=txpaid')->json('data.total'));

        // The detail: what happened when, Safaricom's answer included.
        $gift = Gift::where('reference', $failed)->firstOrFail();
        $steps = collect($this->getJson("/api/accounting/transactions/gift/{$gift->id}")->assertOk()->json('data.steps'))->pluck('what');
        $this->assertTrue($steps->contains('Started on the giving page') && $steps->contains('M-Pesa prompt sent') && $steps->contains('Not paid'));
        $this->assertTrue($steps->contains(fn ($s) => str_starts_with($s, 'Safaricom answered')));

        // Another church's gift isn't ours to see.
        $other = Gift::where('territory_id', $this->otherChurch->id)->firstOrFail();
        $this->getJson("/api/accounting/transactions/gift/{$other->id}")->assertNotFound();

        // The diocese sees all of it, including the payment waiting to be sorted.
        Sanctum::actingAs($this->dfo);
        $all = $this->getJson("/api/accounting/transactions?territory_id={$this->diocese->id}")->assertOk()->json('data');
        $this->assertSame(4, $all['total']);
        $this->assertSame('to_sort', collect($all['rows'])->firstWhere('reference', 'TXTYPED001')['status']);
        $this->assertSame(1, $this->getJson("/api/accounting/transactions?territory_id={$this->diocese->id}&place_id={$this->otherChurch->id}")->json('data.total'));
    }

    public function test_a_waiting_one_can_be_checked_again_by_whoever_writes_receipts(): void
    {
        $ref = $this->gift('SHR027', 400);
        Gift::where('reference', $ref)->update(['created_at' => now()->subMinutes(5)]);
        MpesaRequest::query()->update(['created_at' => now()->subMinutes(5)]);
        $gift = Gift::where('reference', $ref)->firstOrFail();

        Sanctum::actingAs($this->otherTreasurer);
        $this->postJson("/api/accounting/transactions/gift/{$gift->id}/check")->assertNotFound();

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/transactions/gift/{$gift->id}/check")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->query = ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.'];
        $this->postJson('/api/accounting/transactions/check-waiting')->assertOk()->assertJsonPath('data.paid', 1);
        $this->assertSame('paid', $gift->fresh()->status);
        $this->assertTrue($this->getJson("/api/accounting/transactions/gift/{$gift->id}")->json('data.steps.0.what') === 'Started on the giving page');
        $this->assertGreaterThan(0, PaymentEvent::count() + 1);
    }
}
