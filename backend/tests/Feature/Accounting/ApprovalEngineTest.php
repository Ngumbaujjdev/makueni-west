<?php

namespace Tests\Feature\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\ConditionEvaluator;
use App\Approval\Services\EscalationService;
use App\Approval\Services\WorkflowBuilder;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\PlaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The approvals engine (docs/specs/accounting-spec.md, A4): routing by level
 * and amount, stages and their kinds, nobody approving their own, empty
 * stages, delegation, reminders and escalation, deciding once, the strict
 * conditions - and the people told only once it's saved.
 */
class ApprovalEngineTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $committee;

    private User $overseerR;

    private User $dfo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        $this->committee = $this->userWithRole('committee', 'Church Committee Member', 'church', $this->myChurch->id, $this->perms('church', ['read', 'approvals']));
        $this->overseerR = $this->userWithRole('regover', 'Regional Overseer', 'region', $this->region->id, $this->perms('region', ['read', 'below', 'approvals']));
        $this->dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'below', 'rules', 'approvals']));
        Notification::fake();
    }

    private function rule(array $stages, array $over = []): \App\Models\ApprovalWorkflow
    {
        return app(WorkflowBuilder::class)->save(array_replace(['name' => 'Rule', 'subject_type' => '*', 'level' => 'church', 'stages' => $stages], $over), null, $this->dfo);
    }

    private function stage(string $name, array $roles, array $over = []): array
    {
        return array_replace(['name' => $name, 'type' => 'single', 'on_empty' => 'block', 'steps' => [['resolver_type' => 'role_here', 'resolver_config' => ['roles' => $roles]]]], $over);
    }

    private function ask(float $amount, ?User $as = null): Requisition
    {
        Sanctum::actingAs($as ?? $this->treasurer);
        $id = $this->postJson('/api/accounting/requisitions', ['kind' => 'payment', 'purpose' => 'Chairs', 'amount' => $amount, 'account_id' => $this->acc('5310')->id])->assertCreated()->json('data.id');

        return Requisition::find($id);
    }

    private function turn(User $u, Requisition $r): ?ApprovalAssignment
    {
        $req = app(ApprovalService::class)->latest($r);

        return ApprovalAssignment::where('request_id', $req->id)->where('approver_id', $u->id)->where('status', 'pending')->first();
    }

    public function test_a_single_stage_is_approved_by_the_pastor_and_the_document_is_told(): void
    {
        $this->rule([$this->stage('Pastor', ['Senior Pastor'])]);
        $r = $this->ask(20000);
        $this->assertSame('submitted', $r->status);
        Notification::assertSentTo($this->authoriser, PlaceNotification::class);
        Notification::assertNotSentTo($this->committee, PlaceNotification::class);

        Sanctum::actingAs($this->authoriser);
        $this->getJson('/api/approvals?tab=waiting')->assertOk()->assertJsonPath('data.items.0.subject.number', $r->number)->assertJsonPath('data.counts.waiting', 1);
        $req = app(ApprovalService::class)->latest($r);
        $this->postJson("/api/approvals/requests/{$req->id}/approve")->assertOk();
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame($this->authoriser->id, $r->fresh()->decided_by);
        Notification::assertSentTo($this->treasurer, PlaceNotification::class, fn ($n) => str_contains($n->toArray($this->treasurer)['title'], 'Approved'));
    }

    public function test_two_stages_in_turn_a_no_stops_it_and_return_sends_it_back_for_changes(): void
    {
        $this->rule([$this->stage('Pastor', ['Senior Pastor']), $this->stage('Committee', ['Church Committee Member'])]);
        $r = $this->ask(80000);
        $this->assertNull($this->turn($this->committee, $r), 'the committee waits for the pastor');
        app(ApprovalService::class)->approve($this->turn($this->authoriser, $r), $this->authoriser);
        $this->assertSame('submitted', $r->fresh()->status);
        try {
            app(ApprovalService::class)->reject($this->turn($this->committee, $r), $this->committee, '');
            $this->fail('a no needs a reason');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        app(ApprovalService::class)->sendBack($this->turn($this->committee, $r), $this->committee, 'Get a second quote');
        $this->assertSame('returned', $r->fresh()->status);
        $this->assertSame('Get a second quote', $r->fresh()->decision_note);

        // Fixed and sent again: a new request, from the start.
        Sanctum::actingAs($this->treasurer);
        $this->putJson("/api/accounting/requisitions/{$r->id}", ['kind' => 'payment', 'purpose' => 'Chairs (2 quotes)', 'amount' => 78000, 'account_id' => $this->acc('5310')->id])->assertOk();
        $this->assertNotNull($this->turn($this->authoriser, $r->fresh()));
        $this->assertSame(2, ApprovalRequest::where('subject_id', $r->id)->where('subject_type', 'requisition')->count());
        app(ApprovalService::class)->approve($this->turn($this->authoriser, $r), $this->authoriser);
        app(ApprovalService::class)->reject($this->turn($this->committee, $r), $this->committee, 'Not this year');
        $this->assertSame('rejected', $r->fresh()->status, 'a no stops it');
    }

    public function test_nobody_approves_their_own_and_an_empty_stage_escalates_or_blocks(): void
    {
        $up = ['resolver_type' => 'role_above', 'resolver_config' => ['roles' => ['Regional Overseer'], 'level' => 'region']];
        $this->rule([$this->stage('Pastor', ['Senior Pastor'], ['on_empty' => 'escalate', 'escalate_to' => $up])]);
        $this->authoriser->roles()->first()->givePermissionTo(\App\Models\Permission::firstOrCreate(['name' => 'church.accounting.requisitions.create', 'guard_name' => 'web'], ['action' => 'create', 'territory_scope' => 'church']));
        $r = $this->ask(10000, $this->authoriser);
        $this->assertNull($this->turn($this->authoriser, $r), 'the pastor never approves the pastor');
        $this->assertNotNull($this->turn($this->overseerR, $r), 'it went to the overseer above');

        // A stage with nobody, set to block, waits - and alerts the finance officer.
        \App\Models\ApprovalWorkflow::query()->delete();
        \App\Models\Role::firstOrCreate(['name' => 'Church Elder Test', 'guard_name' => 'web'], ['territory_level' => 'church']);
        $this->rule([$this->stage('Elder', ['Church Elder Test'])]);
        $r2 = $this->ask(5000);
        $req = app(ApprovalService::class)->latest($r2);
        $this->assertSame('blocked', $req->stages()->first()->status);
        Notification::assertSentTo($this->dfo, PlaceNotification::class, fn ($n) => str_contains($n->toArray($this->dfo)['title'], 'Nobody to approve'));
        $this->userWithRole('elderx', 'Church Elder Test', 'church', $this->myChurch->id, []);
        Sanctum::actingAs($this->dfo);
        $this->postJson("/api/approvals/requests/{$req->id}/retry")->assertOk();
        $this->assertSame('active', $req->stages()->first()->fresh()->status);
    }

    public function test_all_must_approve_and_two_deciding_at_once_finish_a_stage_only_once(): void
    {
        $second = $this->userWithRole('committee2', 'Church Committee Member', 'church', $this->myChurch->id, []);
        $this->rule([$this->stage('Committee', ['Church Committee Member'], ['type' => 'all'])]);
        $r = $this->ask(30000);
        $engine = app(ApprovalService::class);
        $engine->approve($this->turn($this->committee, $r), $this->committee);
        $this->assertSame('submitted', $r->fresh()->status, 'one of two is not enough');
        $engine->approve($this->turn($second, $r), $second);
        $this->assertSame('approved', $r->fresh()->status);

        // Any one of two: the second is too late, never a second completion.
        \App\Models\ApprovalWorkflow::query()->delete();
        $this->rule([$this->stage('Committee', ['Church Committee Member'])]);
        $r2 = $this->ask(30000);
        $late = $this->turn($second, $r2);
        $engine->approve($this->turn($this->committee, $r2), $this->committee);
        $this->expectException(ValidationException::class);
        $engine->approve($late, $second);
    }

    public function test_a_delegate_approves_while_the_pastor_is_away_but_never_for_the_person_asking(): void
    {
        $this->rule([$this->stage('Pastor', ['Senior Pastor'])]);
        ApprovalDelegation::create(['delegator_id' => $this->authoriser->id, 'delegate_id' => $this->committee->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        $r = $this->ask(10000);
        $this->assertNull($this->turn($this->authoriser, $r));
        $this->assertSame($this->authoriser->id, $this->turn($this->committee, $r)->delegated_from);

        ApprovalDelegation::query()->update(['delegate_id' => $this->treasurer->id]);
        $r2 = $this->ask(9000);
        $this->assertNotNull($this->turn($this->authoriser, $r2), 'never handed to the person asking');

        ApprovalDelegation::query()->update(['delegate_id' => $this->committee->id, 'ends_at' => now()->subHour()]);
        $r3 = $this->ask(8000);
        $this->assertNotNull($this->turn($this->authoriser, $r3), 'an expired delegation is ignored');
    }

    public function test_late_approvals_are_reminded_once_then_passed_up(): void
    {
        $up = ['resolver_type' => 'role_above', 'resolver_config' => ['roles' => ['Regional Overseer'], 'level' => 'region']];
        $this->rule([$this->stage('Pastor', ['Senior Pastor'], ['sla_hours' => 48, 'escalate_after_hours' => 24, 'escalate_to' => $up, 'type' => 'all'])]);
        $r = $this->ask(10000);
        $esc = app(EscalationService::class);
        $this->travel(49)->hours();
        $this->assertSame(['reminded' => 1, 'escalated' => 0], $esc->run());
        $this->assertSame(['reminded' => 0, 'escalated' => 0], $esc->run(), 'reminded once');
        $this->travel(24)->hours();
        $this->assertSame(1, $esc->run()['escalated']);
        $this->assertSame('superseded', ApprovalAssignment::where('approver_id', $this->authoriser->id)->value('status'), 'on "everyone" stages the late one is replaced');
        app(ApprovalService::class)->approve($this->turn($this->overseerR, $r), $this->overseerR);
        $this->assertSame('approved', $r->fresh()->status);
    }

    public function test_the_most_specific_rule_wins_and_conditions_are_strict(): void
    {
        $this->rule([$this->stage('Anyone', ['Church Committee Member'])], ['level' => null, 'name' => 'All levels']);
        $this->rule([$this->stage('Pastor', ['Senior Pastor'])], ['amount_max' => 50000, 'name' => 'Church small']);
        $this->rule([$this->stage('Committee', ['Church Committee Member'])], ['amount_min' => 50000, 'name' => 'Church big']);
        $this->assertSame('Church small', app(ApprovalService::class)->latest($this->ask(50000))->workflow_name);
        $this->assertSame('Church big', app(ApprovalService::class)->latest($this->ask(50001))->workflow_name);

        $c = app(ConditionEvaluator::class);
        $small = ['field' => 'amount', 'operator' => '<=', 'value' => 50000];
        $this->assertFalse($c->passes($small, []), 'a missing amount is not small');
        $this->assertFalse($c->passes($small, ['amount' => 'lots']));
        $this->assertTrue($c->passes($small, ['amount' => '50000.00']));
        $this->assertFalse($c->passes(['field' => 'kind', 'operator' => 'in', 'value' => [0]], ['kind' => 'advance']));
    }

    public function test_a_payment_voucher_is_routed_and_authorised_by_its_approver_only(): void
    {
        $this->rule([$this->stage('Pastor', ['Senior Pastor'])]);
        $this->receive(20000);
        Sanctum::actingAs($this->treasurer);
        $pv = $this->postJson('/api/accounting/payment-vouchers', ['date' => $this->day(), 'payee_name' => 'KPLC', 'pay_from_account_id' => $this->cash()->id, 'narration' => 'Power', 'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 3000]]])
            ->assertCreated()->assertJsonPath('data.approval.status', 'pending')->json('data');
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertForbidden();
        Sanctum::actingAs($this->regionReader);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertForbidden();
        Sanctum::actingAs($this->authoriser);
        $this->getJson("/api/accounting/payment-vouchers/{$pv['id']}")->assertJsonPath('data.can.authorise_this', true);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertOk()->assertJsonPath('data.status', 'authorised')->assertJsonPath('data.authorised_by', $this->authoriser->full_name);
    }
}
