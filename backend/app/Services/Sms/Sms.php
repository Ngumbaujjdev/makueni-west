<?php

namespace App\Services\Sms;

use App\Models\MessageLog;
use App\Services\Settings\Settings;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends an SMS through the gateway set in Settings > SMS
 * (docs/specs/settings-spec.md, S4), or a church's own Africa's Talking
 * account (S6b): Africa's Talking, or - until that's set up - just the log.
 * Every message is written to message_logs, with its text (secrets masked).
 */
final class Sms
{
    public function __construct(private Settings $settings) {}

    /**
     * Send through the diocese's gateway (Settings > SMS).
     *
     * @return array{ok: bool, status: string, error: ?string, provider_ref: ?string, to: string, log_id: ?int}
     */
    public function send(string $to, string $message, ?int $territoryId = null, ?int $sentBy = null): array
    {
        return $this->sendWith($this->dioceseAccount(), $to, $message, ['territory_id' => $territoryId, 'sent_by' => $sentBy, 'via' => 'diocese']);
    }

    /** The diocese's gateway: {driver, username, api_key, sender_id, sandbox}. */
    public function dioceseAccount(): array
    {
        $place = $this->settings->systemPlace();

        $driver = $this->settings->get('sms.driver', $place) ?: 'log';

        return [
            'driver' => $driver,
            'username' => (string) $this->settings->get('sms.username', $place),
            'api_key' => (string) $this->settings->get('sms.api_key', $place),
            'sender_id' => $this->settings->get('sms.sender_id', $place) ?: ($driver === 'textsms' ? config('services.textsms.shortcode') : null),
            'sandbox' => (bool) $this->settings->get('sms.sandbox', $place),
        ];
    }

    /** Whether this account really sends (rather than writing to the log). */
    public static function sendsForReal(array $account): bool
    {
        return in_array($account['driver'] ?? 'log', ['africastalking', 'textsms'], true);
    }

    /**
     * Send through a given account - the diocese's, or a church's own
     * (S6b) - and log it with $log (territory_id, sent_by, via, kind,
     * secrets: masked in the stored copy).
     *
     * @return array{ok: bool, status: string, error: ?string, provider_ref: ?string, to: string, log_id: ?int}
     */
    public function sendWith(array $account, string $to, string $message, array $log = []): array
    {
        $log += ['body' => MessageLog::mask($message, $log['secrets'] ?? []), 'from' => $account['sender_id'] ?? null];
        $number = self::kenya($to);
        if (! $number) {
            return $this->record($to, 'failed', null, Phone::problem($to) ?? "That doesn't look like a phone number.", $log);
        }

        if (($account['driver'] ?? 'log') === 'textsms') {
            return $this->sendTextSms($number, $message, $log);
        }

        if (($account['driver'] ?? 'log') !== 'africastalking') {
            Log::info("SMS (log only) to {$number}: ".$log['body']);

            return $this->record($number, 'logged', null, null, $log);
        }

        if (($account['username'] ?? '') === '' || ($account['api_key'] ?? '') === '') {
            return $this->record($number, 'failed', null, "Africa's Talking needs a username and an API key.", $log);
        }

        $payload = ['username' => $account['username'], 'to' => $number, 'message' => $message];
        if (! empty($account['sender_id'])) {
            $payload['from'] = $account['sender_id'];
        }

        try {
            $res = Http::asForm()->acceptJson()->timeout(15)
                ->withHeaders(['apiKey' => $account['api_key']])
                ->post(self::base($account['sandbox'] ?? false).'/version1/messaging', $payload);
        } catch (\Throwable $e) {
            return $this->record($number, 'failed', null, "Couldn't reach Africa's Talking: ".$e->getMessage(), $log);
        }

        $recipient = $res->json('SMSMessageData.Recipients.0');
        if ($res->successful() && $recipient && in_array((int) ($recipient['statusCode'] ?? 0), [100, 101, 102], true)) {
            return $this->record($number, 'sent', $recipient['messageId'] ?? null, null, $log);
        }

        $error = $recipient['status'] ?? $res->json('SMSMessageData.Message') ?? trim(substr($res->body(), 0, 200)) ?: "HTTP {$res->status()}";

        return $this->record($number, 'failed', null, "Africa's Talking said: {$error}", $log);
    }

