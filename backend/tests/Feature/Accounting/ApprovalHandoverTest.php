<?php

namespace Tests\Feature\Accounting;

use App\Approval\Services\DelegationResolver;
use App\Approval\Services\WorkflowBuilder;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Handing approvals over while away (docs/specs/accounting-spec.md, A4): only
 * to someone at the same place, or the roles the rules pass work up to at
 * the place just above - never a pastor of another church (who sits at the
 * diocese as a council member); a hand-over that isn't allowed is ignored;
 * and whoever sends a document is told who it waits for.
 */
class ApprovalHandoverTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $associate;

    private User $otherPastor;

    private User $councillor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        $this->associate = $this->userWithRole('assoc', 'Associate Pastor', 'church', $this->myChurch->id, $this->perms('church', ['read', 'authorise', 'approvals']));
        $this->overseer = $this->userWithRole('overseer', 'Regional Overseer', 'region', $this->region->id, $this->perms('region', ['read', 'below', 'approvals']));
        $this->otherPastor = $this->userWithRole('otherpastor', 'Senior Pastor', 'church', $this->otherChurch->id, $this->perms('church', ['read', 'authorise', 'approvals']));
        // A pastor of another church who also sits on the diocese council - the one who leaked into the list.
        $this->councillor = $this->userWithRole('councillor', 'Diocese Council Member', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'approvals']));
        $dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'below', 'rules', 'approvals']));
        app(WorkflowBuilder::class)->save(['name' => 'Church', 'subject_type' => '*', 'level' => 'church', 'stages' => [
            ['name' => 'Pastor', 'type' => 'single', 'on_empty' => 'escalate', 'steps' => [['resolver_type' => 'role_here', 'resolver_config' => ['roles' => ['Senior Pastor']]]],
                'escalate_to' => ['resolver_type' => 'role_above', 'resolver_config' => ['roles' => ['Regional Overseer'], 'level' => 'region']]],
        ]], null, $dfo);
    }

    public function test_only_people_at_the_church_or_the_overseer_above_are_offered(): void
    {
        Sanctum::actingAs($this->authoriser);
        $people = collect($this->getJson('/api/approvals/delegations')->assertOk()->json('data.people'));
        $this->assertTrue($people->pluck('id')->contains($this->associate->id));
        $this->assertTrue($people->pluck('id')->contains($this->overseer->id), 'the Regional Overseer the rules pass work up to');
        $this->assertFalse($people->pluck('id')->contains($this->otherPastor->id), 'never a pastor of another church');
        $this->assertFalse($people->pluck('id')->contains($this->councillor->id), 'nor a diocese council member');
        $this->assertSame(['Associate Pastor', 'My Church', true], [$people->firstWhere('id', $this->associate->id)['role'], $people->firstWhere('id', $this->associate->id)['place'], $people->firstWhere('id', $this->associate->id)['same_place']]);

        $this->postJson('/api/approvals/delegations', ['delegate_id' => $this->councillor->id, 'starts_at' => now()->toDateString(), 'ends_at' => now()->addDays(3)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('delegate_id');
        $this->postJson('/api/approvals/delegations', ['delegate_id' => $this->associate->id, 'starts_at' => now()->toDateString(), 'ends_at' => now()->addDays(3)->toDateString()])->assertCreated();
    }

    public function test_a_hand_over_across_to_another_church_is_ignored(): void
    {
        ApprovalDelegation::create(['delegator_id' => $this->authoriser->id, 'delegate_id' => $this->otherPastor->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        $request = new ApprovalRequest(['territory_id' => $this->myChurch->id, 'subject_type' => 'payment_voucher', 'requested_by' => $this->treasurer->id]);
        [$who, $for] = app(DelegationResolver::class)->resolve($this->authoriser, $request);
        $this->assertSame([$this->authoriser->id, null], [$who->id, $for]);

        ApprovalDelegation::query()->update(['delegate_id' => $this->associate->id]);
        [$who, $for] = app(DelegationResolver::class)->resolve($this->authoriser, $request);
        $this->assertSame([$this->associate->id, $this->authoriser->id], [$who->id, $for]);
    }

    public function test_the_sender_is_told_who_it_waits_for(): void
    {
        Sanctum::actingAs($this->treasurer);
        $message = $this->postJson('/api/accounting/requisitions', ['kind' => 'payment', 'purpose' => 'Chairs', 'amount' => 5000, 'account_id' => $this->acc('5310')->id])->assertCreated()->json('message');
        $this->assertStringContainsString('waiting for', $message);
        $this->assertStringContainsString('(Senior Pastor)', $message);
    }
}
