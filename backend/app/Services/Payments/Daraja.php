<?php

namespace App\Services\Payments;

use App\Models\PaymentChannel;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Safaricom Daraja (docs/specs/accounting-spec.md, A8) - ported from
 * v1-events' MpesaService, with its gaps closed: the token is cached per
 * shortcode and environment (not one global key), credentials come from the
 * encrypted Settings (never plain columns or code), and amounts are whole
 * shillings, rounded - never truncated.
 */
final class Daraja
{
    private const URLS = ['sandbox' => 'https://sandbox.safaricom.co.ke', 'production' => 'https://api.safaricom.co.ke'];

    /** Safaricom's published callback addresses (C2B and STK). */
    public const SAFARICOM_IPS = [
        '196.201.214.200', '196.201.214.206', '196.201.213.114', '196.201.214.207', '196.201.214.208', '196.201.213.44',
        '196.201.212.127', '196.201.212.138', '196.201.212.129', '196.201.212.136', '196.201.212.74', '196.201.212.69',
    ];

    public function __construct(
        public readonly string $environment,
        public readonly string $shortcode,
        private readonly string $key,
        private readonly string $secret,
        private readonly ?string $passkey = null,
        private readonly ?string $initiator = null,
        private readonly ?string $credential = null,
        private readonly ?string $initiatorPassword = null,
    ) {}

    /** The diocese paybill, from Settings > Paybill. */
    public static function diocese(): self
    {
        $s = app(Settings::class);
        foreach (['paybill.shortcode', 'paybill.consumer_key', 'paybill.consumer_secret'] as $k) {
            if (! $s->system($k)) {
                throw new RuntimeException('The paybill isn\'t set up yet - fill in Settings, Paybill.');
            }
        }

        return new self($s->system('paybill.environment') === 'production' ? 'production' : 'sandbox', (string) $s->system('paybill.shortcode'),
            (string) $s->system('paybill.consumer_key'), (string) $s->system('paybill.consumer_secret'), $s->system('paybill.passkey') ?: null,
            $s->system('paybill.initiator_name') ?: null, $s->system('paybill.security_credential') ?: null, $s->system('paybill.initiator_password') ?: null);
    }

    /** A church's own Daraja app (A10b), from its encrypted channel. */
    public static function forChannel(PaymentChannel $channel): self
    {
        $s = $channel->secrets();
        if ($channel->provider !== 'daraja' || ! $channel->mpesaReady()) {
            throw new RuntimeException('That church\'s Daraja app isn\'t fully set up - add its keys on Gateways.');
        }

        return new self(($s['environment'] ?? '') === 'production' ? 'production' : 'sandbox', (string) $channel->account_number,
            (string) $s['consumer_key'], (string) $s['consumer_secret'], (string) $s['passkey']);
    }

    public function isSandbox(): bool
    {
        return $this->environment !== 'production';
    }

    private function url(string $path): string
    {
        return self::URLS[$this->environment].$path;
    }

