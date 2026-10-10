<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendPaybillThanks;
use App\Models\GivingPurpose;
use App\Models\MpesaPayment;
use App\Models\Setting;
use App\Services\Accounting\GivingPurposes;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\Paybill;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Giving options as data (docs/specs/accounting-spec.md, A11): the five that
 * were fixed in code keep their keys, endings and words; the list is what the
 * account numbers, the parser and every name read.
 */
class GivingPurposesTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private const KEY = 'PurposeCallbackKey0123456789abcdefABCDE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        Bus::fake([SendPaybillThanks::class]);
        $this->diocese->update(['code' => 'CCI-MWD']);
        $this->region->update(['code' => 'CCI-MWD-SHR']);
        $this->myChurch->update(['code' => 'CCI-MWD-SHR-027']);
        $dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'paybill']));
        app(Settings::class)->setMany($this->diocese, 'diocese', 'paybill', ['paybill.shortcode' => '600123', 'paybill.consumer_key' => 'ck', 'paybill.consumer_secret' => 'cs',
            'paybill.passkey' => 'pk', 'paybill.callback_key' => self::KEY, 'paybill.callback_base' => 'https://api.example.org'], [], [], $dfo);
    }

    private function pay(string $ref, string $code): void
    {
        $this->postJson('/api/payments/daraja/'.self::KEY.'/confirmation', ['TransactionType' => 'Pay Bill', 'TransID' => $code, 'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'TransAmount' => '500.00', 'BusinessShortCode' => '600123', 'BillRefNumber' => $ref, 'MSISDN' => '254712345678', 'FirstName' => 'Jane'])->assertOk();
    }

    public function test_the_standard_five_keep_their_keys_endings_and_words(): void
    {
        $paybill = app(Paybill::class);
        $this->assertSame(['T', 'O', 'TH', 'B', 'K'], app(GivingPurposes::class)->active()->pluck('key')->all());
        foreach (['SHR027T' => 'T', 'SHR027TITHE' => 'T', 'SHR027OFF' => 'O', 'SHR027SADAKA' => 'O', 'SHR027THANKS' => 'TH', 'SHR027BLD' => 'B', 'SHR027KYS' => 'K',
            'SHR027K' => 'K', 'SHR027' => 'O', 'SHRT' => 'T', 'MWDBLD' => 'B'] as $ref => $key) {
            $this->assertSame($key, $paybill->parse($ref)['purpose'], $ref);
        }
        $this->assertNull($paybill->parse('SHR027X')['purpose']);
        $this->assertSame('SHR027KYS', $paybill->accountNumbers($this->myChurch)['K']['account']);
        $this->assertSame('Any (Offering)', $paybill->accountNumbers($this->myChurch)['']['label']);
        // The Building fund is still booked to its fund.
        [$account, $fund] = app(GivingPurposes::class)->target('B');
        $this->assertSame(['4020', 'BLD'], [$account->code, $fund->code]);
    }

    public function test_the_old_default_setting_carries_over(): void
    {
        Setting::create(['territory_id' => $this->diocese->id, 'key' => 'paybill.default_purpose', 'value' => json_encode('T')]);
        $this->assertSame('T', app(GivingPurposes::class)->default()->key);
        $this->assertSame('T', app(Paybill::class)->parse('SHR027')['purpose']);
    }

    public function test_names_follow_the_list_and_old_keys_keep_theirs(): void
    {
        $this->pay('SHR027OFF', 'SJKPURP001');
        $purposes = app(GivingPurposes::class);
        GivingPurpose::where('key', 'O')->update(['label' => 'Sadaka', 'is_active' => false, 'is_default' => false]);
        GivingPurpose::where('key', 'T')->update(['is_default' => true]);
        $purposes->forget();
        $this->assertSame('Sadaka', GivingPurposes::label('O'), 'switched off, still named');
        $this->assertArrayNotHasKey('O', app(Paybill::class)->accountNumbers($this->myChurch));
        $this->assertSame('O', app(Paybill::class)->parse('SHR027OFF')['purpose'], 'a printed number keeps working');
        $this->assertSame('T', app(Paybill::class)->parse('SHR027')['purpose'], 'the new default');
        $this->assertSame('Sadaka', GivingPurposes::label(MpesaPayment::firstOrFail()->purpose), 'the payment made before is named by the list');
        $this->assertNotContains('O', collect($this->getJson('/api/give/SHR027')->json('data.purposes'))->pluck('key')->all());
    }

    public function test_a_new_option_is_parsed_and_booked_to_its_account(): void
    {
        $purposes = app(GivingPurposes::class);
        $purposes->ensureStandard();
        GivingPurpose::create(['key' => 'MIS', 'label' => 'Missions', 'suffix' => 'MIS', 'words' => ['MISSIONS'], 'account_id' => $this->acc('4030')->id,
            'reach' => 'below', 'display_order' => 60, 'is_active' => true]);
        $purposes->forget();
        $this->assertSame('SHR027MIS', app(Paybill::class)->accountNumbers($this->myChurch)['MIS']['account']);
        $this->pay('shr027 missions', 'SJKPURP002');
        $p = MpesaPayment::firstOrFail();
        $this->assertSame(['posted', 'MIS'], [$p->status, $p->purpose]);
        $this->assertEquals(500, app(Ledger::class)->balance($this->myChurch, $this->acc('4030')));
        $this->assertContains('Missions', collect($this->getJson('/api/give/SHR027')->json('data.purposes'))->pluck('label')->all());
        $this->postJson('/api/give/SHR027', ['purpose' => 'MIS', 'amount' => 100, 'method' => 'paystack', 'pay' => 'card', 'name' => 'Ruth'])->assertStatus(422)
            ->assertJsonMissingValidationErrors('purpose');
    }
}
