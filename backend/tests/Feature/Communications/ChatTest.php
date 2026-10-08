<?php

namespace Tests\Feature\Communications;

use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatRead;
use App\Events\Chat\ChatUpdated;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Chat (docs/specs/messages-spec.md, L6): any leader in the diocese can
 * chat with any other, one-to-one or in groups; only a chat's members read or
 * write in it; group admins manage the group; everything is broadcast live.
 */
class ChatTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private User $otherPastor;

    private User $farPastor;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        foreach ([[$this->pastor, 'church'], [$this->overseer, 'region'], [$this->bishop, 'diocese']] as [$u, $level]) {
            $this->give($u, ["{$level}.messages.inbox.read"]);
        }
        $this->otherPastor = $this->userWithRole('otherpastor', 'Senior Pastor', 'church', $this->otherChurch->id, ['church.messages.inbox.read']);
        $this->farPastor = $this->userWithRole('farpastor', 'Senior Pastor', 'church', $this->farChurch->id, ['church.messages.inbox.read']);
        $this->outsider = $this->userWithRole('outsider', 'Visitor Clerk', 'church', $this->myChurch->id, []);
        Event::fake([ChatMessageSent::class, ChatRead::class, ChatUpdated::class]);
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => explode('.', $name)[0]]));
        }
    }

    public function test_contacts_are_every_leader_in_the_diocese_but_me_and_search_works(): void
    {
        Sanctum::actingAs($this->pastor);
        $ids = collect($this->getJson('/api/chat/contacts')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($this->pastor->id, $ids, 'not me');
        foreach ([$this->overseer, $this->bishop, $this->otherPastor, $this->farPastor] as $u) {
            $this->assertContains($u->id, $ids, 'anyone in the diocese, up, down or sideways');
        }
        $this->assertSame($ids->count(), $ids->unique()->count(), 'each person once');

        $found = $this->getJson('/api/chat/contacts?q='.urlencode($this->farChurch->name))->json('data');
        $this->assertContains($this->farPastor->id, array_column($found, 'id'), 'found by their church');

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/chat/contacts')->assertForbidden();
    }

    public function test_a_one_to_one_chat_is_the_same_chat_both_ways_and_only_its_two_can_read_it(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/chat/direct', ['user_id' => $this->farPastor->id])->assertOk()->json('data.id');
        $this->postJson("/api/chat/chats/{$id}/messages", ['body' => 'Hello from Sultan Hamud'])->assertCreated()->assertJsonPath('data.body', 'Hello from Sultan Hamud');
        Event::assertDispatched(ChatMessageSent::class, fn ($e) => $e->chatId === $id && $e->broadcastOn()[0]->name === "private-chat.{$id}");
        Event::assertDispatched(ChatUpdated::class, fn ($e) => $e->userId === $this->farPastor->id);

        Sanctum::actingAs($this->farPastor);
        $this->assertSame($id, $this->postJson('/api/chat/direct', ['user_id' => $this->pastor->id])->json('data.id'), 'no second chat');
        $chats = $this->getJson('/api/chat/chats')->assertOk()->json('data');
        $this->assertSame(1, $chats['unread']);
        $this->assertSame('Hello from Sultan Hamud', $chats['items'][0]['last_message']['body']);
        $msgs = $this->getJson("/api/chat/chats/{$id}/messages")->assertOk()->json('data.items');
        $this->postJson("/api/chat/chats/{$id}/read", ['message_id' => end($msgs)['id']])->assertOk();
        $this->assertSame(0, $this->getJson('/api/chat/chats')->json('data.unread'));
        Event::assertDispatched(ChatRead::class);

        Sanctum::actingAs($this->pastor);
        $this->assertSame(end($msgs)['id'], $this->getJson("/api/chat/chats/{$id}/messages")->json('data.read_upto'), 'their read shows as my double tick');

        Sanctum::actingAs($this->otherPastor);
        $this->getJson("/api/chat/chats/{$id}/messages")->assertNotFound();
        $this->postJson("/api/chat/chats/{$id}/messages", ['body' => 'Hi'])->assertNotFound();
    }

    public function test_a_group_admin_adds_and_removes_members_leave_and_lines_are_written(): void
    {
        Sanctum::actingAs($this->pastor);
        $g = $this->postJson('/api/chat/groups', ['name' => 'Sultan Hamud leaders', 'member_ids' => [$this->overseer->id, $this->otherPastor->id]])
            ->assertCreated()->assertJsonPath('data.is_admin', true)->json('data');
        $this->assertCount(3, $g['people']);
        $this->postJson("/api/chat/groups/{$g['id']}/members", ['user_ids' => [$this->farPastor->id]])->assertOk();

        Sanctum::actingAs($this->otherPastor);
        $this->deleteJson("/api/chat/groups/{$g['id']}/members/{$this->farPastor->id}")->assertForbidden();
        $this->postJson("/api/chat/groups/{$g['id']}/members", ['user_ids' => [$this->bishop->id]])->assertForbidden();
        $this->patchJson("/api/chat/groups/{$g['id']}", ['name' => 'Mine'])->assertForbidden();
        $this->deleteJson("/api/chat/groups/{$g['id']}/members/{$this->otherPastor->id}")->assertOk();
        $this->postJson("/api/chat/chats/{$g['id']}/messages", ['body' => 'Still here?'])->assertForbidden();
        $this->getJson("/api/chat/chats/{$g['id']}/messages")->assertOk();

        Sanctum::actingAs($this->pastor);
        $this->deleteJson("/api/chat/groups/{$g['id']}/members/{$this->farPastor->id}")->assertOk();
        $this->patchJson("/api/chat/groups/{$g['id']}", ['name' => 'Region leaders'])->assertOk()->assertJsonPath('data.name', 'Region leaders');
        $lines = ChatMessage::where('chat_id', $g['id'])->where('kind', 'system')->orderBy('id')->pluck('body')->all();
        $this->assertCount(5, $lines, implode(' | ', $lines));
        $this->assertStringContainsString('created the group', $lines[0]);
        $this->assertStringContainsString('left', $lines[2]);

        Sanctum::actingAs($this->farPastor);
        $this->postJson("/api/chat/chats/{$g['id']}/messages", ['body' => 'Hi'])->assertForbidden();
    }

    public function test_a_group_is_never_left_without_an_admin(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/chat/groups', ['name' => 'Two of us', 'member_ids' => [$this->overseer->id]])->json('data.id');
        $this->deleteJson("/api/chat/groups/{$id}/members/{$this->pastor->id}")->assertOk();
        $this->assertTrue(Chat::find($id)->current()->where('user_id', $this->overseer->id)->value('is_admin'));
    }

    public function test_messages_are_checked(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/chat/direct', ['user_id' => $this->overseer->id])->json('data.id');
        $this->postJson("/api/chat/chats/{$id}/messages", ['body' => '   '])->assertStatus(422);
        $this->postJson("/api/chat/chats/{$id}/messages", ['body' => str_repeat('a', 4001)])->assertStatus(422);
        $this->postJson('/api/chat/direct', ['user_id' => $this->pastor->id])->assertStatus(422);
        $this->postJson('/api/chat/groups', ['name' => 'Empty', 'member_ids' => []])->assertStatus(422);
    }

    public function test_the_live_channels_let_only_members_in(): void
    {
        Sanctum::actingAs($this->pastor);
        $id = $this->postJson('/api/chat/direct', ['user_id' => $this->overseer->id])->json('data.id');
        $auth = fn (string $channel) => $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        app('Illuminate\Broadcasting\BroadcastManager')->purge('reverb');
        require base_path('routes/channels.php');

        $auth("private-chat.{$id}")->assertOk();
        $auth("private-user.{$this->pastor->id}")->assertOk();
        $auth("private-user.{$this->overseer->id}")->assertForbidden();

        Sanctum::actingAs($this->otherPastor);
        $auth("private-chat.{$id}")->assertForbidden();
    }
}
