<?php

namespace App\Approval\Services;

use App\Models\ApprovalAssignment;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Models\User;
use App\Support\AccountingAccess;
use Illuminate\Support\Collection;

/**
 * The Approvals page (docs/specs/accounting-spec.md, A4): what waits for me,
 * what I asked for, what I decided - and one request with its document and
 * the timeline of its stages.
 */
final class Inbox
{
    /** @return Collection<int, ApprovalRequest> */
    public function list(User $user, string $tab): Collection
    {
        $q = ApprovalRequest::with(['subject', 'territory', 'requester', 'stages.assignments.approver']);
        $q = match ($tab) {
            'mine' => $q->where('requested_by', $user->id),
            'decided' => $q->whereIn('id', ApprovalAssignment::whereIn('id', ApprovalDecision::where('actor_id', $user->id)->pluck('assignment_id'))->pluck('request_id')),
            default => $q->where('status', 'pending')->whereIn('id', ApprovalAssignment::where('approver_id', $user->id)->where('status', 'pending')->whereNull('superseded_at')->pluck('request_id')),
        };

        return $q->orderByDesc('id')->limit(300)->get()->filter(fn ($r) => $r->subject !== null)->values();
    }

    public function counts(User $user): array
    {
        return [
            'waiting' => ApprovalRequest::where('status', 'pending')->whereIn('id', ApprovalAssignment::where('approver_id', $user->id)->where('status', 'pending')->whereNull('superseded_at')->pluck('request_id'))->count(),
            'mine' => ApprovalRequest::where('requested_by', $user->id)->where('status', 'pending')->count(),
        ];
    }

    /** Who may look at it: whoever asked, anyone assigned, and those who read the place's books. */
    public function canView(?User $user, ApprovalRequest $r): bool
    {
        if (! $user) {
            return false;
        }
        if ((int) $r->requested_by === (int) $user->id || ApprovalAssignment::where('request_id', $r->id)->where('approver_id', $user->id)->exists()) {
            return true;
        }
        $place = Territory::find($r->territory_id);

        return $place && AccountingAccess::canRead($user, $place);
    }

    /** The assignment this person can act on now, if any. */
    public function myTurn(User $user, ApprovalRequest $r): ?ApprovalAssignment
    {
        if ($r->status !== 'pending' || (int) $r->requested_by === (int) $user->id) {
            return null;
        }

        return ApprovalAssignment::where('request_id', $r->id)->where('approver_id', $user->id)->where('status', 'pending')->whereNull('superseded_at')
            ->whereHas('stage', fn ($q) => $q->where('status', 'active'))->first();
    }

    public function present(ApprovalRequest $r, User $viewer, bool $full = false): array
    {
        $r->loadMissing(['subject', 'territory', 'requester', 'stages.assignments.approver']);
        $s = $r->subject->approvalSummary();
        $active = $r->stages->firstWhere('status', 'active') ?? $r->stages->firstWhere('status', 'blocked');
        $waitingOn = $active ? $active->assignments->where('status', 'pending')->whereNull('superseded_at')->map(fn ($a) => $a->approver?->full_name)->filter()->values()->all() : [];
        $turn = $this->myTurn($viewer, $r);
        $out = [
            'id' => $r->id,
            'status' => $r->status,
            'status_label' => ApprovalRequest::STATUSES[$r->status],
            'subject' => $s + ['id' => $r->subject_id],
            'place' => ['id' => $r->territory?->id, 'name' => $r->territory?->name, 'level' => $r->territory?->territory_type?->value],
            'requested_by' => $r->requester?->full_name,
            'requested_at' => $r->created_at?->toIso8601String(),
            'workflow' => $r->workflow_name,
            'stage' => $active ? ['name' => $active->name, 'status' => $active->status, 'blocked_reason' => $active->blocked_reason] : null,
            'waiting_on' => $waitingOn,
            'due_at' => $turn?->due_at?->toIso8601String(),
            'my_assignment' => $turn?->id,
            'completed_at' => $r->completed_at?->toIso8601String(),
        ];
        if ($full) {
            $d = $r->subject->approvalDetails();
            $decisions = ApprovalDecision::with('actor')->whereIn('assignment_id', $r->stages->flatMap(fn ($st) => $st->assignments->pluck('id')))->get()->groupBy('assignment_id');
            $out += [
                'facts' => $d['facts'] ?? [],
                'lines' => $d['lines'] ?? [],
                'files' => collect($d['media'] ?? [])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'mime' => $m->mime_type])->values()->all(),
                'stages' => $r->stages->map(fn ($st) => [
                    'name' => $st->name,
                    'type' => $st->type,
                    'status' => $st->status,
                    'blocked_reason' => $st->blocked_reason,
                    'people' => $st->assignments->sortBy('id')->map(fn ($a) => [
                        'name' => $a->approver?->full_name,
                        'status' => $a->status,
                        'superseded' => (bool) $a->superseded_at,
                        'escalated' => $a->resolver_type === 'escalation',
                        'for' => $a->delegated_from ? User::find($a->delegated_from)?->full_name : null,
                        'decided_at' => ($decisions[$a->id] ?? collect())->first()?->created_at?->toIso8601String(),
                        'comment' => ($decisions[$a->id] ?? collect())->first()?->comment,
                        'due_at' => $a->status === 'pending' ? $a->due_at?->toIso8601String() : null,
                    ])->values(),
                ])->values(),
                'can' => [
                    'decide' => (bool) $turn,
                    'cancel' => $r->status === 'pending' && (int) $r->requested_by === (int) $viewer->id,
                    'retry' => $active?->status === 'blocked' && AccountingAccess::canManageRules($viewer),
                ],
            ];
        }

        return $out;
    }
}
