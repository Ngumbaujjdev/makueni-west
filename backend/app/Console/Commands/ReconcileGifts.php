<?php

namespace App\Console\Commands;

use App\Services\Accounting\Giving;
use Illuminate\Console\Command;

/** Online gifts still pending after 10 minutes are checked with Paystack (A10a) or PayHero (A10b) and completed; after a day, abandoned. */
class ReconcileGifts extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check pending online gifts with Paystack or PayHero and complete them';

    public function handle(Giving $giving): int
    {
        $done = $giving->sweep();
        $this->info("Checked {$done['checked']}, paid {$done['paid']}, abandoned {$done['abandoned']}.");

        return self::SUCCESS;
    }
}
