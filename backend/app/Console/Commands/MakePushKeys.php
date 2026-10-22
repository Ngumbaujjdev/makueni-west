<?php

namespace App\Console\Commands;

use App\Services\Settings\Settings;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Makes the diocese's push keys once (docs/specs/mobile-app-spec.md, M12).
 * Keys that exist are never replaced - a new pair would silence every phone.
 */
class MakePushKeys extends Command
{
    protected $signature = 'app:push-keys';

    protected $description = "Create the phone app's push notification keys (only when there are none)";

    public function handle(Settings $settings): int
    {
        if ($settings->system('push.vapid_public') && $settings->system('push.vapid_private')) {
            $this->info('Push keys are already set - nothing changed.');

            return self::SUCCESS;
        }
        $place = $settings->systemPlace();
        if (! $place) {
            $this->error('No diocese yet.');

            return self::FAILURE;
        }
        $keys = VAPID::createVapidKeys();
        $settings->setMany($place, 'diocese', 'communication', [
            'push.vapid_public' => $keys['publicKey'], 'push.vapid_private' => $keys['privateKey'],
        ]);
        // setMany answers with what changed; read the keys back to be sure they were kept.
        $fresh = app(Settings::class);
        if ($fresh->system('push.vapid_public') !== $keys['publicKey'] || ! $fresh->system('push.vapid_private')) {
            $this->error('Could not save the keys.');

            return self::FAILURE;
        }
        $this->info('Push keys created.');

        return self::SUCCESS;
    }
}
