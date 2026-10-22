<?php

namespace Tests\Feature\Mobile;

use App\Models\PushSubscription;
use App\Notifications\PlaceNotification;
use App\Services\Chat\Chats;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Settings\BuildsSettingsWorld;
use Tests\TestCase;

/**
 * The phone app's push notifications and giving QR (docs/specs/mobile-app-spec.md,
 * M12): signing a phone up, every bell notice reaching the person's phones, chat
 * reaching the other members, dead phones forgotten - with a fake sender, so
 * nothing leaves the test.
 */
class PushTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        $this->sender = new class(app(\App\Services\Settings\Settings::class)) extends PushSender
        {
            public array $sent = [];

            public array $answer = [];

            public bool $isReady = true;

            public function ready(): bool
            {
                return $this->isReady;
            }

            public function deliver(iterable $subs, array $payload): array
            {
                $out = [];
                foreach ($subs as $s) {
                    $this->sent[] = ['user_id' => $s->user_id, 'endpoint' => $s->endpoint, 'payload' => $payload];
                    $out[$s->endpoint] = $this->answer[$s->endpoint] ?? 201;
                }

                return $out;
            }
        };
        $this->app->instance(PushSender::class, $this->sender);
    }

    private function phone($user, string $endpoint = 'https://push.example.test/abc'): PushSubscription
    {
        return PushSubscription::create(['user_id' => $user->id, 'endpoint' => $endpoint, 'p256dh' => 'p', 'auth' => 'a']);
    }

    public function test_a_phone_signs_up_and_a_second_sign_up_updates_it(): void
    {
        Sanctum::actingAs($this->pastor);
        $body = ['endpoint' => 'https://push.example.test/1', 'keys' => ['p256dh' => 'k1', 'auth' => 'a1']];
        $this->postJson('/api/app/push/subscribe', $body)->assertJsonPath('success', true);
        $this->postJson('/api/app/push/subscribe', ['keys' => ['p256dh' => 'k2', 'auth' => 'a2']] + $body)->assertJsonPath('success', true);
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('k2', PushSubscription::first()->p256dh);
    }

    public function test_only_the_owner_can_turn_a_phone_off(): void
    {
        $this->phone($this->pastor);
        Sanctum::actingAs($this->secretary);
        $this->deleteJson('/api/app/push/subscribe', ['endpoint' => 'https://push.example.test/abc'])->assertJsonPath('success', true);
        $this->assertSame(1, PushSubscription::count());
        Sanctum::actingAs($this->pastor);
        $this->deleteJson('/api/app/push/subscribe', ['endpoint' => 'https://push.example.test/abc']);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_bell_notice_reaches_the_persons_phones_and_nobody_elses(): void
    {
        $this->phone($this->pastor);
        $this->phone($this->secretary, 'https://push.example.test/other');
        $this->pastor->notify(new PlaceNotification('report', 'Report seen', 'Your September report was seen.', '/church/monthly-reports'));

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame($this->pastor->id, $this->sender->sent[0]['user_id']);
        $this->assertSame('Report seen', $this->sender->sent[0]['payload']['title']);
        $this->assertSame('report', $this->sender->sent[0]['payload']['kind']);
        $this->assertSame(1, $this->pastor->notifications()->count());   // the bell still gets it
    }

    public function test_without_a_phone_the_bell_alone_is_used(): void
    {
        $this->pastor->notify(new PlaceNotification('report', 'Report seen', 'x'));
        $this->assertSame([], $this->sender->sent);
        $this->assertSame(1, $this->pastor->notifications()->count());
    }

    public function test_a_phone_that_is_gone_is_forgotten(): void
    {
        $this->phone($this->pastor);
        $this->sender->answer['https://push.example.test/abc'] = 410;
        $this->pastor->notify(new PlaceNotification('report', 'Report seen', 'x'));
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_chat_message_pushes_to_the_other_member_only(): void
    {
        $this->phone($this->pastor, 'https://push.example.test/me');
        $this->phone($this->secretary, 'https://push.example.test/them');
        $chats = app(Chats::class);
        $chat = $chats->direct($this->pastor, $this->secretary);
        $chats->send($chat, $this->pastor, 'Service starts at 9');

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame($this->secretary->id, $this->sender->sent[0]['user_id']);
        $this->assertSame('chat', $this->sender->sent[0]['payload']['kind']);
        $this->assertSame('Service starts at 9', $this->sender->sent[0]['payload']['body']);
    }

    public function test_the_key_says_whether_push_is_ready(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->sender->isReady = false;
        $this->getJson('/api/app/push/key')->assertJsonPath('data.ready', false);
    }

    public function test_the_key_command_makes_keys_once(): void
    {
        $this->artisan('app:push-keys')->assertSuccessful();
        $settings = app(\App\Services\Settings\Settings::class);
        $first = $settings->system('push.vapid_public');
        $this->assertNotEmpty($first);
        $this->assertNotEmpty($settings->system('push.vapid_private'));
        $this->artisan('app:push-keys')->assertSuccessful();
        $this->assertSame($first, app(\App\Services\Settings\Settings::class)->system('push.vapid_public'));
    }

    public function test_the_giving_qr_is_an_svg_for_those_who_read_the_books(): void
    {
        Sanctum::actingAs($this->pastor);   // no accounting rights in this world
        $this->get('/api/app/giving/qr', ['Accept' => 'application/json'])->assertJsonPath('status', 403);

        $role = $this->pastor->roles()->first();
        $role->givePermissionTo(\App\Models\Permission::firstOrCreate(['name' => 'church.accounting.books.read', 'guard_name' => 'web'], ['action' => 'read', 'territory_scope' => 'church']));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $res = $this->get('/api/app/giving/qr');
        $res->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $res->getContent());
    }
}
