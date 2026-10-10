<?php

namespace App\Jobs;

use App\Models\Gift;
use App\Models\Journal;
use App\Models\Territory;
use App\Services\Accounting\GivingPurposes;
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

    /** The thank-you the giver gets, with the amount and the references. */
    public static function text(Gift $g, Territory $place): string
    {
        $receipt = Journal::find($g->journal_id)?->number;
        $what = strtolower(GivingPurposes::label($g->purpose, 'gift'));
        $name = trim((string) explode(' ', (string) $g->giver_name)[0]);

        return 'Thank you'.($name !== '' ? " {$name}" : '').'. '.$place->name.' has received your '.$what.' of KES '.number_format((float) $g->amount, 2)
            .' (ref '.$g->reference.($receipt ? ", receipt {$receipt}" : '').'). God bless you.';
    }

    /** Where the receipt can be seen and downloaded again (the thanks page). */
    public static function link(Gift $g): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/give-thanks?ref='.rawurlencode($g->reference);
    }

    /** The SMS: the thank-you and the link to the receipt. */
    public static function sms(Gift $g, Territory $place): string
    {
        return self::text($g, $place).' Receipt: '.self::link($g);
    }

    public function handle(PlaceMessenger $messenger, Settings $settings): void
    {
        $g = Gift::find($this->giftId);
        $place = $g ? Territory::find($g->territory_id) : null;
        if (! $g || ! $place || $g->status !== 'paid' || ! $settings->system('giving.receipts')) {
            return;
        }
        $receipt = Journal::find($g->journal_id)?->number;
        $what = strtolower(GivingPurposes::label($g->purpose, 'gift'));
        $text = self::text($g, $place);
        $link = self::link($g);
        $phone = Phone::kenyaMobile((string) $g->giver_phone);
        if ($phone && ! Phone::isDemo($phone)) {
            $messenger->sms($place, $phone, self::sms($g, $place), 'giving');
        }
        if ($g->giver_email && ! str_ends_with($g->giver_email, '.test') && ! str_starts_with($g->giver_email, 'give+')) {
            $html = '<p>'.e($text).'</p><p>Amount: <strong>KES '.number_format((float) $g->amount, 2).'</strong><br>For: '.e(ucfirst($what)).'<br>Reference: '.e($g->reference)
                .($receipt ? '<br>Receipt: '.e($receipt) : '').'<br>Date: '.e($g->paid_at?->setTimezone('Africa/Nairobi')->format('j F Y, H:i')).'</p>';
            $html .= '<p><a href="'.e($link).'">View or download your receipt</a></p>';
            $messenger->email($place, $g->giver_email, "Receipt - your {$what} to {$place->name}", $html, 'giving');
        }
    }
}
