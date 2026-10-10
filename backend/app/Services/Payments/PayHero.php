<?php

namespace App\Services\Payments;

use App\Models\PaymentChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayHero (docs/specs/accounting-spec.md, A10b) - the M-Pesa prompt for a
 * church's own paybill or till, linked in the church's PayHero account as a
 * payment channel. Basic auth with the church's API username and password.
 * PayHero doesn't sign its callbacks, so a payment is only ever taken as
 * paid from status(), asked of PayHero ourselves.
 */
final class PayHero
{
    private const URL = 'https://backend.payhero.co.ke/api/v2';

    public function __construct(private readonly string $username, private readonly string $password, public readonly int $channelId) {}

    public static function forChannel(PaymentChannel $channel): self
    {
        $s = $channel->secrets();
        if ($channel->provider !== 'payhero' || ! $channel->mpesaReady()) {
            throw new RuntimeException('That church\'s PayHero isn\'t fully set up - add its API details on Gateways.');
        }

        return new self((string) $s['username'], (string) $s['password'], (int) $s['channel_id']);
    }

    private function http()
    {
        return Http::timeout(30)->withBasicAuth($this->username, $this->password)->acceptJson();
    }

    /**
     * Ask to pay: the prompt on the payer's phone. Answers with PayHero's
     * reference (for status()) and the CheckoutRequestID its callback carries.
     *
     * @return array{reference: string, checkout_request_id: string, status: string}
     */
    public function stkPush(float $amount, string $phone, string $externalReference, ?string $name, string $callbackUrl): array
    {
        $r = $this->http()->post(self::URL.'/payments', array_filter([
            'amount' => (int) round($amount), 'phone_number' => Daraja::phone($phone), 'channel_id' => $this->channelId, 'provider' => 'm-pesa',
            'external_reference' => $externalReference, 'customer_name' => $name ? mb_substr($name, 0, 60) : null, 'callback_url' => $callbackUrl,
        ], fn ($v) => $v !== null));
        if (! $r->successful() || $r->json('success') === false) {
            throw new RuntimeException((string) ($r->json('error_message') ?? $r->json('message') ?? 'PayHero answered '.$r->status().'.'));
        }

        return ['reference' => (string) $r->json('reference'), 'checkout_request_id' => (string) $r->json('CheckoutRequestID'), 'status' => (string) $r->json('status')];
    }

    /**
     * The payment as PayHero has it - QUEUED, SUCCESS or FAILED. Only SUCCESS
     * is paid. The M-Pesa code is PayHero's provider reference.
     *
     * @return array{status: string, receipt: ?string, amount: ?float, raw: array}
     */
    public function status(string $reference): array
    {
        $r = $this->http()->get(self::URL.'/transaction-status', ['reference' => $reference]);
        if (! $r->successful()) {
            throw new RuntimeException('PayHero couldn\'t say how the payment went ('.$r->status().').');
        }
        $d = (array) $r->json();

        return [
            'status' => strtoupper((string) ($d['status'] ?? '')),
            'receipt' => ($d['provider_reference'] ?? $d['third_party_reference'] ?? $d['MpesaReceiptNumber'] ?? null) ?: null,
            'amount' => isset($d['amount']) ? (float) $d['amount'] : null,
            'raw' => $d,
        ];
    }
}
