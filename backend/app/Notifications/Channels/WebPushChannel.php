<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWebPush;
use Illuminate\Notifications\Notification;

/**
 * Every bell notice also goes to the person's phones as a push (mobile-app-spec
 * M12). PlaceNotification::via() adds this channel only for people who turned
 * notifications on in the app.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $data = method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : [];
        SendWebPush::dispatch([(int) $notifiable->getKey()], [
            'title' => (string) ($data['title'] ?? 'CCI Makueni West'),
            'body' => mb_strimwidth((string) ($data['body'] ?? ''), 0, 180, '…'),
            'url' => $data['url'] ?? null,
            'kind' => $data['kind'] ?? 'reminder',
        ]);
    }
}
