<?php

namespace Tests\Feature\Settings;

use App\Models\MessageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Communication > Messages (docs/specs/settings-spec.md, S6c): every email
 * and SMS sent for a place, with a preview; a church sees its own, a region
 * its churches' too, the diocese everything.
 */
class SettingsMessagesTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
    }

    private function message(array $row): MessageLog
    {
        return MessageLog::create($row + ['channel' => 'sms', 'to' => '+254712345678', 'status' => 'sent', 'via' => 'diocese', 'kind' => 'test', 'body' => 'Hello', 'body_type' => 'text']);
    }

    public function test_each_level_sees_its_own_messages_and_those_below(): void
    {
        $mine = $this->message(['territory_id' => $this->myChurch->id]);
        $other = $this->message(['territory_id' => $this->otherChurch->id]);
        $region = $this->message(['territory_id' => $this->region->id]);
        $system = MessageLog::create(['channel' => 'mail', 'kind' => 'account', 'via' => 'system', 'to' => 'x@example.test', 'subject' => 'Reset your password', 'status' => 'sent']);
        $ids = fn ($user) => (Sanctum::actingAs($user) ? collect($this->getJson('/api/settings/messages')->assertOk()->json('data.rows'))->pluck('id')->sort()->values()->all() : []);

        $this->assertSame([$mine->id], $ids($this->pastor));
        $this->assertSame([$mine->id, $other->id, $region->id], $ids($this->overseer));
        $this->assertSame([$mine->id, $other->id, $region->id, $system->id], $ids($this->globalAdmin));

        Sanctum::actingAs($this->pastor);
        $this->getJson("/api/settings/messages/{$other->id}")->assertNotFound();
    }

    public function test_the_preview_has_the_masked_text_or_says_why_there_is_none(): void
    {
        $mail = MessageLog::create([
            'channel' => 'mail', 'kind' => 'sign_in_details', 'via' => 'diocese', 'to' => 'jane@example.test', 'from' => 'My Church <noreply@example.test>',
            'reply_to' => 'office@mychurch.test', 'subject' => 'Your sign-in details', 'body' => '<p>Password ••••</p>', 'body_type' => 'html',
            'status' => 'sent', 'territory_id' => $this->myChurch->id, 'sent_by' => $this->pastor->id,
        ]);
        Sanctum::actingAs($this->pastor);
        $d = $this->getJson("/api/settings/messages/{$mail->id}")->assertOk()->json('data');
        $this->assertSame(['<p>Password ••••</p>', 'html', 'office@mychurch.test', 'Test Pastor', 'email'], [$d['body'], $d['body_type'], $d['reply_to'], $d['by'], $d['channel']]);

        $row = collect($this->getJson('/api/settings/messages')->json('data.rows'))->firstWhere('id', $mail->id);
        $this->assertSame('Your sign-in details', $row['preview']);
        $this->assertArrayNotHasKey('body', $row, 'the list carries no message text');

        $system = MessageLog::create(['channel' => 'mail', 'kind' => 'account', 'via' => 'system', 'to' => 'x@example.test', 'status' => 'sent', 'meta' => ['body_not_kept' => 'Account emails can carry a private link.']]);
        Sanctum::actingAs($this->globalAdmin);
        $this->assertSame('Account emails can carry a private link.', $this->getJson("/api/settings/messages/{$system->id}")->json('data.body_note'));
    }

    public function test_a_failed_message_can_be_sent_again_but_not_sign_in_details(): void
    {
        $failed = $this->message(['territory_id' => $this->myChurch->id, 'status' => 'failed', 'error' => 'Gateway down', 'body' => "Service moved to 10am\n- My Church"]);
        $secret = $this->message(['territory_id' => $this->myChurch->id, 'status' => 'failed', 'kind' => 'sign_in_details']);
        $sent = $this->message(['territory_id' => $this->myChurch->id]);

        Sanctum::actingAs($this->secretary);
        $this->postJson("/api/settings/messages/{$failed->id}/resend")->assertForbidden();
        $this->assertFalse(collect($this->getJson('/api/settings/messages')->json('data.rows'))->firstWhere('id', $failed->id)['can_resend']);

        Sanctum::actingAs($this->pastor);
        $this->assertTrue(collect($this->getJson('/api/settings/messages')->json('data.rows'))->firstWhere('id', $failed->id)['can_resend']);
        $this->postJson("/api/settings/messages/{$failed->id}/resend")->assertOk()->assertJsonPath('data.status', 'logged');
        $this->assertSame("Service moved to 10am\n- My Church", MessageLog::latest('id')->value('body'), 'sent again as it was - no second signature');
        $this->postJson("/api/settings/messages/{$secret->id}/resend")->assertStatus(422);
        $this->postJson("/api/settings/messages/{$sent->id}/resend")->assertStatus(422);
    }

    public function test_old_message_text_is_cleared_but_the_row_stays(): void
    {
        $old = $this->message(['territory_id' => $this->myChurch->id]);
        $old->forceFill(['created_at' => now()->subDays(MessageLog::KEEP_BODY_DAYS + 1)])->save();
        $new = $this->message(['territory_id' => $this->myChurch->id]);

        Artisan::call('messages:prune-bodies');

        $this->assertNull($old->fresh()->body);
        $this->assertNotNull($old->fresh()->body_cleared_at);
        $this->assertSame('Hello', $new->fresh()->body);
        Sanctum::actingAs($this->pastor);
        $this->assertSame('The text was cleared after 90 days.', $this->getJson("/api/settings/messages/{$old->id}")->json('data.body_note'));
    }
}