    /**
     * TextSMS (textsms.co.ke), with the account from the server's .env
     * (config services.textsms) - the same call as the makueni sibling app:
     * POST JSON {apikey, partnerID, mobile, message, shortcode}.
     */
    private function sendTextSms(string $number, string $message, array $log): array
    {
        $c = config('services.textsms');
        if (empty($c['api_key']) || empty($c['partner_id']) || empty($c['shortcode'])) {
            return $this->record($number, 'failed', null, "TextSMS isn't set up on the server (TEXTSMS_API_KEY, TEXTSMS_PARTNER_ID and TEXTSMS_SHORTCODE).", $log);
        }

        try {
            $res = Http::acceptJson()->asJson()->timeout(15)->post($c['url'], [
                'apikey' => $c['api_key'],
                'partnerID' => (string) $c['partner_id'],
                'mobile' => ltrim($number, '+'),
                'message' => $message,
                'shortcode' => $c['shortcode'],
                'pass_type' => 'plain',
            ]);
        } catch (\Throwable $e) {
            return $this->record($number, 'failed', null, "Couldn't reach TextSMS: ".$e->getMessage(), $log);
        }

        // TextSMS spells it "respose-code" in its replies.
        $r = $res->json('responses.0') ?? [];
        $code = (int) ($r['respose-code'] ?? $r['response-code'] ?? 0);
        if ($res->successful() && $code === 200) {
            return $this->record($number, 'sent', isset($r['messageid']) ? (string) $r['messageid'] : null, null, $log);
        }
        $r = $r ?: ($res->json() ?? []);
        $error = $r['response-description'] ?? trim(substr($res->body(), 0, 200)) ?: "HTTP {$res->status()}";
        if (stripos($error, 'mobile') !== false) {
            $error .= " ({$number}) - check the number";
        }
        // 1006: the key or partner ID in .env is wrong (or was regenerated in the TextSMS dashboard).
        $hint = (int) ($r['response-code'] ?? $r['respose-code'] ?? 0) === 1006 ? ' - check TEXTSMS_API_KEY and TEXTSMS_PARTNER_ID in the server\'s .env' : '';

        return $this->record($number, 'failed', null, "TextSMS said: {$error}{$hint}", $log);
    }

    /** The account balance (Health), or null when not set up or unreachable. */
    public function balance(): ?string
    {
        $place = $this->settings->systemPlace();
        if ($this->settings->get('sms.driver', $place) !== 'africastalking') {
            return null;
        }
        try {
            $res = Http::acceptJson()->timeout(8)
                ->withHeaders(['apiKey' => (string) $this->settings->get('sms.api_key', $place)])
                ->get(self::base($this->settings->get('sms.sandbox', $place)).'/version1/user', ['username' => $this->settings->get('sms.username', $place)]);

            return $res->successful() ? $res->json('UserData.balance') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "0712 345 678", "712345678", "+254712345678" -> "+254712345678"; null if it isn't one. */
    public static function kenya(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^(?:254|0)?([17]\d{8})$/', $digits, $m)) {
            return '+254'.$m[1];
        }

        // Another country's number, with its + - never a Kenyan one with the wrong number of digits.
        return Phone::problem($phone) === null && str_starts_with(trim((string) $phone), '+') ? '+'.$digits : null;
    }

    private static function base(mixed $sandbox): string
    {
        return $sandbox ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
    }

    private function record(string $to, string $status, ?string $ref, ?string $error, array $log): array
    {
        $id = null;
        try {
            $id = MessageLog::create([
                'channel' => 'sms', 'to' => $to, 'status' => $status, 'provider_ref' => $ref, 'error' => $error,
                'territory_id' => $log['territory_id'] ?? null, 'sent_by' => $log['sent_by'] ?? null,
                'kind' => $log['kind'] ?? null, 'via' => $log['via'] ?? null, 'from' => $log['from'] ?? null,
                'body' => $log['body'] ?? null, 'body_type' => 'text',
            ])->id;
        } catch (\Throwable) {
            // no table yet - the send still counts
        }

        return ['ok' => $status !== 'failed', 'status' => $status, 'error' => $error, 'provider_ref' => $ref, 'to' => $to, 'log_id' => $id];
    }
}
