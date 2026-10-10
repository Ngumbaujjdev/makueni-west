<?php

namespace App\Console\Commands;

use App\Services\Accounting\Giving;
use App\Services\Accounting\Paybill;
use Illuminate\Console\Command;

/**
 * Online gifts still pending after 10 minutes are checked with Paystack (A10a)
 * or PayHero (A10b) and completed; after a day, abandoned. M-Pesa prompts
 * whose answer never came back are asked about after 2 minutes, given up
 * after an hour.
 */
class ReconcileGifts extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check pending online gifts and M-Pesa prompts with Paystack, Safaricom or PayHero and complete them';

    public function handle(Giving $giving, Paybill $paybill): int
    {
        $done = $giving->sweep();
        $this->info("Gifts: checked {$done['checked']}, paid {$done['paid']}, abandoned {$done['abandoned']}.");
        $prompts = $paybill->sweepPrompts();
        $this->info("Prompts: checked {$prompts['checked']}, paid {$prompts['paid']}, given up {$prompts['expired']}.");

        return self::SUCCESS;
    }
}
