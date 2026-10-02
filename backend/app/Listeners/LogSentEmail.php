<?php

namespace App\Listeners;

use App\Models\MessageLog;
use Illuminate\Mail\Events\MessageSent;

/** Every email that leaves goes into message_logs (Settings > System health). */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $to = collect($event->message->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
            MessageLog::create([
                'channel' => 'mail',
                'to' => mb_substr($to, 0, 255),
                'subject' => mb_substr((string) $event->message->getSubject(), 0, 255),
                'status' => config('mail.default') === 'log' ? 'logged' : 'sent',
            ]);
        } catch (\Throwable) {
            // logging a send must never break the send
        }
    }
}
