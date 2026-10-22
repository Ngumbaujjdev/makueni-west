<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Services\Settings\Settings;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push messages to phones (mobile-app-spec M12) with the diocese's
 * VAPID keys. Bound in the container so tests can swap it for a fake.
 */
class PushSender
{
    public function __construct(private Settings $settings) {}

    /** Both keys are set, so pushes can go out. */
    public function ready(): bool
    {
        return (bool) $this->settings->system('push.vapid_public') && (bool) $this->settings->system('push.vapid_private');
    }

    /**
     * Send one payload to some subscriptions.
     *
     * @param  iterable<PushSubscription>  $subs
     * @return array<string, int> endpoint => HTTP status (0 when it never answered)
     */
    public function deliver(iterable $subs, array $payload): array
    {
        if (! $this->ready()) {
            return [];
        }
        $push = new WebPush(['VAPID' => [
            'subject' => rtrim((string) config('app.url'), '/') ?: 'mailto:support@example.org',
            'publicKey' => $this->settings->system('push.vapid_public'),
            'privateKey' => $this->settings->system('push.vapid_private'),
        ]], ['TTL' => 86400, 'urgency' => 'normal']);

        $json = json_encode($payload);
        foreach ($subs as $s) {
            $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth]]), $json);
        }
        $out = [];
        foreach ($push->flush() as $report) {
            $out[$report->getEndpoint()] = $report->getResponse()?->getStatusCode() ?? ($report->isSuccess() ? 201 : 0);
        }

        return $out;
    }
}
