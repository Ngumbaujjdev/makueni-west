<?php

namespace App\Console\Commands;

use App\Services\Accounting\Giving;
use App\Services\Payments\Paystack;
use Illuminate\Console\Command;

/** Online gifts still pending after 10 minutes are checked with Paystack and completed (A10a); after a day, abandoned. */
class ReconcileGifts extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check pending online gifts with Paystack and complete them';

    public function handle(Giving $giving): int
    {
        if (! Paystack::ready()) {
            return self::SUCCESS;
        }
        $done = $giving->sweep();
        $this->info("Checked {$done['checked']}, paid {$done['paid']}, abandoned {$done['abandoned']}.");

        return self::SUCCESS;
    }
}
