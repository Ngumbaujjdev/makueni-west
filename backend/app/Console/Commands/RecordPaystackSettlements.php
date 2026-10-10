<?php

namespace App\Console\Commands;

use App\Services\Accounting\Payouts;
use App\Services\Payments\Paystack;
use Illuminate\Console\Command;

/** Paystack's payouts into each place's bank - every status kept, a paid one posted once as a transfer out of clearing, matched to its gifts (A10a, A10c). */
class RecordPaystackSettlements extends Command
{
    protected $signature = 'payments:settlements';

    protected $description = 'Record Paystack payouts as transfers into each place\'s bank';

    public function handle(Payouts $payouts): int
    {
        if (! Paystack::ready()) {
            return self::SUCCESS;
        }
        $this->info($payouts->record().' payouts recorded.');

        return self::SUCCESS;
    }
}
