<?php

namespace Tests\Feature\Communications;

use App\Models\MessageLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Communication > Messages > Message log (docs/specs/messages-spec.md): every
 * email and SMS sent, for those who read messages - no Settings rights
 * needed. A church sees its own, a region its churches' too, the diocese all.
 */
class MessageLogTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $this->give($this->pastor, ['church.messages.messages.read', 'church.messages.messages.send', 'church.messages.inbox.read']);
        $this->give($this->overseer, ['region.messages.messages.read', 'region.messages.inbox.read']);
        $this->give($this->bishop, ['diocese.messages.messages.read', 'diocese.messages.inbox.read']);
        foreach ([[$this->myChurch, 'to-mine@example.test'], [$this->otherChurch, 'to-other@example.test'], [$this->farChurch, 'to-far@example.test']] as [$place, $to]) {
            MessageLog::create(['channel' => 'mail', 'to' => $to, 'status' => 'failed', 'error' => 'No server', 'territory_id' => $place->id, 'kind' => 'broadcast', 'via' => 'diocese', 'subject' => 'Hello', 'body' => '<p>Hello</p>', 'body_type' => 'html']);
        }
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => explode('.', $name)[0]]));
        }
    }

    private function seen(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/messages/log')->assertOk()->json('data.rows'))->pluck('to')->sort()->values()->all();
    }

    public function test_each_level_sees_its_own_line(): void
    {
        $this->assertSame(['to-mine@example.test'], $this->seen($this->pastor));
        $this->assertSame(['to-mine@example.test', 'to-other@example.test'], $this->seen($this->overseer));
        $this->assertSame(['to-far@example.test', 'to-mine@example.test', 'to-other@example.test'], $this->seen($this->bishop));
    }

    public function test_one_message_and_resend_need_the_right_role(): void
    {
        $mine = MessageLog::where('to', 'to-mine@example.test')->value('id');
        $other = MessageLog::where('to', 'to-other@example.test')->value('id');

        Sanctum::actingAs($this->pastor);
        $this->getJson("/api/messages/log/{$mine}")->assertOk()->assertJsonPath('data.body', '<p>Hello</p>')->assertJsonPath('data.can_resend', true);
        $this->getJson("/api/messages/log/{$other}")->assertNotFound();

        // A reader who doesn't send can look, not resend.
        Sanctum::actingAs($this->overseer);
        $this->getJson("/api/messages/log/{$other}")->assertOk()->assertJsonPath('data.can_resend', false);
        $this->postJson("/api/messages/log/{$other}/resend")->assertForbidden();

        // Only the Inbox: no log.
        $member = $this->userWithRole('member', 'Church Member', 'church', $this->myChurch->id, ['church.messages.inbox.read']);
        Sanctum::actingAs($member);
        $this->getJson('/api/messages/log')->assertForbidden();
    }
}
