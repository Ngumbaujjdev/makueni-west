<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\Settings\Settings;
use App\Services\Sms\Sms;
use App\Support\Settings\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The diocese's system settings (docs/specs/settings-spec.md, S4): email
 * and SMS set on screen, test sends, System health - global admins only.
 */
class SettingsSystemTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
    }

    private function saveAs($user, string $section, array $values): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson("/api/settings/sections/{$section}", ['values' => $values]);
    }

    public function test_only_global_admins_see_and_use_the_system_settings(): void
    {
        Sanctum::actingAs($this->bishop);
        $keys = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->pluck('key');
        $this->assertNotContains('email', $keys->all());
        $this->assertNotContains('health', $keys->all());
        $this->getJson('/api/settings/health')->assertForbidden();
        $this->postJson('/api/settings/test/email', ['to' => 'x@example.test'])->assertForbidden();
        $this->putJson('/api/settings/sections/email', ['values' => ['mail.host' => 'evil.example']])->assertForbidden();

        Sanctum::actingAs($this->globalAdmin);
        $keys = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->pluck('key');
        $this->assertContains('email', $keys->all());
        $this->assertContains('sms', $keys->all());
        $this->assertContains('health', $keys->all());
    }

    public function test_saved_email_settings_take_over_from_the_env_and_the_password_stays_secret(): void
    {
        $this->saveAs($this->globalAdmin, 'email', [
            'mail.mailer' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.port' => '465', 'mail.scheme' => 'smtps',
            'mail.username' => 'office@example.test', 'mail.password' => 'super-secret', 'mail.from_address' => 'office@example.test',
        ])->assertOk();

        $this->assertStringNotContainsString('super-secret', (string) Setting::where('key', 'mail.password')->value('value'));
        $this->assertStringNotContainsString('super-secret', $this->getJson('/api/settings/sections/email')->getContent());

        app(Settings::class)->applyToConfig();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('super-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('office@example.test', config('mail.from.address'));
    }

    public function test_a_test_email_is_sent_and_logged(): void
    {
        Sanctum::actingAs($this->globalAdmin);

        $this->postJson('/api/settings/test/email', ['to' => 'pastor@example.test'])->assertOk();

        $this->assertDatabaseHas('message_logs', ['channel' => 'mail', 'to' => 'pastor@example.test']);
        $this->postJson('/api/settings/test/email', ['to' => 'not-an-email'])->assertStatus(422);
    }

    public function test_sms_is_only_logged_until_a_gateway_is_set_up(): void
    {
        Sanctum::actingAs($this->globalAdmin);

        $this->postJson('/api/settings/test/sms', ['to' => '0712 345 678'])->assertOk()->assertJsonPath('data.status', 'logged');

        $this->assertDatabaseHas('message_logs', ['channel' => 'sms', 'to' => '+254712345678', 'status' => 'logged']);
        $this->postJson('/api/settings/test/sms', ['to' => '12'])->assertStatus(422);
    }

    public function test_africas_talking_gets_the_right_request_and_its_errors_come_back(): void
    {
        $this->saveAs($this->globalAdmin, 'sms', [
            'sms.driver' => 'africastalking', 'sms.username' => 'sandbox', 'sms.api_key' => 'at-key-123', 'sms.sandbox' => true, 'sms.sender_id' => 'MKWEST',
        ])->assertOk();
        Http::fake([
            'api.sandbox.africastalking.com/version1/messaging' => Http::sequence()
                ->push(['SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [['statusCode' => 101, 'number' => '+254712345678', 'status' => 'Success', 'messageId' => 'ATXid_1']]]])
                ->push(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 403, 'number' => '+254712345678', 'status' => 'InvalidSenderId']]]]),
        ]);

        $ok = app(Sms::class)->send('0712 345 678', 'Hello');
        $this->assertSame('sent', $ok['status']);
        Http::assertSent(fn ($r) => $r->hasHeader('apiKey', 'at-key-123')
            && $r['username'] === 'sandbox' && $r['to'] === '+254712345678' && $r['from'] === 'MKWEST' && $r['message'] === 'Hello');

        $bad = app(Sms::class)->send('0712 345 678', 'Hello');
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('InvalidSenderId', $bad['error']);
        $this->assertDatabaseHas('message_logs', ['channel' => 'sms', 'status' => 'sent', 'provider_ref' => 'ATXid_1']);
        $this->assertDatabaseHas('message_logs', ['channel' => 'sms', 'status' => 'failed']);
    }

    public function test_health_says_what_needs_checking(): void
    {
        $tiles = fn () => collect(app(Health::class)->report(false)['tiles'])->keyBy('key');

        $this->assertSame('check', $tiles()['scheduler']['status'], 'no heartbeat yet');
        $this->assertSame('check', $tiles()['sms']['status'], 'log only');
        Cache::forever(Health::HEARTBEAT, now()->toIso8601String());
        $this->assertSame('ok', $tiles()['scheduler']['status']);

        DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $this->assertSame('check', $tiles()['queue']['status']);
        $this->assertContains('retry-failed', $tiles()['queue']['actions']);

        Sanctum::actingAs($this->globalAdmin);
        $this->getJson('/api/settings/health?quick=1')->assertOk()->assertJsonCount(5, 'data.tiles');
    }

    public function test_retry_failed_puts_failed_jobs_back(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => 'x-2', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['all']]);
        Sanctum::actingAs($this->globalAdmin);

        $this->postJson('/api/settings/maintenance/retry-failed')->assertOk()->assertJsonPath('data.retried', 1);
    }
}
