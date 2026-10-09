<?php

namespace App\Console\Commands;

use App\Approval\Services\EscalationService;
use Illuminate\Console\Command;

/** Remind approvers who are late, then pass the approval up (docs/specs/accounting-spec.md, A4). Hourly. */
class EscalateApprovals extends Command
{
    protected $signature = 'approvals:escalate';

    protected $description = 'Remind late approvers, then escalate past the grace period';

    public function handle(EscalationService $escalation): int
    {
        $r = $escalation->run();
        $this->info("Reminded {$r['reminded']}, escalated {$r['escalated']}.");

        return self::SUCCESS;
    }
}
