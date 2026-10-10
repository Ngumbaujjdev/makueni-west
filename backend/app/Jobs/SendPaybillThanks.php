<?php

namespace App\Jobs;

use App\Models\Journal;
use App\Models\MpesaPayment;
use App\Models\Territory;
use App\Services\Accounting\GivingPurposes;
use App\Services\Accounting\Paybill;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Settings\Settings;
use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A thank-you SMS to whoever paid into the diocese paybill
 * (docs/specs/accounting-spec.md, A8), from the church's own sender, with the
 * receipt number. Queued, so Safaricom's callback is answered at once.
 * Safaricom masks the number on live C2B payments - those get no SMS.
 */
class SendPaybillThanks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $paymentId) {}

    public function handle(PlaceMessenger $messenger, Settings $settings): void
    {
        $p = MpesaPayment::find($this->paymentId);
        $place = $p?->territory_id ? Territory::find($p->territory_id) : null;
        $phone = $p ? Phone::kenyaMobile((string) $p->phone) : null;
        if (! $p || ! $place || $p->status !== 'posted' || ! $phone || Phone::isDemo($phone) || ! $settings->system('paybill.thank_sms')) {
            return;
        }
        $receipt = Journal::find($p->place_journal_id ?? $p->diocese_journal_id)?->number;
        $what = strtolower(GivingPurposes::label($p->purpose, 'gift'));
        $name = trim((string) explode(' ', (string) $p->payer_name)[0]);
        $text = 'Thank you'.($name !== '' ? " {$name}" : '').'. '.$place->name.' has received your '.$what.' of KES '.number_format((float) $p->amount)
            .' (M-Pesa '.$p->trans_id.($receipt ? ", receipt {$receipt}" : '').'). God bless you.';
        $messenger->sms($place, $phone, $text, 'paybill');
    }
}
