<?php

namespace App\Services\Payments;

use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Paystack (docs/specs/accounting-spec.md, A10a) - ported from v1-events'
 * PaystackService, with its faults closed: amounts are always
 * (int) round(x * 100) (never a bare float * 100), the keys come from the
 * encrypted Settings, and webhooks are checked with the secret key itself
 * (what Paystack signs with), not a separate secret that was never set.
 */
final class Paystack
{
    private const URL = 'https://api.paystack.co';

    private const BANKS_KEY = 'paystack:banks:kenya:v1';

    public function __construct(private readonly string $secret) {}

    public static function diocese(): self
    {
        $secret = (string) app(Settings::class)->system('giving.paystack_secret');
        if ($secret === '') {
            throw new RuntimeException('Paystack isn\'t set up yet - add the keys in Settings, Online giving.');
        }

        return new self($secret);
    }

    public static function ready(): bool
    {
        return (string) app(Settings::class)->system('giving.paystack_secret') !== '';
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Did Paystack send this body? HMAC-SHA512 of the raw body with the secret key. */
    public function signed(string $body, ?string $signature): bool
    {
        return $signature !== null && $signature !== '' && hash_equals(hash_hmac('sha512', $body, $this->secret), $signature);
    }

    private function http()
    {
        return Http::timeout(30)->withToken($this->secret)->acceptJson();
    }

    private function check($r, string $what): array
    {
        if (! $r->successful() || $r->json('status') === false) {
            throw new RuntimeException("Paystack {$what}: ".($r->json('message') ?? 'answered '.$r->status()).'.');
        }

        return (array) $r->json();
    }

    /**
     * Start a payment on Paystack's page. With a subaccount the money is the
     * subaccount's, Paystack's fee too (bearer subaccount), and $charge (the
     * diocese share) comes to the main account.
     */
    public function initialize(float $amount, string $email, string $reference, string $callbackUrl, array $metadata = [], ?string $subaccount = null, float $charge = 0, ?array $channels = null): array
    {
        $body = [
            'amount' => self::cents($amount), 'email' => $email, 'currency' => 'KES', 'reference' => $reference,
            'callback_url' => $callbackUrl, 'metadata' => $metadata,
        ];
        if ($channels) {
            $body['channels'] = $channels;
        }
        if ($subaccount) {
            $body['subaccount'] = $subaccount;
            $body['bearer'] = 'subaccount';
            if ($charge > 0) {
                $body['transaction_charge'] = self::cents($charge);
            }
        }

        return $this->check($this->http()->post(self::URL.'/transaction/initialize', $body), 'refused the payment')['data'] ?? [];
    }

    public function verify(string $reference): array
    {
        return $this->check($this->http()->get(self::URL.'/transaction/verify/'.rawurlencode($reference)), 'couldn\'t check the payment')['data'] ?? [];
    }

    public function createSubaccount(string $name, string $bankCode, string $accountNumber, string $description = ''): array
    {
        return $this->check($this->http()->post(self::URL.'/subaccount', [
            'business_name' => mb_substr($name, 0, 100), 'settlement_bank' => $bankCode, 'account_number' => $accountNumber,
            'percentage_charge' => 0, 'description' => $description,
        ]), 'refused the subaccount')['data'] ?? [];
    }

    public function updateSubaccount(string $code, array $data): array
    {
        return $this->check($this->http()->put(self::URL.'/subaccount/'.rawurlencode($code), $data), 'refused the change')['data'] ?? [];
    }

    /** Payouts: a subaccount's (code) or the main account's ('none'). */
    public function settlements(string $subaccount, ?string $from = null): array
    {
        $q = ['subaccount' => $subaccount, 'perPage' => 100];
        if ($from) {
            $q['from'] = $from;
        }

        return $this->check($this->http()->get(self::URL.'/settlement', $q), 'couldn\'t list payouts')['data'] ?? [];
    }

    /**
     * Kenyan banks Paystack can pay into, keyed by its bank code - one of each
     * (Paystack lists every bank twice), cached a day. Empty if Paystack
     * can't be reached; the form then says so.
     *
     * @return array<string, string>
     */
    public function banks(): array
    {
        $cached = Cache::get(self::BANKS_KEY);
        if (is_array($cached) && $cached) {
            return $cached;
        }
        try {
            $rows = $this->check($this->http()->get(self::URL.'/bank', ['country' => 'kenya', 'perPage' => 200]), 'couldn\'t list banks')['data'] ?? [];
            $banks = collect($rows)->filter(fn ($b) => filled($b['code'] ?? null) && filled($b['name'] ?? null) && ($b['active'] ?? true))
                ->unique('code')->mapWithKeys(fn ($b) => [(string) $b['code'] => trim($b['name'])])->sort(fn ($a, $b) => strcasecmp($a, $b))->all();
            if ($banks) {
                Cache::put(self::BANKS_KEY, $banks, now()->addDay());
            }

            return $banks;
        } catch (Throwable $e) {
            Log::warning('Paystack bank list unavailable: '.$e->getMessage());

            return [];
        }
    }
}
