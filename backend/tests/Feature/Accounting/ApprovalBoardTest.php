<?php

namespace Tests\Feature\Accounting;

use App\Approval\Services\WorkflowBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Approvals board (docs/specs/accounting-spec.md, Redesign R2): every
 * request waiting at the place and who holds it, this month's numbers and the
 * latest happenings - for whoever reads the place's books.
 */
class ApprovalBoardTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $dfo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'below', 'rules', 'approvals']));
        app(WorkflowBuilder::class)->save(['name' => 'Pastor', 'subject_type' => '*', 'level' => 'church', 'stages' => [['name' => 'Pastor', 'type' => 'single', 'on_empty' => 'block', 'steps' => [['resolver_type' => 'role_here', 'resolver_config' => ['roles' => ['Senior Pastor']]]]]]], null, $this->dfo);
    }

    private function ask(float $amount): int
    {
        Sanctum::actingAs($this->treasurer);

        return $this->postJson('/api/accounting/requisitions', ['kind' => 'payment', 'purpose' => 'Chairs', 'amount' => $amount, 'account_id' => $this->acc('5310')->id])->assertCreated()->json('data.id');
    }

    public function test_the_board_shows_what_waits_who_holds_it_and_what_happened(): void
    {
        $first = $this->ask(4000);
        $second = $this->ask(6000);
        Sanctum::actingAs($this->authoriser);
        $req = $this->getJson('/api/approvals?tab=waiting')->json('data.items.0.id');
        $this->postJson("/api/approvals/requests/{$req}/approve")->assertOk();

        $board = $this->getJson('/api/approvals/board')->assertOk()->json('data');
        $this->assertCount(1, $board['open']);
        $this->assertSame('requisition', $board['open'][0]['record']['type']);
        $this->assertContains($board['open'][0]['record']['id'], [$first, $second]);
        $this->assertSame([$this->authoriser->full_name], $board['open'][0]['waiting_on']);
        $this->assertSame(1, $board['stats']['waiting']);
        $this->assertSame(1, $board['stats']['mine'], 'one waits on the pastor');
        $this->assertSame(1, $board['stats']['approved']);
        $this->assertSame('Approved', $board['activity'][0]['text']);
        $this->assertSame($this->authoriser->full_name, $board['activity'][0]['who']);
    }

    public function test_another_church_cannot_see_the_board(): void
    {
        $this->ask(4000);
        Sanctum::actingAs($this->userWithRole('othertr2', 'Church Treasurer', 'church', $this->otherChurch->id, $this->perms('church', ['read'])));
        $this->getJson('/api/approvals/board?territory_id='.$this->myChurch->id)->assertForbidden();
        $this->assertSame([], $this->getJson('/api/approvals/board')->assertOk()->json('data.open'));
    }
}
