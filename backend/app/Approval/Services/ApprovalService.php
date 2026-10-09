<?php

namespace App\Approval\Services;

use App\Approval\Contracts\Approvable;
use App\Approval\Resolvers\Registry;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalDecision;
use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStage;
use App\Models\ApprovalWorkflow;
use App\Models\Territory;
use App\Models\User;
use App\Support\PlaceRoles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The approvals engine (docs/specs/accounting-spec.md, A4), ported from
 * erp-server and made for churches.
 *
 * A document is routed to the most specific active workflow whose
 * conditions pass (this kind of document before "any money document", this
 * level before every level, then priority, then the newest). Its stages are
 * copied onto the request, so changing a workflow never changes what's
 * already in flight. Each stage is opened in turn: its approvers are found
 * relative to the document's place, delegation applied, the person asking
 * removed; an empty stage is skipped or blocked as the stage says.
 *
 * A decision locks the request first, so two approvers deciding at the same
 * moment can't both complete a stage. Approve moves on; reject follows the
 * stage's rule (stop, go back a stage, or carry on); "return" sends it back
 * to the person asking for changes. The document is told the outcome in
 * the same transaction; people are told after it commits.
 */
final class ApprovalService
{
    public const ANY = '*';

    public function __construct(private ConditionEvaluator $conditions, private Registry $resolvers, private DelegationResolver $delegations, private Notices $notices) {}

    /** Route a document: its request, or null when no workflow applies (the document's own rule then). */
    public function route(Model&Approvable $subject, User $requester): ?ApprovalRequest
    {
        $place = $subject->approvalPlace();
        $context = $subject->approvalContext() + ['level' => $place->territory_type->value];
        $workflow = $this->findWorkflow($subject->getMorphClass(), $place, $context);

        return $workflow ? $this->submit($subject, $workflow, $context, $requester, $place) : null;
    }

    public function findWorkflow(string $subjectType, Territory $place, array $context): ?ApprovalWorkflow
    {
        $level = $place->territory_type->value;
        $candidates = ApprovalWorkflow::with('stages.steps')->where('is_active', true)
            ->whereIn('subject_type', [$subjectType, self::ANY])
            ->where(fn ($q) => $q->whereNull('level')->orWhere('level', $level))
            ->get()
            ->sort(fn ($a, $b) => [($a->subject_type === self::ANY), ($a->level === null), -$a->match_priority, -$a->id]
                <=> [($b->subject_type === self::ANY), ($b->level === null), -$b->match_priority, -$b->id]);
        foreach ($candidates as $w) {
            if ($this->conditions->passes($w->applies_when, $context)) {
                return $w;
            }
        }

        return null;
    }

    /** The document's live (pending) request, if any. */
    public function current(Model $subject): ?ApprovalRequest
    {
        return ApprovalRequest::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where('status', 'pending')->latest('id')->first();
    }

    /** The document's latest request, whatever its status. */
    public function latest(Model $subject): ?ApprovalRequest
    {
        return ApprovalRequest::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->latest('id')->first();
    }

    public function submit(Model&Approvable $subject, ApprovalWorkflow $workflow, array $context, User $requester, Territory $place): ApprovalRequest
    {
        return DB::transaction(function () use ($subject, $workflow, $context, $requester, $place) {
            if ($this->current($subject)) {
                throw ValidationException::withMessages(['approval' => ['It is already waiting for approval.']]);
            }
            $request = ApprovalRequest::create([
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'workflow_name' => $workflow->name,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'territory_id' => $place->id,
                'requested_by' => $requester->id,
                'context' => $context,
                'status' => 'pending',
                'current_stage_sequence' => 0,
            ]);
            foreach ($workflow->stages as $stage) {
                ApprovalRequestStage::create([
                    'request_id' => $request->id,
                    'stage_id' => $stage->id,
                    'sequence' => $stage->sequence,
                    'name' => $stage->name,
                    'type' => $stage->type,
                    'quorum' => $stage->quorum,
                    'on_reject' => $stage->on_reject,
                    'rule_snapshot' => [
                        'on_empty' => $stage->on_empty,
                        'sla_hours' => $stage->sla_hours,
                        'escalate_after_hours' => $stage->escalate_after_hours,
                        'escalate_to' => $stage->escalate_to,
                        'steps' => $stage->steps->map(fn ($s) => ['resolver_type' => $s->resolver_type, 'resolver_config' => $s->resolver_config ?? []])->values()->all(),
                    ],
                    'status' => 'pending',
                ]);
            }
            $this->event($request, 'submitted', ['workflow' => $workflow->name], $requester->id);
            $this->activateNext($request, null, null);

            return $request->fresh();
        });
    }

