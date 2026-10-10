<?php

namespace App\Console\Commands;

use App\Services\Accounting\PaybillClaims;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetch the diocese paybill's payments of the last few hours from Safaricom
 * (Pull Transactions) and record any whose callback never came
 * (docs/specs/accounting-spec.md, A10f). Live paybill only, once Safaricom
 * has approved Pull for it; --register does the one-time registration.
 */
class PullPaybillPayments extends Command
{
    protected $signature = 'payments:pull {--register : Register for Pull with Safaricom (once)} {--hours=3}';

    protected $description = 'Fetch recent paybill payments from Safaricom and record any that are missing';

    public function handle(PaybillClaims $claims): int
    {
        if (! $claims->canPull()) {
            $this->line('Pull is off - it needs the live paybill and its nominated phone in Settings, Paybill.');

            return self::SUCCESS;
        }
        try {
            if ($this->option('register')) {
                $out = $claims->pullRegister();
                $this->info('Safaricom: '.($out['ResponseDescription'] ?? json_encode($out)));

                return self::SUCCESS;
            }
            $done = $claims->pull(max(1, min(47, (int) $this->option('hours'))));
            $this->info("Seen {$done['seen']}, recorded {$done['recorded']} new.");
        } catch (Throwable $e) {
            report($e);
            $this->warn('Safaricom: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
