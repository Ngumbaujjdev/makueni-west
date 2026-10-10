<?php

namespace App\Console\Commands;

use App\Services\Accounting\Giving;
use App\Services\Payments\Paystack;
use Illuminate\Console\Command;

/** Paystack's payouts into each place's bank, posted once as transfers out of clearing (A10a). */
class RecordPaystackSettlements extends Command
{
    protected $signature = 'payments:settlements';

    protected $description = 'Record Paystack payouts as transfers into each place\'s bank';

    public function handle(Giving $giving): int
    {
        if (! Paystack::ready()) {
            return self::SUCCESS;
        }
        $this->info($giving->recordSettlements().' payouts recorded.');

        return self::SUCCESS;
    }
}