    public function approve(ApprovalAssignment $a, User $actor, ?string $comment = null): ApprovalRequest
    {
        return $this->decide($a, $actor, 'approve', $comment);
    }

    public function reject(ApprovalAssignment $a, User $actor, string $comment): ApprovalRequest
    {
        return $this->decide($a, $actor, 'reject', $comment);
    }

    /** Back to the person asking, for changes; they fix it and send it again. */
    public function sendBack(ApprovalAssignment $a, User $actor, string $comment): ApprovalRequest
    {
        return $this->decide($a, $actor, 'return', $comment);
    }

    public function decide(ApprovalAssignment $assignment, User $actor, string $decision, ?string $comment): ApprovalRequest
    {
        $comment = $comment !== null ? trim($comment) : null;
        if (in_array($decision, ['reject', 'return'], true) && ! $comment) {
            throw ValidationException::withMessages(['comment' => [$decision === 'reject' ? 'Say why it is rejected.' : 'Say what needs changing.']]);
        }

        return DB::transaction(function () use ($assignment, $actor, $decision, $comment) {
            // Lock the request first: two people deciding at once can't both finish a stage.
            $request = ApprovalRequest::whereKey($assignment->request_id)->lockForUpdate()->firstOrFail();
            $a = ApprovalAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => ['It has already been decided.']]);
            }
            if ($a->status !== 'pending' || $a->superseded_at) {
                throw ValidationException::withMessages(['approval' => ['You have already acted on it, or it was passed to someone else.']]);
            }
            if ((int) $a->approver_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['approval' => ['This isn\'t yours to approve.']]);
            }
            if ((int) $request->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approval' => ['You asked for it - someone else must approve it.']]);
            }
            ApprovalDecision::create(['assignment_id' => $a->id, 'actor_id' => $actor->id, 'decision' => $decision, 'comment' => $comment ?: null]);
            $a->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$decision]]);
            $this->event($request, 'decided', ['assignment_id' => $a->id, 'decision' => $decision, 'comment' => $comment], $actor->id);

            $stage = ApprovalRequestStage::whereKey($a->request_stage_id)->firstOrFail();
            if ($stage->status !== 'active') {
                return $request->fresh();
            }
            if ($decision === 'return') {
                $this->completeStage($stage, 'rejected');
                $this->finish($request, 'returned', $actor, $comment);

                return $request->fresh();
            }
            $outcome = $this->stageOutcome($stage);
            if ($outcome === null) {
                return $request->fresh();
            }
            $this->completeStage($stage, $outcome);
            $outcome === 'approved' ? $this->activateNext($request, $actor, $comment) : $this->handleReject($request, $stage, $actor, $comment);

            return $request->fresh();
        });
    }

    /** Withdraw it (the person asking, or the document being cancelled). */
    public function cancel(ApprovalRequest $request, ?User $actor): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                return $request;
            }
            foreach ($request->stages()->whereIn('status', ['active', 'blocked'])->get() as $stage) {
                $this->completeStage($stage, 'skipped');
            }
            $this->finish($request, 'cancelled', $actor, null);

            return $request->fresh();
        });
    }

    /** Try a blocked stage again (after someone was given the role). */
    public function retryStage(ApprovalRequestStage $stage): bool
    {
        return DB::transaction(function () use ($stage) {
            $request = ApprovalRequest::whereKey($stage->request_id)->lockForUpdate()->firstOrFail();
            $stage = ApprovalRequestStage::whereKey($stage->id)->firstOrFail();
            if ($request->status !== 'pending' || $stage->status !== 'blocked') {
                return false;
            }
            $stage->update(['status' => 'pending', 'blocked_reason' => null]);
            $this->activate($request, $stage);

            return true;
        });
    }

    // ------------------------------------------------------------------ the flow

    private function activateNext(ApprovalRequest $request, ?User $by, ?string $comment): void
    {
        $next = $request->stages()->where('sequence', '>', $request->current_stage_sequence)->where('status', 'pending')->orderBy('sequence')->first();
        if (! $next) {
            $this->finish($request, 'approved', $by, $comment);

            return;
        }
        $request->update(['current_stage_sequence' => $next->sequence]);
        $this->activate($request, $next, $by, $comment);
    }

    private function activate(ApprovalRequest $request, ApprovalRequestStage $stage, ?User $by = null, ?string $comment = null): void
    {
        $snap = $stage->rule_snapshot ?? [];
        try {
            $people = $this->resolveSteps($snap['steps'] ?? [], $request);
        } catch (InvalidArgumentException $e) {
            $this->block($request, $stage, $e->getMessage());

            return;
        }
        // Delegation, then never the person asking.
        $acting = [];
        foreach ($people as $user) {
            [$who, $for] = $this->delegations->resolve($user, $request);
            if ((int) $who->id === (int) $request->requested_by) {
                continue;
            }
            $acting[$who->id] ??= ['user' => $who, 'for' => $for];
        }
        // Nobody left (e.g. the pastor asked and the pastor approves): straight to the escalation people.
        if (! $acting && ($snap['on_empty'] ?? 'block') === 'escalate' && ! empty($snap['escalate_to']['resolver_type'])) {
            try {
                foreach ($this->resolveSteps([$snap['escalate_to']], $request) as $user) {
                    [$who, $for] = $this->delegations->resolve($user, $request);
                    if ((int) $who->id !== (int) $request->requested_by) {
                        $acting[$who->id] ??= ['user' => $who, 'for' => $for];
                    }
                }
            } catch (InvalidArgumentException) {
                // Falls through to blocked below.
            }
            if ($acting) {
                $this->event($request, 'stage_escalated_empty', ['stage' => $stage->name]);
            }
        }
        if (! $acting) {
            if (($snap['on_empty'] ?? 'block') === 'skip') {
                $this->completeStage($stage, 'skipped');
                $this->event($request, 'stage_skipped', ['stage' => $stage->name]);
                $this->activateNext($request, $by, $comment);

                return;
            }
            $this->block($request, $stage, 'Nobody holds the role for this stage.');

            return;
        }
        if ($stage->type === 'quorum' && count($acting) < max(1, (int) $stage->quorum)) {
            $this->block($request, $stage, 'Fewer people hold the role than need to approve.');

            return;
        }
        $due = ! empty($snap['sla_hours']) ? now()->addHours((int) $snap['sla_hours']) : null;
        $stage->update(['status' => 'active', 'activated_at' => now()]);
        foreach ($acting as $p) {
            ApprovalAssignment::create([
                'request_id' => $request->id, 'request_stage_id' => $stage->id, 'approver_id' => $p['user']->id,
                'resolver_type' => 'stage', 'delegated_from' => $p['for'], 'status' => 'pending', 'due_at' => $due,
            ]);
            $this->notices->send($p['user'], 'assigned', $request);
        }
        $this->event($request, 'stage_opened', ['stage' => $stage->name, 'approvers' => array_map(fn ($p) => $p['user']->id, array_values($acting))]);
    }

    /** @return Collection<int, User> keyed by id */
    public function resolveSteps(array $steps, ApprovalRequest $request): Collection
    {
        $people = collect();
        foreach ($steps as $step) {
            foreach ($this->resolvers->get($step['resolver_type'] ?? '')->resolve($step['resolver_config'] ?? [], $request) as $u) {
                $people->put($u->id, $u);
            }
        }

        return $people;
    }

    private function block(ApprovalRequest $request, ApprovalRequestStage $stage, string $why): void
    {
        $stage->update(['status' => 'blocked', 'blocked_reason' => $why]);
        $this->event($request, 'stage_blocked', ['stage' => $stage->name, 'reason' => $why]);
        // Whoever looks after the rules: the diocese finance officer(s).
        $diocese = Territory::where('territory_type', 'diocese')->orderBy('id')->first();
        foreach ($diocese ? PlaceRoles::holders($diocese, ['Diocese Finance Officer']) : [] as $u) {
            $this->notices->send($u, 'blocked', $request, ['stage' => $stage->name]);
        }
    }

    /** null while undecided; approved | rejected once it is. Superseded and skipped people don't count. */
    private function stageOutcome(ApprovalRequestStage $stage): ?string
    {
        $live = ApprovalAssignment::where('request_stage_id', $stage->id)->whereNull('superseded_at')->whereNotIn('status', ['superseded', 'skipped'])->get();
        $approved = $live->where('status', 'approved')->count();
        $rejected = $live->where('status', 'rejected')->count();
        $pending = $live->where('status', 'pending')->count();

        return match ($stage->type) {
            'all' => $rejected > 0 ? 'rejected' : ($pending === 0 ? 'approved' : null),
            'quorum' => $approved >= max(1, (int) $stage->quorum) ? 'approved' : ($approved + $pending < max(1, (int) $stage->quorum) ? 'rejected' : null),
            default => $approved > 0 ? 'approved' : ($pending === 0 ? 'rejected' : null),
        };
    }

    private function completeStage(ApprovalRequestStage $stage, string $status): void
    {
        $stage->update(['status' => $status, 'completed_at' => now()]);
        ApprovalAssignment::where('request_stage_id', $stage->id)->where('status', 'pending')->update(['status' => 'skipped']);
    }

    private function handleReject(ApprovalRequest $request, ApprovalRequestStage $stage, User $actor, ?string $comment): void
    {
        if ($stage->on_reject === 'continue') {
            $this->activateNext($request, $actor, $comment);

            return;
        }
        if ($stage->on_reject === 'return_previous') {
            $prev = $request->stages()->where('sequence', '<', $stage->sequence)->where('status', 'approved')->orderByDesc('sequence')->first();
            if ($prev) {
                foreach ([$prev, $stage] as $s) {
                    ApprovalAssignment::where('request_stage_id', $s->id)->whereNull('superseded_at')->update(['superseded_at' => now()]);
                    $s->update(['status' => 'pending', 'completed_at' => null]);
                }
                $request->update(['current_stage_sequence' => $prev->sequence - 1]);
                $this->event($request, 'returned_to_stage', ['stage' => $prev->name], $actor->id);
                $this->activateNext($request, $actor, $comment);

                return;
            }
        }
        $this->finish($request, 'rejected', $actor, $comment);
    }

    /** The end: the document is told now, the person asking once it's saved. */
    private function finish(ApprovalRequest $request, string $status, ?User $by, ?string $comment): void
    {
        $request->update(['status' => $status, 'completed_at' => now()]);
        $this->event($request, $status, ['comment' => $comment], $by?->id);
        $subject = $request->subject()->first();
        if ($subject instanceof Approvable) {
            $subject->approvalOutcome($request, $status, $by, $comment);
        }
        $requester = $request->requested_by ? User::find($request->requested_by) : null;
        if ($requester && (! $by || $requester->id !== $by->id || $status !== 'cancelled')) {
            $this->notices->send($requester, $status, $request, ['comment' => $comment]);
        }
        if ($status === 'cancelled') {
            $ids = ApprovalAssignment::where('request_id', $request->id)->where('status', 'skipped')->pluck('approver_id')->unique();
            foreach (User::whereIn('id', $ids)->get() as $u) {
                $this->notices->send($u, 'cancelled', $request);
            }
        }
    }

    public function event(ApprovalRequest $request, string $type, array $payload = [], ?int $actorId = null): void
    {
        ApprovalEvent::create(['request_id' => $request->id, 'type' => $type, 'payload' => $payload ?: null, 'actor_id' => $actorId]);
    }
}
