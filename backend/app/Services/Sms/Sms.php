<?php

namespace App\Services\Sms;

use App\Models\MessageLog;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends an SMS through the gateway set in Settings > SMS
 * (docs/specs/settings-spec.md, S4): Africa's Talking, or - until that's
 * set up - just the log. Every message is written to message_logs.
 */
final class Sms
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array{ok: bool, status: string, error: ?string, provider_ref: ?string, to: string}
     */
    public function send(string $to, string $message, ?int $territoryId = null, ?int $sentBy = null): array
    {
        $number = self::kenya($to);
        if (! $number) {
            return $this->record($to, 'failed', null, "That doesn't look like a phone number.", $territoryId, $sentBy);
        }
        $place = $this->settings->systemPlace();
        $driver = $this->settings->get('sms.driver', $place) ?: 'log';

        if ($driver !== 'africastalking') {
            Log::info("SMS (log only) to {$number}: {$message}");

            return $this->record($number, 'logged', null, null, $territoryId, $sentBy);
        }

        $username = (string) $this->settings->get('sms.username', $place);
        $apiKey = (string) $this->settings->get('sms.api_key', $place);
        if ($username === '' || $apiKey === '') {
            return $this->record($number, 'failed', null, "Africa's Talking needs a username and an API key.", $territoryId, $sentBy);
        }

        $payload = ['username' => $username, 'to' => $number, 'message' => $message];
        if ($sender = $this->settings->get('sms.sender_id', $place)) {
            $payload['from'] = $sender;
        }

        try {
            $res = Http::asForm()->acceptJson()->timeout(15)
                ->withHeaders(['apiKey' => $apiKey])
                ->post(self::base($this->settings->get('sms.sandbox', $place)).'/version1/messaging', $payload);
        } catch (\Throwable $e) {
            return $this->record($number, 'failed', null, "Couldn't reach Africa's Talking: ".$e->getMessage(), $territoryId, $sentBy);
        }

        $recipient = $res->json('SMSMessageData.Recipients.0');
        if ($res->successful() && $recipient && in_array((int) ($recipient['statusCode'] ?? 0), [100, 101, 102], true)) {
            return $this->record($number, 'sent', $recipient['messageId'] ?? null, null, $territoryId, $sentBy);
        }

        $error = $recipient['status'] ?? $res->json('SMSMessageData.Message') ?? trim(substr($res->body(), 0, 200)) ?: "HTTP {$res->status()}";

        return $this->record($number, 'failed', null, "Africa's Talking said: {$error}", $territoryId, $sentBy);
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

        return strlen($digits) >= 10 && str_starts_with(trim((string) $phone), '+') ? '+'.$digits : null;
    }

    private static function base(mixed $sandbox): string
    {
        return $sandbox ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
    }

    private function record(string $to, string $status, ?string $ref, ?string $error, ?int $territoryId, ?int $sentBy): array
    {
        try {
            MessageLog::create(['channel' => 'sms', 'to' => $to, 'status' => $status, 'provider_ref' => $ref, 'error' => $error, 'territory_id' => $territoryId, 'sent_by' => $sentBy]);
        } catch (\Throwable) {
            // no table yet - the send still counts
        }

        return ['ok' => $status !== 'failed', 'status' => $status, 'error' => $error, 'provider_ref' => $ref, 'to' => $to];
    }
}
