<?php

namespace Tests\Feature\Settings;

use App\Models\MessageLog;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The diocese's SMS through TextSMS (textsms.co.ke), with the account from the
 * server's .env (config services.textsms) - never stored, never shown. Tests
 * never send: phpunit.xml keeps SMS on the log and these fake the call.
 */
class TextSmsTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    private const URL = 'https://sms.textsms.co.ke/api/services/sendsms/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        config(['services.textsms' => ['url' => self::URL, 'api_key' => 'tx-secret-key', 'partner_id' => '9999', 'shortcode' => 'CCIMWD']]);
    }

    private function driver(string $driver): void
    {
        app(Settings::class)->setMany($this->diocese, 'diocese', 'sms', ['sms.driver' => $driver], [], [], $this->bishop);
    }

    public function test_a_church_sms_goes_through_textsms_and_is_logged_sent(): void
    {
        $this->driver('textsms');
        app(Settings::class)->setMany($this->myChurch, 'church', 'communication', ['comms.sms_signature' => 'CCI My Church'], [], [], $this->pastor);
        Http::fake([self::URL => Http::response(['responses' => [['respose-code' => 200, 'response-description' => 'Success', 'mobile' => 254712345678, 'messageid' => 8812345, 'networkid' => '1']]])]);

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712 345 678', 'Hello Jane', 'test', [], $this->pastor);

        $this->assertSame(['sent', 'diocese'], [$r['status'], $r['via']]);
        Http::assertSent(fn ($req) => $req->url() === self::URL && $req['apikey'] === 'tx-secret-key' && $req['partnerID'] === '9999'
            && $req['mobile'] === '254712345678' && $req['shortcode'] === 'CCIMWD' && $req['message'] === "Hello Jane\n- CCI My Church");
        $log = MessageLog::findOrFail($r['log_id']);
        $this->assertSame(['sent', '8812345', 'CCIMWD'], [$log->status, $log->provider_ref, $log->from]);
        $this->assertStringNotContainsString('tx-secret-key', json_encode($log->toArray()));

        // It counts as really sending, under the shortcode.
        $c = app(PlaceMessenger::class)->channels($this->myChurch);
        $this->assertSame([true, 'CCIMWD'], [$c['sms']['sends'], $c['sms']['sender_id']]);
    }

    public function test_a_textsms_error_is_a_failure_with_its_reason(): void
    {
        $this->driver('textsms');
        Http::fake([self::URL => Http::sequence()
            ->push(['responses' => [['response-code' => 1004, 'response-description' => 'Low bulk credits']]])
            ->push(['response-code' => 1006, 'response-description' => 'Invalid credentials'], 401)]);

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712345678', 'Hi', 'test');

        $this->assertSame('failed', $r['status']);
        $this->assertSame('TextSMS said: Low bulk credits', $r['error']);

        // Wrong key or partner ID: it says where to look.
        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712345678', 'Hi', 'test');
        $this->assertSame("TextSMS said: Invalid credentials - check TEXTSMS_API_KEY and TEXTSMS_PARTNER_ID in the server's .env", $r['error']);
    }

    public function test_without_the_server_details_nothing_is_sent(): void
    {
        $this->driver('textsms');
        config(['services.textsms.api_key' => null]);
        Http::fake();

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712345678', 'Hi', 'test');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString("TextSMS isn't set up on the server", $r['error']);
        Http::assertNothingSent();
    }

    public function test_choosing_the_log_in_settings_wins(): void
    {
        $this->driver('log');
        Http::fake();

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712345678', 'Hi', 'test');

        $this->assertSame('logged', $r['status']);
        Http::assertNothingSent();
        $this->assertFalse(app(PlaceMessenger::class)->channels($this->myChurch)['sms']['sends']);
    }
}
