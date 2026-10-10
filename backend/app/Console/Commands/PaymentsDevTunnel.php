<?php

namespace App\Console\Commands;

use App\Models\Territory;
use App\Services\Accounting\Paybill;
use App\Services\Settings\Settings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Testing payments on a computer the internet can't reach
 * (docs/specs/accounting-spec.md, A8/A10): point the diocese paybill's
 * callbacks at a tunnel (e.g. `cloudflared tunnel --url http://localhost:8004`),
 * register them with Safaricom, and show the Paystack webhook to set.
 * Sandbox only - it refuses a live paybill.
 */
class PaymentsDevTunnel extends Command
{
    protected $signature = 'payments:dev-tunnel {url : The tunnel\'s https address, e.g. https://abc.trycloudflare.com}';

    protected $description = 'Point the sandbox paybill callbacks at a tunnel and register them with Safaricom';

    public function handle(Settings $settings, Paybill $paybill): int
    {
        $url = rtrim((string) $this->argument('url'), '/');
        if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $url)) {
            $this->error('Give the tunnel\'s https address, e.g. https://abc.trycloudflare.com');

            return self::FAILURE;
        }
        if ($settings->system('paybill.environment') === 'production') {
            $this->error('The paybill is live - this is for the sandbox only.');

            return self::FAILURE;
        }
        $diocese = Territory::where('territory_type', 'diocese')->orderBy('id')->firstOrFail();
        $settings->setMany($diocese, 'diocese', 'paybill', ['paybill.callback_base' => $url]);
        $paybill->callbackKey();
        $this->info('Callbacks now go to '.$url.'/api/payments/daraja/{the callback key}/stk (and /confirmation, /validation).');
        try {
            $out = $paybill->register();
            $this->info('Safaricom: '.($out['ResponseDescription'] ?? json_encode($out)));
        } catch (Throwable $e) {
            $this->warn('Safaricom didn\'t register the addresses: '.$e->getMessage().' - prompts still work; the status check completes them.');
        }
        $this->line('Paystack: set the test webhook URL (Paystack dashboard, Settings, API keys & webhooks) to '.$url.'/api/payments/paystack/webhook');

        return self::SUCCESS;
    }
}
