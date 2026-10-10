<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Jobs\SendPaybillThanks;
use App\Models\AccountingFund;
use App\Models\Gift;
use App\Models\GivingPurpose;
use App\Models\JournalLine;
use App\Models\MpesaPayment;
use App\Models\User;
use App\Services\Accounting\Giving;
use App\Services\Accounting\Ledger;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Giving options and funds at every level (docs/specs/accounting-spec.md, A11):
 * a region's option for its churches shows only on their pages and its money
 * lands in the region's books; endings are checked; a place's fund is its own;
 * what has been used is switched off, never removed.
 */
class GivingOptionsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'OptionsCallbackKey0123456789abcdefABCDE';

    private User $regionSettings;

    private User $churchSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendPaybillThanks::class, SendGiftReceipt::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->otherRegion->update(['code' => 'CCI-MWD-KIB']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $this->otherChurch->update(['code' => 'CCI-MWD-SHR-028']);
        $this->farChurch->update(['code' => 'CCI-MWD-KIB-001']);
        $dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $dfo);
        $this->regionSettings = $this->userWithRole('regsettings', 'Regional Treasurer', 'region', $this->region->id, ['region.settings.hub.givingoptions.update']);
        $this->churchSettings = $this->userWithRole('chsettings', 'Church Treasurer', 'church', $this->myChurch->id, ['church.settings.hub.givingoptions.update']);
    }

    private function pay(string $ref, string $code, float $amount = 500): MpesaPayment
    {
        $this->postJson('/api/payments/daraja/'.self::KEY.'/confirmation', ['TransactionType' => 'Pay Bill', 'TransID' => $code, 'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => number_format($amount, 2, '.', ''), 'BusinessShortCode' => '600123', 'BillRefNumber' => $ref, 'MSISDN' => '254712345678', 'FirstName' => 'Jane'])->assertOk();

        return MpesaPayment::where('trans_id', $code)->firstOrFail();
    }

    private function save(User $as, array $body): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as);
        $r = $this->putJson('/api/settings/giving-options', $body + ['purposes' => [], 'funds' => []]);
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function conference(array $extra = []): array
    {
        $row = ['label' => 'Region conference', 'suffix' => 'CONF', 'reach' => 'below', 'account_id' => $this->acc('4020')->id, 'fund_id' => null, 'fund_code' => 'RCONF', 'icon' => 'ri-community-line', 'colour' => 'info'];

        return array_merge($row, $extra);
    }

    private function regionSetsUpTheConference(): GivingPurpose
    {
        $this->save($this->regionSettings, ['purposes' => [$this->conference()], 'funds' => [['code' => 'RCONF', 'name' => 'Region conference fund', 'reach' => 'below']]])->assertOk();

        return GivingPurpose::where('label', 'Region conference')->firstOrFail();
    }

    public function test_a_region_option_shows_on_its_churches_and_its_money_is_the_regions(): void
    {
        $conf = $this->regionSetsUpTheConference();
        $fund = AccountingFund::where('code', 'RCONF')->firstOrFail();
        $this->assertSame([$this->region->id, 'below', $fund->id], [$conf->territory_id, $conf->reach, $conf->fund_id]);
        $this->assertSame('3900', $fund->equityAccount->code, 'a place fund sits on Other funds');

        $page = collect($this->getJson('/api/give/SHR027')->json('data.purposes'));
        $this->assertSame('Region A', $page->firstWhere('key', $conf->key)['owner']['name']);
        $this->assertNull(collect($this->getJson('/api/give/KIB001')->json('data.purposes'))->firstWhere('key', $conf->key), 'not in another region');

        $p = $this->pay('SHR027CONF', 'SJKOPT0001');
        $this->assertSame(['posted', $this->myChurch->id, $this->region->id, $conf->key], [$p->status, $p->territory_id, $p->owner_territory_id, $p->purpose]);
        $ledger = app(Ledger::class);
        $this->assertEquals(500, $ledger->balance($this->region, $this->acc('4020')), 'the region\'s income');
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->acc('4020')), 'not the church\'s');
        $this->assertSame($fund->id, (int) JournalLine::where('territory_id', $this->region->id)->where('account_id', $this->acc('4020')->id)->value('fund_id'));
        $this->assertSame($this->region->id, (int) JournalLine::where('territory_id', $this->diocese->id)->where('account_id', $this->chart->account('held_for_others')->id)->value('for_territory_id'),
            'the diocese holds it for the region and settles it to the region');
        $this->assertTrue($ledger->trialBalance($this->region)['balanced'] && $ledger->trialBalance($this->diocese)['balanced']);

        // The same ending at a church in another region waits to be sorted.
        $far = $this->pay('KIB001CONF', 'SJKOPT0002');
        $this->assertSame('to_sort', $far->status);
        $this->assertStringContainsString("isn't one of Far Church's giving options", $far->note);
    }

    public function test_a_paystack_gift_for_a_region_option_goes_into_the_regions_books(): void
    {
        $conf = $this->regionSetsUpTheConference();
        $gift = Gift::create(['reference' => 'GFT-OPT-1', 'territory_id' => $this->myChurch->id, 'owner_territory_id' => $this->region->id, 'purpose' => $conf->key, 'amount' => 1000,
            'giver_name' => 'Ruth', 'method' => 'paystack', 'status' => 'pending', 'channel' => 'diocese']);
        app(Giving::class)->complete($gift, ['status' => 'success', 'amount' => 100000, 'currency' => 'KES', 'reference' => 'GFT-OPT-1', 'fees' => 0, 'channel' => 'card', 'paid_at' => now()->toIso8601String()]);
        $this->assertSame('paid', $gift->fresh()->status);
        $this->assertEquals(1000, app(Ledger::class)->balance($this->region, $this->acc('4020')));
        $this->assertEquals(0, app(Ledger::class)->balance($this->myChurch, $this->acc('4020')));
        $this->assertStringContainsString('given at My Church', (string) \App\Models\Journal::find($gift->fresh()->journal_id)->narration);
    }

    public function test_endings_are_checked(): void
    {
        $this->regionSetsUpTheConference();
        $row = fn (string $suffix, array $words = []) => ['purposes' => [['label' => 'Choir', 'suffix' => $suffix, 'words' => $words, 'account_id' => $this->acc('4020')->id]]];
        $this->save($this->churchSettings, $row('T'))->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e), 'already used by Tithe'));
        $this->save($this->churchSettings, $row('CONF'))->assertStatus(422);
        $this->save($this->churchSettings, $row('CHOIR', ['TITHE']))->assertStatus(422);
        $this->save($this->churchSettings, $row('FUNDS'))->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e), 'ends in DS'));
        $this->save($this->churchSettings, $row('CHOIR7'))->assertStatus(422);
        $this->save($this->churchSettings, ['purposes' => [['label' => 'Choir', 'suffix' => 'CHOIR', 'reach' => 'below', 'account_id' => $this->acc('1000')->id]]])->assertStatus(422);
        $this->save($this->churchSettings, $row('CHOIR'))->assertOk();
        $choir = GivingPurpose::where('suffix', 'CHOIR')->firstOrFail();
        $this->assertSame([$this->myChurch->id, 'self'], [$choir->territory_id, $choir->reach], 'a church\'s option is for itself');
        $this->assertNull(collect($this->getJson('/api/give/SHR028')->json('data.purposes'))->firstWhere('key', $choir->key), 'not on the church next door');
    }

    public function test_a_used_option_is_switched_off_and_a_changed_ending_still_works(): void
    {
        $conf = $this->regionSetsUpTheConference();
        $this->pay('SHR027CONF', 'SJKOPT0003');
        $fund = AccountingFund::where('code', 'RCONF')->firstOrFail();
        $this->save($this->regionSettings, ['purposes' => [$this->conference(['id' => $conf->id, 'suffix' => 'RCON', 'fund_id' => $fund->id, 'fund_code' => null])],
            'funds' => [['id' => $fund->id, 'code' => 'RCONF', 'name' => 'Region conference fund', 'reach' => 'below']]])->assertOk();
        $this->assertSame(['RCON', ['CONF']], [$conf->fresh()->suffix, $conf->fresh()->words]);
        $this->assertSame('posted', $this->pay('SHR027CONF', 'SJKOPT0004')->status, 'the old ending still works');

        $this->save($this->regionSettings, [])->assertOk();
        $this->assertFalse($conf->fresh()->is_active, 'used, so switched off');
        $this->assertFalse($fund->fresh()->is_active);
        $this->assertSame('Region conference', \App\Services\Accounting\GivingPurposes::label($conf->key));
    }

    public function test_a_place_fund_is_its_own(): void
    {
        $this->save($this->churchSettings, ['funds' => [['code' => 'CHOIR', 'name' => 'Choir fund']]])->assertOk();
        $choir = AccountingFund::where('code', 'CHOIR')->firstOrFail();
        $this->assertSame([$this->myChurch->id, 'self', true], [$choir->territory_id, $choir->reach, $choir->is_restricted]);
        $lines = fn () => [['account_id' => $this->cash()->id, 'debit' => 100], ['account_id' => $this->acc('4020')->id, 'credit' => 100, 'fund_id' => $choir->id]];
        $ledger = app(Ledger::class);
        $ledger->post($this->myChurch, ['doc_type' => 'receipt', 'date' => now()->toDateString()], $lines(), $this->treasurer);
        try {
            $ledger->post($this->otherChurch, ['doc_type' => 'receipt', 'date' => now()->toDateString()], [['account_id' => $this->chart->account('held_by_diocese')->id, 'debit' => 100], ['account_id' => $this->acc('4020')->id, 'credit' => 100, 'fund_id' => $choir->id]], null);
            $this->fail('another church used My Church\'s fund');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('funds', json_encode($e->errors()));
        }
        $this->save($this->churchSettings, ['funds' => [['code' => 'GEN', 'name' => 'Mine']]])->assertStatus(422);

        // A region's fund for the places below reaches its churches, not others.
        $this->regionSetsUpTheConference();
        Sanctum::actingAs($this->treasurer);
        $this->assertContains('RCONF', collect($this->getJson('/api/accounting/options')->json('data.funds'))->pluck('code')->all());
        $this->assertContains('CHOIR', collect($this->getJson('/api/accounting/options')->json('data.funds'))->pluck('code')->all());
    }

    public function test_the_diocese_keeps_the_standard_options(): void
    {
        $bishop = $this->userWithRole('dsettings', 'Diocese Treasurer', 'diocese', $this->diocese->id, ['diocese.settings.hub.givingoptions.update']);
        Sanctum::actingAs($bishop);
        $standard = collect($this->getJson('/api/settings/giving-options')->assertOk()->json('data.standard'));
        $this->assertSame(['T', 'O', 'TH', 'B', 'K'], $standard->pluck('key')->all());
        $this->app['auth']->forgetGuards();
        $rows = $standard->map(fn ($r) => $r['key'] === 'O' ? ['label' => 'Sadaka'] + $r : $r)->reject(fn ($r) => $r['key'] === 'K')->values()->all();
        $this->save($bishop, ['standard' => $rows, 'default' => 'T'])->assertOk();
        $this->assertSame('Sadaka', GivingPurpose::where('key', 'O')->value('label'));
        $this->assertFalse((bool) GivingPurpose::where('key', 'K')->value('is_active'), 'a standard one is only ever switched off');
        $this->assertSame('T', app(\App\Services\Accounting\Paybill::class)->parse('SHR027')['purpose'], 'the new default');
        $this->save($bishop, ['standard' => $rows, 'default' => 'K'])->assertStatus(422);
    }

    public function test_who_can_change_what_and_hiding_an_inherited_option(): void
    {
        Sanctum::actingAs($this->authoriser);
        $this->getJson('/api/settings/giving-options')->assertForbidden();
        Sanctum::actingAs($this->churchSettings);
        $this->getJson('/api/settings/giving-options?territory_id='.$this->otherChurch->id)->assertForbidden();
        $view = $this->getJson('/api/settings/giving-options')->assertOk()->json('data');
        $this->assertNull($view['standard'], 'only the diocese keeps the standard ones');
        $th = collect($view['inherited'])->firstWhere('key', 'TH');
        $this->assertTrue($th['shown']);
        $this->app['auth']->forgetGuards();

        $this->save($this->churchSettings, ['hidden' => [$th['id']]])->assertOk();
        $this->assertNull(collect($this->getJson('/api/give/SHR027')->json('data.purposes'))->firstWhere('key', 'TH'), 'hidden from its page');
        $this->assertSame('posted', $this->pay('SHR027TH', 'SJKOPT0005')->status, 'a payment with the ending still posts');
    }
}
