<?php

namespace App\Services\Messages;

use App\Models\MessageBatch;
use App\Models\MessageRecipient;
use App\Models\Territory;
use App\Models\User;
use App\Notifications\PlaceNotification;
use App\Services\Messaging\PlaceMessenger;
use App\Support\Messaging\EmailBrand;
use Illuminate\Support\Str;

/**
 * Sends a message (docs/specs/messages-spec.md) through the Settings sender -
 * PlaceMessenger, kind "broadcast" - so it goes out on the place's own or the
 * diocese's account, with its signature, and lands in the message log.
 * Everyone with a login also gets it in the Inbox and the bell.
 */
final class Broadcaster
{
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";

    public function __construct(private PlaceMessenger $messenger) {}

    /** How many SMS a text takes: 160 (153 a part) in plain GSM text, 70 (67) otherwise. */
    public static function smsParts(string $text): array
    {
        $len = mb_strlen($text);
        $gsm = collect(mb_str_split($text))->every(fn ($c) => str_contains(self::GSM, $c));
        [$single, $part] = $gsm ? [160, 153] : [70, 67];

        return ['characters' => $len, 'parts' => $len === 0 ? 0 : ($len <= $single ? 1 : (int) ceil($len / $part)), 'unicode' => ! $gsm];
    }

    /** {name}, {place} and {sender} filled in for one person. */
    public static function fill(string $text, ?string $name, ?string $place, string $sender): string
    {
        $first = $name ? (Str::before(trim($name), ' ') ?: 'friend') : 'friend';
        if ($name && str_starts_with($name, '+')) {
            $first = 'friend'; // a typed number has no name
        }

        return strtr($text, ['{name}' => $first, '{place}' => $place ?: $sender, '{sender}' => $sender]);
    }

    /** Send to everyone not sent to yet (or, retrying, the channels that failed). */
    public function deliver(MessageBatch $batch): void
    {
        $batch->loadMissing('territory', 'creator');
        $place = $batch->territory;
        $by = $batch->creator;
        $places = Territory::whereIn('id', $batch->recipients()->pluck('place_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');

        foreach ($batch->recipients()->get() as $r) {
            $this->deliverOne($batch, $r, $place, $places->get($r->place_id), $by);
        }
        $this->tally($batch->fresh());
    }

    /** Retry: the failed channels go again. */
    public function retry(MessageBatch $batch): int
    {
        $failed = $batch->recipients()->where(fn ($q) => $q->where('sms_status', 'failed')->orWhere('email_status', 'failed'))->get();
        foreach ($failed as $r) {
            $r->forceFill([
                'sms_status' => $r->sms_status === 'failed' ? null : $r->sms_status,
                'email_status' => $r->email_status === 'failed' ? null : $r->email_status,
                'error' => null,
            ])->save();
        }
        $this->deliver($batch);

        return $failed->count();
    }

    private function deliverOne(MessageBatch $batch, MessageRecipient $r, Territory $place, ?Territory $theirPlace, ?User $by): void
    {
        $text = self::fill($batch->body, $r->name, $theirPlace?->name, $place->name);
        $changes = [];
        $logs = $r->log_ids ?? [];
        $errors = [];

        if ($batch->sendsSms() && $r->sms_status === null) {
            if ($r->phone) {
                $res = $this->messenger->sms($place, $r->phone, $text, 'broadcast', [], $by);
                $changes['sms_status'] = $res['ok'] ? ($res['status'] === 'logged' ? 'logged' : 'sent') : 'failed';
                $logs[] = $res['log_id'];
                if (! $res['ok']) {
                    $errors[] = $res['error'];
                }
            } else {
                $changes['sms_status'] = 'skipped';
            }
        }
        if ($batch->sendsEmail() && $r->email_status === null) {
            if ($r->email) {
                $subject = $batch->subject ?: Str::limit(Str::before($batch->body, "\n"), 80);
                $html = view('emails.place-message', [
                    'heading' => $subject,
                    'lines' => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: [$text]))),
                    'placeName' => $place->name,
                    'brand' => EmailBrand::for($place),
                ])->render();
                $res = $this->messenger->email($place, $r->email, $subject, $html, 'broadcast', [], $by);
                $changes['email_status'] = $res['ok'] ? ($res['status'] === 'logged' ? 'logged' : 'sent') : 'failed';
                $logs[] = $res['log_id'];
                if (! $res['ok']) {
                    $errors[] = $res['error'];
                }
            } else {
                $changes['email_status'] = 'skipped';
            }
        }
        // In the app: the Inbox, and the bell - once, not again on a retry.
        if ($r->user_id && ! $r->notified_at && ($user = User::find($r->user_id))) {
            $level = $theirPlace?->territory_type?->value ?? 'church';
            $user->notify(new PlaceNotification('message', $batch->subject ?: "Message from {$place->name}", Str::limit($text, 140), "/{$level}/messages/?open={$r->id}", $place, 'ri-chat-3-line'));
            $changes['notified_at'] = now();
        }
        if ($changes) {
            $r->forceFill($changes + ['log_ids' => array_values(array_filter($logs)), 'error' => $errors ? Str::limit(implode(' · ', array_filter($errors)), 250) : $r->error])->save();
        }
    }

    private function tally(MessageBatch $batch): void
    {
        $recipients = $batch->recipients()->get();
        $failed = $recipients->filter(fn (MessageRecipient $r) => $r->failed())->count();
        $batch->forceFill([
            'status' => 'sent',
            'sent_at' => $batch->sent_at ?? now(),
            'failed_count' => $failed,
            'sent_count' => $recipients->count() - $failed,
        ])->save();
    }
}
