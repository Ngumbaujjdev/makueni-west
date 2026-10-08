<?php

namespace Tests\Feature\Communications;

use App\Models\MessageBatch;
use App\Models\MessageLog;
use App\Models\MessageRecipient;
use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Submodule;
use App\Models\User;
use Database\Seeders\MessagesAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Messages (docs/specs/messages-spec.md, L5a): send down - never up or
 * sideways - through the Settings sender; schedule, cancel, retry; the Inbox
 * with replies; saved messages per place.
 */
class MessagesTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private User $treasurer;

    private User $otherPastor;

    private User $farPastor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $send = fn (string $level) => ["{$level}.messages.messages.read", "{$level}.messages.messages.send", "{$level}.messages.inbox.read"];
        $this->give($this->pastor, $send('church'));
        $this->give($this->overseer, $send('region'));
        $this->give($this->bishop, $send('diocese'));
        $this->treasurer = $this->userWithRole('treasurer', 'Church Treasurer', 'church', $this->myChurch->id, ['church.messages.inbox.read']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Senior Pastor', 'church', $this->otherChurch->id, ['church.messages.inbox.read']);
        $this->farPastor = $this->userWithRole('farpastor', 'Senior Pastor', 'church', $this->farChurch->id, ['church.messages.inbox.read']);
        $this->otherPastor->forceFill(['phone' => '0722000111', 'email' => 'other@example.test'])->save();
        $this->farPastor->forceFill(['phone' => '0722000222'])->save();
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => explode('.', $name)[0]]));
        }
    }

    private function names(User $as, array $audience): array
    {
        Sanctum::actingAs($as);

        return collect($this->postJson('/api/messages/preview', ['audience' => $audience, 'channel' => 'sms', 'body' => 'Hi'])->assertOk()->json('data.names'))->sort()->values()->all();
    }

    public function test_each_level_reaches_down_never_up_or_sideways(): void
    {
        // A church: its own people only - "below" means nothing for it.
        $n = fn (User ...$users) => collect($users)->map(fn ($u) => trim("{$u->firstname} {$u->lastname}"))->sort()->values()->all();
        $this->assertSame($n($this->pastor, $this->treasurer), $this->names($this->pastor, ['own' => ['roles' => ['*']], 'below' => ['scope' => 'all', 'roles' => ['*']]]));
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/messages/options')->assertOk()->assertJsonPath('data.below', null);

        // A region: its churches' Senior Pastors - not the far region's.
        $this->assertSame($n($this->otherPastor), $this->names($this->overseer, ['below' => ['scope' => 'all', 'roles' => ['Senior Pastor']]]));
        Sanctum::actingAs($this->overseer);
        $this->postJson('/api/messages/preview', ['audience' => ['below' => ['scope' => 'picked', 'place_ids' => [$this->farChurch->id], 'roles' => ['*']]]])->assertStatus(422);
        $this->postJson('/api/messages/preview', ['audience' => ['below' => ['scope' => 'picked', 'place_ids' => [$this->diocese->id], 'roles' => ['*']]]])->assertStatus(422);
        $options = $this->getJson('/api/messages/options')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['My Church', 'Other Church'], array_column($options['below']['places'], 'name'));

        // The diocese: both regions' churches, and the regions' own people.
        $this->assertSame($n($this->pastor, $this->treasurer, $this->otherPastor, $this->farPastor, $this->overseer), $this->names($this->bishop, ['below' => ['scope' => 'all', 'levels' => ['region', 'church'], 'roles' => ['*']]]));
        $this->assertSame($n($this->farPastor), $this->names($this->bishop, ['below' => ['scope' => 'groups', 'group_ids' => [$this->otherRegion->id], 'roles' => ['Senior Pastor']]]));
    }

    public function test_typed_numbers_are_checked_and_each_person_counted_once(): void
    {
        Sanctum::actingAs($this->overseer);
        $data = $this->postJson('/api/messages/preview', ['audience' => [
            'below' => ['scope' => 'all', 'roles' => ['Senior Pastor']],
            'typed' => ["+254 722 000 111\n0733 444 555", 'not a number', 'friend@example.test'],
        ], 'channel' => 'sms', 'body' => 'Hello {name}'])->assertOk()->json('data');
        // The typed number is Other Church's pastor - counted once.
        $this->assertSame(3, $data['people']);
        $this->assertSame(['not a number'], $data['invalid']);
        // The SMS is counted with the first person's name filled in.
        $this->assertSame(['characters' => 10, 'parts' => 1, 'unicode' => false], $data['sms']);

        $this->postJson('/api/messages', ['audience' => ['typed' => ['12345']], 'channel' => 'sms', 'body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('audience');
        $this->postJson('/api/messages', ['audience' => ['own' => ['roles' => ['Nobody here']]], 'channel' => 'sms', 'body' => 'x'])->assertStatus(422);
    }

    public function test_sending_goes_through_the_settings_sender(): void
    {
        Sanctum::actingAs($this->overseer);
        $id = $this->postJson('/api/messages', [
            'audience' => ['below' => ['scope' => 'all', 'roles' => ['Senior Pastor']], 'typed' => ['0733444555']],
            'channel' => 'both', 'subject' => 'Rally', 'body' => "Dear {name},\n\nThe rally is on Saturday. - {sender}",
        ])->assertCreated()->assertJsonPath('message', 'Sending to 2 people.')->json('data.id');

        $batch = MessageBatch::find($id);
        $this->assertSame('sent', $batch->status);
        $this->assertSame([2, 0], [$batch->sent_count, $batch->failed_count]);
        $pastor = MessageRecipient::where('message_batch_id', $id)->where('user_id', $this->otherPastor->id)->first();
        $this->assertSame(['logged', 'sent'], [$pastor->sms_status, $pastor->email_status]);
        $this->assertCount(2, $pastor->log_ids);
        $typed = MessageRecipient::where('message_batch_id', $id)->whereNull('user_id')->first();
        $this->assertSame(['logged', 'skipped'], [$typed->sms_status, $typed->email_status]);
        $this->assertSame(3, MessageLog::where('kind', 'broadcast')->count());
        $this->assertStringContainsString('Dear Test,', MessageLog::where('kind', 'broadcast')->where('channel', 'sms')->where('to', '+254722000111')->value('body'));
        // In the app: the pastor's bell; the typed number has no login.
        $this->assertSame(['Rally'], $this->otherPastor->fresh()->notifications->pluck('data.title')->all());
        $this->assertSame('Senior Pastors of 2 churches in Region A · 1 typed in', $batch->summary);
    }

    public function test_schedule_cancel_run_and_retry(): void
    {
        Sanctum::actingAs($this->overseer);
        $audience = ['below' => ['scope' => 'all', 'roles' => ['Senior Pastor']]];
        $this->postJson('/api/messages', ['audience' => $audience, 'channel' => 'sms', 'body' => 'x', 'send_at' => now()->addMinute()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors('send_at');
        $a = $this->postJson('/api/messages', ['audience' => $audience, 'channel' => 'sms', 'body' => 'First', 'send_at' => now()->addHour()->toIso8601String()])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');
        $b = $this->postJson('/api/messages', ['audience' => $audience, 'channel' => 'sms', 'body' => 'Second', 'send_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('data.id');
        $this->postJson("/api/messages/{$a}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, MessageLog::count());

        $this->travel(2)->hours();
        $this->artisan('messages:send-scheduled')->assertSuccessful();
        $this->assertSame(['cancelled', 'sent'], [MessageBatch::find($a)->status, MessageBatch::find($b)->status]);
        $this->assertSame(1, MessageLog::count());

        // Retry sends only what failed.
        MessageRecipient::where('message_batch_id', $b)->update(['sms_status' => 'failed']);
        MessageBatch::whereKey($b)->update(['failed_count' => 1, 'sent_count' => 0]);
        $this->postJson("/api/messages/{$b}/retry")->assertOk();
        $this->assertSame('logged', MessageRecipient::where('message_batch_id', $b)->value('sms_status'));
        $this->assertSame(0, MessageBatch::find($b)->failed_count);
        $this->assertSame(2, MessageLog::count());
        $this->assertSame(1, $this->otherPastor->fresh()->notifications()->count(), 'the bell rang once, not again on the retry');
    }

    public function test_the_inbox_is_mine_and_replies_go_back(): void
    {
        Sanctum::actingAs($this->overseer);
        $id = $this->postJson('/api/messages', ['audience' => ['below' => ['scope' => 'all', 'roles' => ['Senior Pastor']]], 'channel' => 'app', 'subject' => 'Prayer week', 'body' => 'Join us, {name}.'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->otherPastor);
        $inbox = $this->getJson('/api/messages/inbox')->assertOk()->json('data');
        $this->assertSame(1, $inbox['unread']);
        $this->assertSame('Join us, Test.', $inbox['items'][0]['body']);
        // The chat's side panel: who sent it, and their place once - logo, contact, photos.
        $this->assertArrayHasKey('by_photo_url', $inbox['items'][0]);
        $from = (string) $inbox['items'][0]['from']['id'];
        $this->assertSame(['logo_url', 'phone', 'email', 'youtube_url', 'photos'], array_keys($inbox['places'][$from]));
        $rid = $inbox['items'][0]['id'];
        $this->postJson("/api/messages/inbox/{$rid}/read")->assertOk();
        $this->postJson("/api/messages/inbox/{$rid}/reply", ['body' => 'We will come.'])->assertCreated()->assertJsonPath('data.my_replies.0.body', 'We will come.');
        $this->assertSame(0, $this->getJson('/api/messages/inbox')->json('data.unread'));

        // Without a subject, the title is the start of the message as the person reads it.
        Sanctum::actingAs($this->overseer);
        $this->postJson('/api/messages', ['audience' => ['below' => ['scope' => 'all', 'roles' => ['Senior Pastor']]], 'channel' => 'app', 'body' => 'Hello {name}, see you Sunday.'])->assertCreated();
        Sanctum::actingAs($this->otherPastor);
        $this->assertSame('Hello Test, see you Sunday.', $this->getJson('/api/messages/inbox')->json('data.items.0.subject'));

        Sanctum::actingAs($this->farPastor);
        $this->assertSame([], $this->getJson('/api/messages/inbox')->json('data.items'));
        $this->postJson("/api/messages/inbox/{$rid}/reply", ['body' => 'Not mine'])->assertNotFound();

        Sanctum::actingAs($this->overseer);
        $this->assertTrue($this->overseer->fresh()->notifications->contains(fn ($n) => $n->data['title'] === 'Reply: Prayer week'));
        $detail = $this->getJson("/api/messages/{$id}")->assertOk()->json('data');
        $this->assertSame(['We will come.'], array_column($detail['replies_list'], 'body'));
        $this->assertSame(1, $detail['read']);
        // Another place can't open our message.
        Sanctum::actingAs($this->bishop);
        $this->getJson("/api/messages/{$id}")->assertNotFound();
    }

    public function test_saved_messages_belong_to_their_place(): void
    {
        Sanctum::actingAs($this->overseer);
        $tid = $this->postJson('/api/messages/templates', ['name' => 'Rally reminder', 'channel' => 'sms', 'body' => 'Rally on Saturday'])->assertCreated()->json('data.id');
        $this->putJson("/api/messages/templates/{$tid}", ['name' => 'Rally', 'channel' => 'sms', 'body' => 'Rally on Sunday'])->assertOk();
        $this->assertSame(['Rally'], array_column($this->getJson('/api/messages/options')->json('data.templates'), 'name'));

        Sanctum::actingAs($this->pastor);
        $this->assertSame([], $this->getJson('/api/messages/templates')->json('data'));
        $this->putJson("/api/messages/templates/{$tid}", ['name' => 'Mine', 'channel' => 'sms', 'body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/messages/templates/{$tid}")->assertNotFound();

        // Only senders: the treasurer can't.
        Sanctum::actingAs($this->treasurer);
        $this->getJson('/api/messages/options')->assertForbidden();
        $this->getJson('/api/messages/inbox')->assertOk();
    }

    public function test_the_seeder_reuses_the_placeholders(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-programs"], ['name' => 'Programs', 'territory_scope' => $level, 'is_active' => true]);
        }
        $hub = Module::create(['module_group_id' => ModuleGroup::where('slug', 'diocese-programs')->value('id'), 'name' => 'Diocese Communications Hub', 'icon' => 'x', 'number' => 16, 'is_active' => true]);
        $old = Submodule::create(['module_id' => $hub->id, 'title' => 'Bulk SMS', 'path' => '/diocese/communications/sms', 'is_active' => true]);
        $deacon = Role::firstOrCreate(['name' => 'Deacon', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $senior = Role::firstOrCreate(['name' => 'Senior Pastor', 'guard_name' => 'web'], ['territory_level' => 'church']);

        $this->seed(MessagesAccessSeeder::class);
        $this->seed(MessagesAccessSeeder::class);

        $this->assertSame('Messages', $hub->fresh()->name);
        $this->assertFalse((bool) $old->fresh()->is_active);
        foreach (['church', 'region', 'diocese'] as $level) {
            $this->assertSame(1, Submodule::where('path', "/{$level}/messages/")->count());
        }
        $this->assertTrue($deacon->fresh()->hasPermissionTo('church.messages.inbox.read'));
        $this->assertFalse($deacon->fresh()->hasPermissionTo('church.messages.messages.send'));
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.messages.messages.send'));

        // The way in for senders: "Send a message", holding send.
        foreach (['church', 'region', 'diocese'] as $level) {
            $send = Submodule::where('path', "/{$level}/messages/new.php")->sole();
            $this->assertSame('Send a message', $send->title);
            $this->assertTrue((bool) $send->is_active);
            $this->assertSame($send->id, Permission::where('name', "{$level}.messages.messages.send")->value('submodule_id'));
        }

        // Its own "Communication" group, after Programs, with the five pages - each opened by its permission.
        foreach (['church', 'region', 'diocese'] as $level) {
            $group = ModuleGroup::where('slug', "{$level}-communication")->sole();
            $this->assertSame(['Communication', $level], [$group->name, $group->territory_scope]);
            $this->assertGreaterThan(ModuleGroup::where('slug', "{$level}-programs")->value('order'), $group->order);
            $module = Submodule::where('path', "/{$level}/messages/")->sole()->module;
            $this->assertSame([$group->id, 'Messages'], [$module->module_group_id, $module->name]);
            $this->assertSame(['Campaigns', 'Inbox', 'Message log', 'Send a message', 'Templates'], Submodule::where('module_id', $module->id)->where('is_active', true)->orderBy('title')->pluck('title')->all());
            foreach (['campaigns.read' => 'campaigns.php', 'templates.manage' => 'templates.php', 'log.read' => 'log.php'] as $perm => $file) {
                $this->assertSame(Submodule::where('path', "/{$level}/messages/{$file}")->value('id'), Permission::where('name', "{$level}.messages.{$perm}")->value('submodule_id'));
            }
            $this->assertSame(1, Module::where('name', 'Messages')->whereIn('module_group_id', ModuleGroup::where('territory_scope', $level)->pluck('id'))->count());
        }
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.messages.log.read'));
        $this->assertTrue($senior->fresh()->hasPermissionTo('church.messages.templates.manage'));
        $this->assertFalse($deacon->fresh()->hasPermissionTo('church.messages.log.read'));
    }
}
