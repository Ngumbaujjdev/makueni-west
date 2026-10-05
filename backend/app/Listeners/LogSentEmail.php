<?php

namespace App\Listeners;

use App\Models\MessageLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Every email that leaves goes into message_logs (Settings > System health,
 * Communication > Messages). Emails sent for a church or region through
 * PlaceMessenger log themselves, with their text, and carry X-MWD-Logged so
 * they aren't counted twice. Everything else is an account email (password
 * reset, code reset, support) - its text isn't kept, because it can carry a
 * private link.
 */
class LogSentEmail
{
    public const LOGGED_HEADER = 'X-MWD-Logged';

    public function handle(MessageSent $event): void
    {
        try {
            if ($event->message->getHeaders()->has(self::LOGGED_HEADER)) {
                return;
            }
            $to = collect($event->message->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
            $from = collect($event->message->getFrom())->map(fn ($a) => $a->toString())->implode(', ');
            MessageLog::create([
                'channel' => 'mail',
                'kind' => 'account',
                'via' => 'system',
                'to' => mb_substr($to, 0, 255),
                'from' => mb_substr($from, 0, 255) ?: null,
                'subject' => mb_substr((string) $event->message->getSubject(), 0, 255),
                'status' => config('mail.default') === 'log' ? 'logged' : 'sent',
                'meta' => ['body_not_kept' => 'Account emails can carry a private link, so their text isn\'t kept.'],
            ]);
        } catch (\Throwable) {
            // logging a send must never break the send
        }
    }
}
