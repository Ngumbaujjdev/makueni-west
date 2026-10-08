<?php

namespace Tests\Feature\Communications;

use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Permission;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Templates (docs/specs/messages-spec.md, L5c): the diocese shares, a church
 * copies with its name in for {sender}, edits its copy and resets it; the
 * preview is the real branded email; nobody edits another place's.
 */
class TemplatesTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private int $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBudgetWorld();
        $send = fn (string $level) => ["{$level}.messages.messages.read", "{$level}.messages.messages.send"];
        $this->give($this->pastor, $send('church'));
        $this->give($this->overseer, $send('region'));
        $this->give($this->bishop, $send('diocese'));

        Sanctum::actingAs($this->bishop);
        $this->shared = $this->postJson('/api/messages/templates', [
            'name' => 'Prayer request', 'channel' => 'both', 'subject' => 'Praying with you - {sender}',
            'body' => "Dear {name},\n\n{sender} is praying for you this week.", 'shared_below' => true,
        ])->assertCreated()->assertJsonPath('data.shared_below', true)->json('data.id');
        $this->postJson('/api/messages/templates', ['name' => 'Diocese only', 'channel' => 'sms', 'body' => 'For us'])->assertCreated();
    }

    private function give(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            $user->roles()->first()->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['action' => substr($name, strrpos($name, '.') + 1), 'territory_scope' => explode('.', $name)[0]]));
        }
    }

    public function test_a_church_sees_the_shared_ones_and_cannot_change_them(): void
    {
        Sanctum::actingAs($this->pastor);
        $rows = collect($this->getJson('/api/messages/templates')->assertOk()->json('data'));
        $this->assertSame(['Prayer request'], $rows->pluck('name')->all());
        $this->assertSame('shared', $rows[0]['source']);
        $this->assertSame('Test Diocese', $rows[0]['owner']['name']);
        $this->assertNull($rows[0]['our_copy_id']);

        $this->putJson("/api/messages/templates/{$this->shared}", ['name' => 'Mine', 'channel' => 'sms', 'body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/messages/templates/{$this->shared}")->assertNotFound();
        $this->assertSame('Prayer request', MessageTemplate::find($this->shared)->name);

        // A church has no places below to share with.
        $this->postJson('/api/messages/templates', ['name' => 'Ours', 'channel' => 'sms', 'body' => 'Hi', 'shared_below' => true])->assertCreated()->assertJsonPath('data.shared_below', false);

        // The composer offers the shared one too.
        $this->assertContains('Prayer request', array_column($this->getJson('/api/messages/options')->json('data.templates'), 'name'));
    }

    public function test_copy_puts_our_name_in_and_reset_brings_the_shared_text_back(): void
    {
        Sanctum::actingAs($this->pastor);
        $copy = $this->postJson("/api/messages/templates/{$this->shared}/copy")->assertCreated()->json('data');
        $this->assertSame('ours', $copy['source']);
        $this->assertSame("Dear {name},\n\nMy Church is praying for you this week.", $copy['body']);
        $this->assertSame('Praying with you - My Church', $copy['subject']);
        $this->assertSame($this->shared, $copy['copied_from']['id']);

        // Copying again gives the same copy back; the list links them.
        $this->postJson("/api/messages/templates/{$this->shared}/copy")->assertOk()->assertJsonPath('data.id', $copy['id']);
        $rows = collect($this->getJson('/api/messages/templates')->json('data'))->keyBy('id');
        $this->assertSame($copy['id'], $rows[$this->shared]['our_copy_id']);
        // The composer shows our copy, not the shared one twice.
        $this->assertSame([$copy['id']], collect($this->getJson('/api/messages/options')->json('data.templates'))->where('name', 'Prayer request')->pluck('id')->all());

        $this->putJson("/api/messages/templates/{$copy['id']}", ['name' => 'Prayer request', 'channel' => 'both', 'body' => 'Changed'])->assertOk();
        $this->postJson("/api/messages/templates/{$copy['id']}/reset")->assertOk()->assertJsonPath('data.body', "Dear {name},\n\nMy Church is praying for you this week.");
        // Only a copy can be reset.
        $own = $this->postJson('/api/messages/templates', ['name' => 'Ours', 'channel' => 'sms', 'body' => 'Hi'])->json('data.id');
        $this->postJson("/api/messages/templates/{$own}/reset")->assertNotFound();
    }

    public function test_the_preview_is_the_branded_email_and_the_signed_sms(): void
    {
        Sanctum::actingAs($this->pastor);
        app(Settings::class)->setMany($this->myChurch, 'church', 'communication', ['comms.sms_signature' => 'My Church'], [], [], $this->pastor);
        $data = $this->postJson('/api/messages/templates/preview', ['subject' => 'Hello {name}', 'body' => "Dear {name},\n\nSee you on Sunday.\n\n{sender}"])->assertOk()->json('data');

        $this->assertSame('Hello Stephen', $data['subject']);
        $this->assertStringContainsString('<h1', $data['html']);
        $this->assertStringContainsString('Hello Stephen', $data['html']);
        $this->assertStringContainsString('Sent by My Church through the Makueni West Diocese system.', $data['html']);
        $this->assertStringContainsString('See you on Sunday.', $data['html']);
        $this->assertSame("Dear Stephen,\n\nSee you on Sunday.\n\nMy Church\n- My Church", $data['sms']['text']);
        $this->assertSame(1, $data['sms']['parts']);
        $this->assertStringStartsWith('My Church <', $data['from']);
        $this->assertSame(0, MessageLog::count());
    }

    public function test_send_this_to_me_goes_to_my_own_contact(): void
    {
        $this->pastor->forceFill(['email' => 'pastor@example.test', 'phone' => null])->save();
        Sanctum::actingAs($this->pastor);
        $this->postJson('/api/messages/templates/test', ['channel' => 'email', 'subject' => 'Hi {name}', 'body' => 'Hello'])->assertOk()->assertJsonPath('data.to', 'pastor@example.test');
        $log = MessageLog::sole();
        $this->assertSame('pastor@example.test', $log->to);
        $this->assertSame('test', $log->kind);
        $this->assertStringStartsWith('[Test] Hi ', $log->subject);

        $this->postJson('/api/messages/templates/test', ['channel' => 'sms', 'body' => 'Hello'])->assertStatus(422)->assertJsonPath('message', 'Your profile has no phone number.');
    }

    public function test_places_see_only_their_own_line_above(): void
    {
        Sanctum::actingAs($this->overseer);
        $regionShared = $this->postJson('/api/messages/templates', ['name' => 'Region rally', 'channel' => 'sms', 'body' => 'Rally', 'shared_below' => true])->json('data.id');
        $this->assertEqualsCanonicalizing(['Prayer request', 'Region rally'], array_column($this->getJson('/api/messages/templates')->json('data'), 'name'));

        // Region A's church sees Region A's; Region B's church doesn't.
        Sanctum::actingAs($this->pastor);
        $this->assertContains('Region rally', array_column($this->getJson('/api/messages/templates')->json('data'), 'name'));
        $far = $this->userWithRole('farpastor', 'Senior Pastor', 'church', $this->farChurch->id, ['church.messages.messages.send']);
        Sanctum::actingAs($far);
        $this->assertSame(['Prayer request'], array_column($this->getJson('/api/messages/templates')->json('data'), 'name'));
        $this->postJson("/api/messages/templates/{$regionShared}/copy")->assertNotFound();

        // The diocese never sees a church's own.
        $this->postJson('/api/messages/templates', ['name' => 'Far only', 'channel' => 'sms', 'body' => 'x'])->assertCreated();
        Sanctum::actingAs($this->bishop);
        $this->assertNotContains('Far only', array_column($this->getJson('/api/messages/templates')->json('data'), 'name'));

        // Without send, no templates.
        $reader = $this->userWithRole('reader', 'Church Reader', 'church', $this->myChurch->id, ['church.messages.inbox.read']);
        Sanctum::actingAs($reader);
        $this->getJson('/api/messages/templates')->assertForbidden();
        $this->postJson('/api/messages/templates/preview', ['body' => 'x'])->assertForbidden();
    }
}
