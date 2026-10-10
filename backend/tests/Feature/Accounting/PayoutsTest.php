<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\BudgetDeduction;
use App\Models\Gift;
use App\Models\PaymentChannel;
use App\Models\PaystackSettlement;
use App\Models\Remittance;
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
 * Getting paid (docs/specs/accounting-spec.md, A10c): a church asks for its
 * own Paystack and the diocese checks it; each payout is recorded once and
 * matched to the gifts it paid by day; a refund undoes the gift in both
 * books, a part refund is flagged, a dispute is marked until it ends.
 */
class PayoutsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const SECRET = 'sk_test_payoutsecret';

    private User $dfo;

    private User $youth;

    private \App\Models\AccountingAccount $churchBank;

    /** What Paystack's settlement list answers, by subaccount code ('none' = the main account). */
    private array $settlements = [];

    private string $paidAt;

    protected function setUp(): void
    {
        parent::setUp();
        // The giving page allows 10 gifts a minute; these tests make more.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendGiftReceipt::class, SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->otherChurch->update(['code' => 'CCI-MWD-SHR-028']);
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'receipt', 'prepare', 'pay', 'accounts', 'paybill', 'gateways']));
        $this->youth = $this->userWithRole('youth', 'Youth Leader', 'church', $this->myChurch->id, $this->perms('church', ['request']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'giving', ['giving.paystack_secret' => self::SECRET, 'giving.paystack_public' => 'pk_test_x'], [], [], $this->dfo);
        $this->incomeLine->update(['account_id' => $this->acc('4000')->id]);
        $tithe = $this->sharedLine('Diocesan Tithe', 'church');
        $tithe->update(['account_id' => $this->acc('5700')->id]);
        BudgetDeduction::create(['name' => 'Diocese share', 'slug' => 'diocese-share', 'deduction_type' => 'percentage', 'deduction_value' => 10, 'applies_to' => 'income',
            'territory_scope' => 'church', 'territory_type' => 'diocese', 'territory_id' => $this->diocese->id, 'applies_to_level' => 'church',
            'budget_line_id' => $tithe->id, 'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'is_mandatory' => true, 'is_active' => true]);
        $this->churchBank = $this->chart->addPlaceAccount($this->myChurch, 'bank', ['name' => 'Equity - main', 'account_number' => '0123456789']);
        $this->paidAt = now('Africa/Lagos')->subDays(2)->setTime(10, 0)->toIso8601String();
        Http::fake([
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['code' => '68', 'name' => 'Equity Bank', 'active' => true], ['code' => '01', 'name' => 'KCB', 'active' => true]]]),
            'api.paystack.co/subaccount/*' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_church27']]),
            'api.paystack.co/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_church27']]),
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
            'api.paystack.co/transaction/verify/*' => fn ($r) => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 100000, 'currency' => 'KES', 'fees' => 1500,
                'channel' => 'card', 'paid_at' => $this->paidAt, 'subaccount' => ['subaccount_code' => 'ACCT_church27'],
                'fees_split' => ['paystack' => 1500, 'integration' => 10000, 'subaccount' => 88500], 'reference' => basename($r->url())]]),
            'api.paystack.co/settlement*' => fn ($r) => Http::response(['status' => true, 'data' => $this->settlements[$r['subaccount']] ?? []]),
        ]);
    }

    private function webhook(array $payload): void
    {
        $body = json_encode($payload);
        $this->call('POST', '/api/payments/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET)], $body)->assertOk();
    }

    /** The church asks, the diocese approves and switches it on. */
    private function ownPaystack(): PaymentChannel
    {
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/giving/payout-request', ['bank_code' => '68', 'account_number' => '0123456789', 'account_name' => 'CCI Church 27', 'settles_into_id' => $this->churchBank->id])->assertCreated();
        $ch = PaymentChannel::where('territory_id', $this->myChurch->id)->firstOrFail();
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/accounting/gateways/channels/{$ch->id}/review", ['decision' => 'approve', 'switch_on' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        return $ch->fresh();
    }

    private function gift(float $amount = 1000): string
    {
        $ref = $this->postJson('/api/give/SHR027', ['purpose' => 'T', 'amount' => $amount, 'method' => 'paystack', 'name' => 'Jane Mutua', 'phone' => '0712345678', 'email' => 'jane@example.test'])->assertCreated()->json('data.reference');
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $ref]]);
        $this->assertSame('paid', Gift::where('reference', $ref)->value('status'));

        return $ref;
    }

    public function test_the_church_asks_and_the_diocese_checks(): void
    {
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/giving/payout-options')->assertForbidden();

        Sanctum::actingAs($this->treasurer);
        $this->assertSame(['Equity Bank', 'KCB'], collect($this->getJson('/api/accounting/giving/payout-options')->assertOk()->json('data.banks'))->pluck('name')->all());
        $this->postJson('/api/accounting/giving/payout-request', ['bank_code' => 'ZZ', 'account_number' => '0123456789', 'account_name' => 'X', 'settles_into_id' => $this->churchBank->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bank_code');
        $this->postJson('/api/accounting/giving/payout-request', ['bank_code' => '68', 'account_number' => '0123456780', 'account_name' => 'CCI Church 27', 'settles_into_id' => $this->churchBank->id])
            ->assertCreated()->assertJsonPath('data.request.bank', 'Equity Bank')->assertJsonPath('data.request.account_number', '•••• 6780');
        $ch = PaymentChannel::where('territory_id', $this->myChurch->id)->firstOrFail();
        $this->assertSame('pending', $ch->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/subaccount'));

        // The diocese sends it back; the church sees why and asks again.
        Sanctum::actingAs($this->dfo);
        $req = $this->getJson('/api/accounting/gateways/payouts')->assertOk()->json('data.requests.0');
        $this->assertSame('0123456780', $req['account_number'], 'the checker sees it in full');
        $this->postJson("/api/accounting/gateways/channels/{$ch->id}/review", ['decision' => 'return'])->assertUnprocessable();
        $this->postJson("/api/accounting/gateways/channels/{$ch->id}/review", ['decision' => 'return', 'note' => 'The account number ends 6789 on the letter.'])->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->getJson('/api/accounting/giving/payouts')->assertOk()->assertJsonPath('data.route.sent_back.note', 'The account number ends 6789 on the letter.')
            ->assertJsonPath('data.route.card.route', 'diocese')->assertJsonPath('data.can.ask', true);
        $this->app['auth']->forgetGuards();

        $ch = $this->ownPaystack();
        $this->assertSame(['active', 'ACCT_church27', '0123456789', $this->churchBank->id], [$ch->status, $ch->subaccount_code, $ch->account_number, (int) $ch->settles_into_id]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/subaccount') && $r['settlement_bank'] === '68' && $r['account_number'] === '0123456789');

        // A change waits for the check; gifts keep going to the old account until then.
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/giving/payout-request', ['bank_code' => '01', 'account_number' => '5550001111', 'account_name' => 'CCI Church 27', 'settles_into_id' => $this->churchBank->id])->assertCreated();
        $this->assertSame(['active', '0123456789'], [$ch->fresh()->status, $ch->fresh()->account_number]);
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/accounting/gateways/channels/{$ch->id}/review", ['decision' => 'approve'])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/subaccount/ACCT_church27') && $r['settlement_bank'] === '01');
        $this->assertSame(['active', 'KCB', '5550001111'], [$ch->fresh()->status, $ch->fresh()->bank_name, $ch->fresh()->account_number]);
    }

    public function test_payouts_are_matched_to_the_gifts_they_paid(): void
    {
        $this->ownPaystack();
        $refs = [$this->gift(), $this->gift()];
        $today = now('Africa/Lagos')->toDateString();
        // The church's part: 2 x 885; the diocese's main account: 2 x 100 split off; and a payout that failed.
        $this->settlements = [
            'ACCT_church27' => [['id' => 501, 'status' => 'success', 'total_amount' => 177000, 'effective_amount' => 177000, 'total_fees' => 0, 'settlement_date' => $today],
                ['id' => 502, 'status' => 'failed', 'total_amount' => 5000, 'effective_amount' => 5000, 'settlement_date' => $today]],
            'none' => [['id' => 601, 'status' => 'success', 'total_amount' => 20000, 'effective_amount' => 20000, 'settlement_date' => $today]],
        ];
        $this->artisan('payments:settlements')->assertSuccessful();
        $this->artisan('payments:settlements')->assertSuccessful();

        $church = PaystackSettlement::where('settlement_id', '501')->firstOrFail();
        $this->assertSame(['adds_up', false], [$church->matched, $church->main]);
        $this->assertSame(2, Gift::where('settlement_id', $church->id)->count());
        $this->assertSame(2, Gift::where('main_settlement_id', PaystackSettlement::where('settlement_id', '601')->value('id'))->count(), 'the diocese\'s share matched to its own payout');
        $this->assertNull(PaystackSettlement::where('settlement_id', '502')->value('journal_id'), 'a failed payout is never posted');
        $ledger = app(Ledger::class);
        $this->assertEquals(1770, $ledger->balance($this->myChurch, $this->churchBank), 'posted once');
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->chart->account('online_clearing')), 'clearing empties');

        Sanctum::actingAs($this->treasurer);
        $d = $this->getJson('/api/accounting/giving/payouts')->assertOk()->json('data');
        $this->assertSame([1770.0, 1, 1, 0.0], [(float) $d['stats']['paid'], $d['stats']['count'], $d['stats']['failed'], (float) $d['stats']['on_the_way']]);
        $this->assertSame('own', $d['route']['card']['route']);
        $detail = $this->getJson("/api/accounting/giving/payouts/{$church->id}")->assertOk()->json('data');
        $this->assertEquals([2000, 30, 200, 1770, 0], [$detail['totals']['gross'], $detail['totals']['fee'], $detail['totals']['share'], $detail['totals']['part'], $detail['difference']]);
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/giving/payouts/{$church->id}")->assertNotFound();

        // A third gift isn't paid out yet: on the way.
        $this->app['auth']->forgetGuards();
        $this->paidAt = now('Africa/Lagos')->toIso8601String();
        $this->gift();
        Sanctum::actingAs($this->treasurer);
        $this->assertEquals(885, $this->getJson('/api/accounting/giving/payouts')->json('data.stats.on_the_way'));

        Sanctum::actingAs($this->dfo);
        $row = collect($this->getJson('/api/accounting/gateways/payouts')->assertOk()->json('data.places'))->firstWhere('id', $this->myChurch->id);
        $this->assertSame(['own', 1, 1770.0, 1, 300.0], [$row['route'], $row['payouts'], (float) $row['paid'], $row['failed'], (float) $row['share']]);
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/gateways/payouts')->assertForbidden();
    }

    public function test_a_refund_undoes_the_gift_and_a_dispute_is_marked(): void
    {
        $this->ownPaystack();
        $ref = $this->gift();
        $ledger = app(Ledger::class);
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->acc('4000')));
        $remittance = Remittance::findOrFail(Gift::where('reference', $ref)->value('remittance_id'));

        $refund = ['event' => 'refund.processed', 'data' => ['status' => 'processed', 'transaction_reference' => $ref, 'amount' => 100000, 'id' => 9001]];
        $this->webhook($refund);
        $this->webhook($refund);
        $gift = Gift::where('reference', $ref)->firstOrFail();
        $this->assertSame('refunded', $gift->status);
        foreach (['4000', '5700', '5800'] as $code) {
            $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc($code)), "{$code} undone");
        }
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->chart->account('online_clearing')));
        $this->assertEquals(0, $ledger->balance($this->diocese, $this->acc('4100')));
        $this->assertSame('cancelled', $remittance->fresh()->status);
        $this->assertTrue($ledger->trialBalance($this->myChurch)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);

        // A part refund is flagged, not guessed; a dispute is marked until it ends.
        $part = $this->gift();
        $this->webhook(['event' => 'refund.processed', 'data' => ['transaction_reference' => $part, 'amount' => 30000]]);
        $this->assertSame(['paid', 300.0], [Gift::where('reference', $part)->value('status'), (float) Gift::where('reference', $part)->value('refunded_amount')]);
        $this->assertEquals(1000, $ledger->balance($this->myChurch, $this->acc('4000')));
        $won = $this->gift();
        $this->webhook(['event' => 'charge.dispute.create', 'data' => ['transaction' => ['reference' => $won]]]);
        $this->assertNotNull(Gift::where('reference', $won)->value('disputed_at'));
        $this->webhook(['event' => 'charge.dispute.resolve', 'data' => ['resolution' => 'declined', 'transaction' => ['reference' => $won]]]);
        $this->assertNull(Gift::where('reference', $won)->value('disputed_at'));
        $lost = $this->gift();
        $this->webhook(['event' => 'charge.dispute.create', 'data' => ['transaction' => ['reference' => $lost]]]);
        $this->webhook(['event' => 'charge.dispute.resolve', 'data' => ['resolution' => 'merchant-accepted', 'refund_amount' => 100000, 'transaction' => ['reference' => $lost]]]);
        $this->assertSame('refunded', Gift::where('reference', $lost)->value('status'));
        $this->assertEquals(2000, $ledger->balance($this->myChurch, $this->acc('4000')), 'the part-refunded and the won gift stay');
        $owed = collect(app(Remittances::class)->owing($this->myChurch, (int) now()->year)[0]['months'])->firstWhere('month', now()->format('Y-m'));
        $this->assertEquals($owed['due'], $owed['sent'], 'the shares of the two gifts kept are still sent');
    }
}
