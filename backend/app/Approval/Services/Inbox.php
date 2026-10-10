<?php

namespace App\Approval\Services;

use App\Models\ApprovalAssignment;
use App\Models\ApprovalDecision;
use App\Models\ApprovalEvent;
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

    /** Which record page shows each kind of request (record.php?type=). */
    public const RECORD_TYPES = ['payment_voucher' => 'voucher', 'requisition' => 'requisition', 'payroll_run' => 'payroll'];

    /**
     * The board for one place (Redesign R2): every request still waiting there
     * and who holds it, this month's numbers, and the latest happenings.
     */
    public function board(User $viewer, Territory $place): array
    {
        $open = ApprovalRequest::with(['subject', 'territory', 'requester', 'stages.assignments.approver'])
            ->where('territory_id', $place->id)->where('status', 'pending')->orderBy('id')->get()
            ->filter(fn ($r) => $r->subject !== null)->values();
        $overdue = ApprovalAssignment::whereIn('request_id', $open->pluck('id'))->where('status', 'pending')->whereNull('superseded_at')
            ->whereNotNull('due_at')->where('due_at', '<', now())->pluck('request_id')->unique()->flip();
        $items = $open->map(fn ($r) => $this->present($r, $viewer) + ['overdue' => isset($overdue[$r->id])])->values();

        $closed = ApprovalRequest::where('territory_id', $place->id)->whereIn('status', ['approved', 'rejected', 'returned'])
            ->where('completed_at', '>=', now()->startOfMonth())->get(['status', 'created_at', 'completed_at']);
        $hours = $closed->map(fn ($r) => $r->created_at->diffInMinutes($r->completed_at) / 60);

        $trail = app(\App\Services\Accounting\Trail::class);
        $requests = ApprovalRequest::where('territory_id', $place->id)->get(['id', 'subject_type', 'subject_id'])->keyBy('id');
        $activity = ApprovalEvent::whereIn('request_id', $requests->keys())
            ->whereIn('type', ['submitted', 'decided', 'stage_blocked', 'escalated', 'stage_escalated_empty', 'returned_to_stage', 'cancelled'])
            ->orderByDesc('id')->limit(20)->get()
            ->map(function ($e) use ($trail, $requests) {
                $line = $trail->approvalSentence($e);
                $r = $requests[$e->request_id];
                $subject = $r->subject;

                return $line ? array_diff_key($line, ['order' => 1]) + ['record' => ['type' => self::RECORD_TYPES[$r->subject_type] ?? null, 'id' => $r->subject_id, 'number' => $subject?->number ?? ($subject?->month ? 'Payroll '.\Carbon\Carbon::parse("{$subject->month}-01")->format('F Y') : null)]] : null;
            })->filter()->values();

        return [
            'open' => $items,
            'stats' => [
                'waiting' => $items->count(),
                'mine' => $items->filter(fn ($i) => $i['my_assignment'])->count(),
                'overdue' => $items->where('overdue', true)->count(),
                'approved' => $closed->where('status', 'approved')->count(),
                'stopped' => $closed->whereIn('status', ['rejected', 'returned'])->count(),
                'avg_hours' => $hours->count() ? round($hours->avg(), 1) : null,
            ],
            'activity' => $activity,
        ];
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
            'record' => ['type' => self::RECORD_TYPES[$r->subject_type] ?? null, 'id' => $r->subject_id],
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
