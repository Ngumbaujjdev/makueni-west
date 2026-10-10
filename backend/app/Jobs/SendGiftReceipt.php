<?php

namespace App\Jobs;

use App\Models\Gift;
use App\Models\Journal;
use App\Models\Territory;
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
 * The giver's receipt for an online gift (docs/specs/accounting-spec.md,
 * A10a): an SMS, and an email when they gave one, from the church's sender.
 * Queued, so Paystack's webhook is answered at once.
 */
class SendGiftReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $giftId) {}

    public function handle(PlaceMessenger $messenger, Settings $settings): void
    {
        $g = Gift::find($this->giftId);
        $place = $g ? Territory::find($g->territory_id) : null;
        if (! $g || ! $place || $g->status !== 'paid' || ! $settings->system('giving.receipts')) {
            return;
        }
        $receipt = Journal::find($g->journal_id)?->number;
        $what = strtolower(Paybill::PURPOSES[$g->purpose][0] ?? 'gift');
        $name = trim((string) explode(' ', (string) $g->giver_name)[0]);
        $text = 'Thank you'.($name !== '' ? " {$name}" : '').'. '.$place->name.' has received your '.$what.' of KES '.number_format((float) $g->amount, 2)
            .' (ref '.$g->reference.($receipt ? ", receipt {$receipt}" : '').'). God bless you.';
        $phone = Phone::kenyaMobile((string) $g->giver_phone);
        if ($phone && ! Phone::isDemo($phone)) {
            $messenger->sms($place, $phone, $text, 'giving');
        }
        if ($g->giver_email && ! str_ends_with($g->giver_email, '.test') && ! str_starts_with($g->giver_email, 'give+')) {
            $html = '<p>'.e($text).'</p><p>Amount: <strong>KES '.number_format((float) $g->amount, 2).'</strong><br>For: '.e(ucfirst($what)).'<br>Reference: '.e($g->reference)
                .($receipt ? '<br>Receipt: '.e($receipt) : '').'<br>Date: '.e($g->paid_at?->setTimezone('Africa/Nairobi')->format('j F Y, H:i')).'</p>';
            $messenger->email($place, $g->giver_email, "Receipt - your {$what} to {$place->name}", $html, 'giving');
        }
    }
}
