<?php

namespace App\Approval\Services;

use App\Models\ApprovalAssignment;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chasing approvals that sit too long (docs/specs/accounting-spec.md, A4) -
 * run hourly by approvals:escalate. When an assignment is due it is
 * reminded once; after the stage's grace period it is passed to the stage's
 * "escalate to" people (e.g. the overseer above). On an "everyone must
 * approve" stage the new person replaces the late one; otherwise either may
 * approve. A stage with no deadline is never chased.
 */
final class EscalationService
{
    public const DEFAULT_GRACE_HOURS = 24;

    public function __construct(private ApprovalService $engine, private DelegationResolver $delegations, private Notices $notices) {}

    /** @return array{reminded: int, escalated: int} */
    public function run(): array
    {
        $now = now();
        $reminded = 0;
        $escalated = 0;

        $due = ApprovalAssignment::with(['request', 'stage'])->where('status', 'pending')->whereNull('superseded_at')
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->get();

        foreach ($due->whereNull('reminded_at') as $a) {
            if ($a->request?->status !== 'pending' || $a->stage?->status !== 'active') {
                continue;
            }
            $a->update(['reminded_at' => $now]);
            $this->engine->event($a->request, 'reminded', ['assignment_id' => $a->id]);
            if ($u = User::find($a->approver_id)) {
                $this->notices->send($u, 'reminder', $a->request);
            }
            $reminded++;
        }

        foreach ($due->whereNull('escalated_at') as $a) {
            $grace = (int) ($a->stage?->rule_snapshot['escalate_after_hours'] ?? self::DEFAULT_GRACE_HOURS);
            if ($a->due_at->copy()->addHours($grace)->gt($now)) {
                continue;
            }
            $escalated += DB::transaction(fn () => $this->escalate($a, $grace));
        }

        return ['reminded' => $reminded, 'escalated' => $escalated];
    }

    private function escalate(ApprovalAssignment $a, int $grace): int
    {
        $request = ApprovalRequest::whereKey($a->request_id)->lockForUpdate()->first();
        $a = ApprovalAssignment::with('stage')->whereKey($a->id)->lockForUpdate()->first();
        if (! $request || $request->status !== 'pending' || $a->status !== 'pending' || $a->escalated_at || $a->stage?->status !== 'active') {
            return 0;
        }
        $step = $a->stage->rule_snapshot['escalate_to'] ?? null;
        $already = ApprovalAssignment::where('request_stage_id', $a->request_stage_id)->whereNull('superseded_at')->whereIn('status', ['pending', 'approved'])->pluck('approver_id')->all();
        $targets = collect();
        if ($step && ! empty($step['resolver_type'])) {
            try {
                foreach ($this->engine->resolveSteps([$step], $request) as $u) {
                    [$who] = $this->delegations->resolve($u, $request);
                    if ((int) $who->id !== (int) $request->requested_by && ! in_array($who->id, $already, true)) {
                        $targets->put($who->id, $who);
                    }
                }
            } catch (\InvalidArgumentException) {
                // A rule naming an unknown kind of approver - nobody to pass it to.
            }
        }
        $a->update(['escalated_at' => now()]);
        if ($targets->isEmpty()) {
            $this->engine->event($request, 'escalation_no_target', ['assignment_id' => $a->id]);

            return 0;
        }
        // "Everyone must approve": one person replaces the late one.
        if ($a->stage->type === 'all') {
            $targets = $targets->take(1);
            $a->update(['status' => 'superseded', 'superseded_at' => now()]);
        }
        foreach ($targets as $u) {
            ApprovalAssignment::create([
                'request_id' => $request->id, 'request_stage_id' => $a->request_stage_id, 'approver_id' => $u->id,
                'resolver_type' => 'escalation', 'escalated_from' => $a->id, 'status' => 'pending', 'due_at' => now()->addHours($grace),
            ]);
            $this->notices->send($u, 'escalated', $request);
        }
        $this->engine->event($request, 'escalated', ['from' => $a->approver_id, 'to' => $targets->keys()->all()]);

        return 1;
    }
}