    /** OAuth token, cached for 55 minutes per app (it lives an hour). */
    private function token(): string
    {
        return Cache::remember('daraja_token:'.$this->environment.':'.$this->shortcode.':'.substr(hash('sha256', $this->key), 0, 12), 55 * 60, function () {
            $r = Http::timeout(20)->withBasicAuth($this->key, $this->secret)->get($this->url('/oauth/v1/generate'), ['grant_type' => 'client_credentials']);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new RuntimeException('Safaricom refused the app\'s key and secret ('.$r->status().').');
            }

            return (string) $r->json('access_token');
        });
    }

    private function post(string $path, array $body): array
    {
        $r = Http::timeout(30)->withToken($this->token())->acceptJson()->post($this->url($path), $body);
        if (! $r->successful()) {
            throw new RuntimeException((string) ($r->json('errorMessage') ?? $r->json('ResponseDescription') ?? 'Safaricom answered '.$r->status().'.'));
        }

        return (array) $r->json();
    }

    /**
     * Send the confirmation and validation addresses to Safaricom. A payment
     * completes even when we can't be reached. Some apps are refused on v1
     * ("Invalid Access Token") and accepted on v2.
     */
    public function registerUrls(string $confirmationUrl, string $validationUrl): array
    {
        $body = ['ShortCode' => $this->shortcode, 'ResponseType' => 'Completed', 'ConfirmationURL' => $confirmationUrl, 'ValidationURL' => $validationUrl];
        try {
            return $this->post('/mpesa/c2b/v1/registerurl', $body);
        } catch (RuntimeException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'access token')) {
                throw $e;
            }

            return $this->post('/mpesa/c2b/v2/registerurl', $body);
        }
    }

    // ------------------------------------------------------------ checking payments (A10f)

    /** Can a payment be checked by its code? Needs an API operator and its credential. */
    public function canCheck(): bool
    {
        return (bool) $this->initiator && ($this->credential || ($this->initiatorPassword && is_file($this->certificatePath())));
    }

    private function certificatePath(): string
    {
        return storage_path("app/daraja/{$this->environment}.cer");
    }

    /**
     * The operator's password encrypted with Safaricom's public certificate
     * (RSA, PKCS#1 v1.5) - as generated on the Daraja portal, or made here from
     * the password and the certificate uploaded for this environment.
     */
    public function securityCredential(): string
    {
        if ($this->credential) {
            return $this->credential;
        }
        $cert = is_file($this->certificatePath()) ? file_get_contents($this->certificatePath()) : false;
        if (! $this->initiatorPassword || ! $cert || ! openssl_public_encrypt($this->initiatorPassword, $out, $cert, OPENSSL_PKCS1_PADDING)) {
            throw new RuntimeException('Checking payments isn\'t set up - add the API operator\'s security credential in Settings, Paybill.');
        }

        return base64_encode($out);
    }

    /**
     * Ask Safaricom about a payment by its M-Pesa code. The answer comes later,
     * to $resultUrl (or $timeoutUrl); this only says the question was taken.
     *
     * @return array{OriginatorConversationID?: string, ConversationID?: string, ResponseCode?: string}
     */
    public function transactionStatus(string $code, string $resultUrl, string $timeoutUrl, string $remarks = 'Payment check'): array
    {
        if (! $this->initiator) {
            throw new RuntimeException('Checking payments isn\'t set up - add the API operator in Settings, Paybill.');
        }

        return $this->post('/mpesa/transactionstatus/v1/query', [
            'Initiator' => $this->initiator, 'SecurityCredential' => $this->securityCredential(), 'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => strtoupper($code), 'PartyA' => $this->shortcode, 'IdentifierType' => '4',
            'ResultURL' => $resultUrl, 'QueueTimeOutURL' => $timeoutUrl, 'Remarks' => mb_substr($remarks, 0, 100), 'Occasion' => 'Giving',
        ]);
    }

    /** Pull Transactions, once: where Safaricom may send the paybill's payments of the last 48 hours. */
    public function pullRegister(string $nominatedNumber, string $callbackUrl): array
    {
        return $this->post('/pulltransactions/v1/register', ['ShortCode' => $this->shortcode, 'RequestType' => 'Pull', 'NominatedNumber' => self::phone($nominatedNumber), 'CallBackURL' => $callbackUrl]);
    }

    /** The paybill's payments in a window (at most the last 48 hours), a page at a time. @return array<int, array> */
    public function pullQuery(string $from, string $to, int $offset = 0): array
    {
        $out = $this->post('/pulltransactions/v1/query', ['ShortCode' => $this->shortcode, 'StartDate' => $from, 'EndDate' => $to, 'OffSetValue' => (string) $offset]);

        return array_values(array_filter((array) (($out['Response'][0] ?? null) ?: []), 'is_array'));
    }

    /** Transaction Status result parameters -> [Key => Value]. */
    public static function resultParameters(array $result): array
    {
        $out = [];
        foreach ((array) ($result['ResultParameters']['ResultParameter'] ?? []) as $p) {
            if (isset($p['Key'])) {
                $out[$p['Key']] = $p['Value'] ?? null;
            }
        }

        return $out;
    }

    /** Sandbox only: a test C2B payment. */
    public function simulate(string $phone, float $amount, string $billRef): array
    {
        if (! $this->isSandbox()) {
            throw new RuntimeException('Test payments are for the sandbox only.');
        }

        return $this->post('/mpesa/c2b/v1/simulate', [
            'ShortCode' => $this->shortcode, 'CommandID' => 'CustomerPayBillOnline', 'Amount' => (int) round($amount), 'Msisdn' => self::phone($phone), 'BillRefNumber' => $billRef,
        ]);
    }

    /** Ask to pay: the M-Pesa prompt on the payer's phone. */
    public function stkPush(string $phone, float $amount, string $accountRef, string $description, string $callbackUrl): array
    {
        if (! $this->passkey) {
            throw new RuntimeException('Add the Lipa na M-Pesa passkey in Settings, Paybill, to ask a phone to pay.');
        }
        $ts = now('Africa/Nairobi')->format('YmdHis');

        return $this->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->shortcode,
            'Password' => base64_encode($this->shortcode.$this->passkey.$ts),
            'Timestamp' => $ts,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) round($amount),
            'PartyA' => self::phone($phone),
            'PartyB' => $this->shortcode,
            'PhoneNumber' => self::phone($phone),
            'CallBackURL' => $callbackUrl,
            'AccountReference' => mb_substr($accountRef, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);
    }

    public function stkQuery(string $checkoutRequestId): array
    {
        $ts = now('Africa/Nairobi')->format('YmdHis');

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->shortcode, 'Password' => base64_encode($this->shortcode.$this->passkey.$ts), 'Timestamp' => $ts, 'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }

    /** 0712 345 678, +254712..., 712... -> 254712345678. */
    public static function phone(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($p, '0')) {
            $p = '254'.substr($p, 1);
        } elseif (strlen($p) === 9 && in_array($p[0], ['7', '1'], true)) {
            $p = '254'.$p;
        }

        return $p;
    }

    public static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^254[17]\d{8}$/', self::phone($phone));
    }

    /** STK callback metadata Item[] -> [Name => Value]. */
    public static function metadata(array $callback): array
    {
        $out = [];
        foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
            if (isset($item['Name'])) {
                $out[$item['Name']] = $item['Value'] ?? null;
            }
        }

        return $out;
    }
}
