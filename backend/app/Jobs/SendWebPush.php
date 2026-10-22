<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Push\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A push notification to some people's phones (mobile-app-spec M12):
 * {title, body, url, kind}. A phone that no longer exists (404/410) is
 * forgotten; nobody subscribed, or no keys, means nothing is sent.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @param  int[]  $userIds */
    public function __construct(public array $userIds, public array $payload) {}

    public function handle(PushSender $sender): void
    {
        $subs = PushSubscription::whereIn('user_id', $this->userIds)->get();
        if ($subs->isEmpty() || ! $sender->ready()) {
            return;
        }
        $results = $sender->deliver($subs, $this->payload);
        foreach ($subs as $s) {
            $code = $results[$s->endpoint] ?? null;
            if (in_array($code, [404, 410], true)) {
                $s->delete();
            } elseif ($code && $code < 300) {
                $s->forceFill(['last_used_at' => now()])->save();
            }
        }
    }
}
