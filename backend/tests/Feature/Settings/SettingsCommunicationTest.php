<?php

namespace Tests\Feature\Settings;

use App\Models\MessageLog;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings > Communication (docs/specs/settings-spec.md, S6b): a church or
 * region sends through the diocese's email and SMS - with its own name,
 * reply-to and signature - or through its own accounts; the diocese can
 * lock everyone onto its own. Every send is logged with its text, masked.
 */
class SettingsCommunicationTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
    }

    private function save($place, string $level, array $values, array $locks = []): void
    {
        app(Settings::class)->setMany($place, $level, 'communication', $values, $locks, [], $this->pastor);
    }

    private function sentMail(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_through_the_diocese_a_church_email_carries_its_name_and_reply_to_and_is_logged(): void
    {
        $this->save($this->myChurch, 'church', ['comms.display_name' => 'CCI My Church', 'comms.reply_to' => 'office@mychurch.test']);

        $r = app(PlaceMessenger::class)->email($this->myChurch, 'jane@example.test', 'Welcome', '<p>Your code is 482913 and password Xy7pQ2mK</p>', 'sign_in_details', ['482913', 'Xy7pQ2mK'], $this->pastor);

        $this->assertTrue($r['ok']);
        $this->assertSame('diocese', $r['via']);
        $sent = $this->sentMail();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('CCI My Church', $email->getFrom()[0]->getName());
        $this->assertSame('test@diocese.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('office@mychurch.test', $email->getReplyTo()[0]->getAddress());

        $log = MessageLog::findOrFail($r['log_id']);
        $this->assertSame(['mail', 'sign_in_details', 'diocese', $this->myChurch->id], [$log->channel, $log->kind, $log->via, $log->territory_id]);
        $this->assertSame('<p>Your code is •••• and password ••••</p>', $log->body);
        $this->assertSame(1, MessageLog::count(), 'the email listener does not log it a second time');
    }

    public function test_through_the_diocese_an_sms_gets_the_church_signature(): void
    {
        $this->save($this->myChurch, 'church', ['comms.sms_signature' => 'CCI My Church']);

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712 345 678', 'Hello Jane', 'test', [], $this->pastor);

        $this->assertSame('logged', $r['status'], 'the diocese SMS is log-only in tests');
        $log = MessageLog::findOrFail($r['log_id']);
        $this->assertSame("Hello Jane\n- CCI My Church", $log->body);
        $this->assertSame(['sms', 'diocese', '+254712345678'], [$log->channel, $log->via, $log->to]);
    }

    public function test_with_its_own_account_a_church_sends_sms_through_it(): void
    {
        $this->save($this->myChurch, 'church', ['comms.mode' => 'own', 'comms.sms.username' => 'mychurch', 'comms.sms.api_key' => 'church-key-1', 'comms.sms.sender_id' => 'MYCHURCH']);
        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'messageId' => 'ATX-9']]]])]);

        $r = app(PlaceMessenger::class)->sms($this->myChurch, '0712345678', 'Hi', 'test');

        $this->assertSame(['sent', 'own'], [$r['status'], $r['via']]);
        Http::assertSent(fn ($req) => $req->hasHeader('apiKey', 'church-key-1') && $req['username'] === 'mychurch' && $req['from'] === 'MYCHURCH');
        $c = app(PlaceMessenger::class)->channels($this->myChurch);
        $this->assertSame(['own', 'diocese'], [$c['sms']['via'], $c['email']['via']]);
        $this->assertSame(['email'], $c['own_incomplete'], 'own chosen but no mail server yet: email still goes through the diocese');
    }

    public function test_a_church_mail_server_must_be_public(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.1.1', 'localhost'] as $host) {
            try {
                $this->save($this->myChurch, 'church', ['comms.mail.host' => $host]);
                $this->fail("{$host} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('comms.mail.host', $e->errors(), $host);
            }
        }
        $this->save($this->myChurch, 'church', ['comms.mail.host' => '203.0.113.10', 'comms.mail.port' => '465']);
        $this->assertSame('203.0.113.10', app(Settings::class)->get('comms.mail.host', $this->myChurch));
    }

    public function test_the_diocese_can_keep_everyone_on_its_email_and_sms(): void
    {
        app(Settings::class)->setMany($this->diocese, 'diocese', 'communication', ['comms.mode' => 'diocese'], ['comms.mode' => true], [], $this->globalAdmin);

        $this->expectException(ValidationException::class);
        $this->save($this->myChurch, 'church', ['comms.mode' => 'own']);
    }

    public function test_the_section_shows_each_level_what_it_can_set_and_secrets_never_come_back(): void
    {
        $this->save($this->myChurch, 'church', ['comms.mode' => 'own', 'comms.mail.password' => 'smtp-secret-pw']);
        Sanctum::actingAs($this->pastor);
        $res = $this->getJson('/api/settings/sections/communication')->assertOk();
        $this->assertStringNotContainsString('smtp-secret-pw', $res->getContent());
        $keys = collect($res->json('data.cards'))->flatMap(fn ($c) => $c['fields'])->pluck('key');
        $this->assertContains('comms.mail.host', $keys->all());
        $this->assertSame(['comms.mode' => 'own'], collect($res->json('data.cards'))->flatMap(fn ($c) => $c['fields'])->firstWhere('key', 'comms.mail.host')['show_if']);
        $this->assertSame('My Church', $res->json('data.extra.display_name'));

        Sanctum::actingAs($this->bishop);
        $keys = collect($this->getJson('/api/settings/sections/communication')->assertOk()->json('data.cards'))->flatMap(fn ($c) => $c['fields'])->pluck('key');
        $this->assertSame(['comms.mode'], $keys->all(), 'the diocese only chooses (and locks) how churches and regions send');
    }

    public function test_a_test_send_goes_through_the_place_path_and_needs_update(): void
    {
        Sanctum::actingAs($this->secretary);
        $this->postJson('/api/settings/communication/test', ['channel' => 'email', 'to' => 'jane@example.test'])->assertForbidden();

        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/settings/communication/test', ['channel' => 'email', 'to' => 'jane@example.test'])->assertOk()->assertJsonPath('data.via', 'diocese');
        $this->postJson('/api/settings/communication/test', ['channel' => 'sms', 'to' => '0712345678'])->assertOk()->assertJsonPath('data.status', 'logged');
        // A digit too many is refused with the reason, before anything is sent.
        $this->postJson('/api/settings/communication/test', ['channel' => 'sms', 'to' => '+2547571410682'])->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'A Kenyan number has 9 digits after +254 (like +254 712 345 678) - this one has 10.');
        $this->assertSame(2, MessageLog::where('territory_id', $this->myChurch->id)->where('kind', 'test')->count());
        $this->assertStringContainsString('Your email is working', MessageLog::where('channel', 'mail')->value('body'));
    }

    public function test_account_emails_are_logged_without_their_text(): void
    {
        Mail::raw('Reset link: https://example.test/reset?token=secret-token', fn ($m) => $m->to('jane@example.test')->subject('Reset your password'));

        $log = MessageLog::firstOrFail();
        $this->assertSame(['account', 'system', null], [$log->kind, $log->via, $log->body]);
    }
}
