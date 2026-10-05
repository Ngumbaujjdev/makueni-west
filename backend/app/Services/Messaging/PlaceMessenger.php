<?php

namespace App\Services\Messaging;

use App\Listeners\LogSentEmail;
use App\Models\MessageLog;
use App\Models\Territory;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Services\Sms\Sms;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Email and SMS sent for a church or region (docs/specs/settings-spec.md,
 * S6b). Settings > Communication decides how they go out:
 *   - "diocese" (the default): through the diocese's Email and SMS, with the
 *     place's display name as the From name, its reply-to, and its SMS
 *     signature;
 *   - "own": through the place's own SMTP server and Africa's Talking
 *     account - each channel only once it's filled in, otherwise the
 *     diocese's.
 * Every send is written to message_logs with its text, secrets masked.
 */
final class PlaceMessenger
{
    /** The mail ports a place's own server may use. */
    public const PORTS = [25, 465, 587, 2525];

    public function __construct(private Settings $settings, private Sms $sms) {}

    /** How messages for this place go out right now - for the senders and the view-only card. */
    public function channels(Territory $place): array
    {
        $get = fn (string $key) => ($v = $this->settings->get($key, $place)) === '' ? null : $v;
        $mode = $get('comms.mode') ?: 'diocese';
        $ownMail = $mode === 'own' && $get('comms.mail.host') && $get('comms.mail.from_address');
        $ownSms = $mode === 'own' && $get('comms.sms.username') && $get('comms.sms.api_key');
        $diocese = $this->sms->dioceseAccount();

        return [
            'mode' => $mode,
            'locked_by' => $this->settings->resolve('comms.mode', $place)['locked_by'],
            'display_name' => $get('comms.display_name') ?: $place->name,
            'reply_to' => $get('comms.reply_to'),
            'sms_signature' => $get('comms.sms_signature'),
            'email' => $ownMail
                ? ['via' => 'own', 'from_address' => $get('comms.mail.from_address'), 'server' => $get('comms.mail.host'), 'sends' => true]
                : ['via' => 'diocese', 'from_address' => config('mail.from.address'), 'server' => null, 'sends' => config('mail.default') !== 'log'],
            'sms' => $ownSms
                ? ['via' => 'own', 'sender_id' => $get('comms.sms.sender_id'), 'sends' => true]
                : ['via' => 'diocese', 'sender_id' => $diocese['sender_id'], 'sends' => $diocese['driver'] === 'africastalking'],
            // "own" chosen but not filled in yet: that channel still goes through the diocese.
            'own_incomplete' => $mode === 'own' ? array_values(array_filter(['email' => ! $ownMail ? 'email' : null, 'sms' => ! $ownSms ? 'sms' : null])) : [],
        ];
    }

    /**
     * @param  string[]  $secrets  text to mask in the stored copy (a temporary password, a code)
     * @return array{ok: bool, status: string, error: ?string, via: string, log_id: ?int}
     */
    public function email(Territory $place, string $to, string $subject, string $html, string $kind, array $secrets = [], ?User $by = null): array
    {
        $c = $this->channels($place);
        $own = $c['email']['via'] === 'own';
        $fromAddress = $own ? $c['email']['from_address'] : (string) config('mail.from.address');
        $fromName = $c['display_name'];
        $status = 'sent';
        $error = null;

        try {
            if ($own) {
                $host = (string) $this->settings->get('comms.mail.host', $place);
                if ($problem = self::hostProblem($host)) {
                    throw new \RuntimeException($problem);
                }
                $mailer = Mail::build([
                    'transport' => 'smtp',
                    'host' => $host,
                    'port' => (int) ($this->settings->get('comms.mail.port', $place) ?: 587),
                    'scheme' => $this->settings->get('comms.mail.scheme', $place) ?: null,
                    'username' => $this->settings->get('comms.mail.username', $place) ?: null,
                    'password' => $this->settings->get('comms.mail.password', $place) ?: null,
                    'timeout' => 20,
                ]);
            } else {
                $mailer = Mail::mailer();
                $status = config('mail.default') === 'log' ? 'logged' : 'sent';
            }
            $mailer->html($html, function (Message $m) use ($to, $subject, $fromAddress, $fromName, $c) {
                $m->to($to)->subject($subject)->from($fromAddress, $fromName);
                if ($c['reply_to']) {
                    $m->replyTo($c['reply_to'], $fromName);
                }
                $m->getSymfonyMessage()->getHeaders()->addTextHeader(LogSentEmail::LOGGED_HEADER, '1');
            });
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 1000);
        }

        $log = $this->log([
            'channel' => 'mail', 'kind' => $kind, 'via' => $c['email']['via'], 'to' => $to,
            'from' => "{$fromName} <{$fromAddress}>", 'reply_to' => $c['reply_to'], 'subject' => $subject,
            'body' => MessageLog::mask($html, $secrets), 'body_type' => 'html',
            'status' => $status, 'error' => $error, 'territory_id' => $place->id, 'sent_by' => $by?->id,
        ]);

        return ['ok' => $status !== 'failed', 'status' => $status, 'error' => $error, 'via' => $c['email']['via'], 'log_id' => $log?->id];
    }

    /**
     * @param  string[]  $secrets
     * @return array{ok: bool, status: string, error: ?string, via: string, log_id: ?int}
     */
    public function sms(Territory $place, string $to, string $text, string $kind, array $secrets = [], ?User $by = null): array
    {
        $c = $this->channels($place);
        $own = $c['sms']['via'] === 'own';
        $account = $own ? [
            'driver' => 'africastalking',
            'username' => (string) $this->settings->get('comms.sms.username', $place),
            'api_key' => (string) $this->settings->get('comms.sms.api_key', $place),
            'sender_id' => $this->settings->get('comms.sms.sender_id', $place) ?: null,
            'sandbox' => (bool) $this->settings->get('comms.sms.sandbox', $place),
        ] : $this->sms->dioceseAccount();
        $message = $c['sms_signature'] ? "{$text}\n- {$c['sms_signature']}" : $text;

        $result = $this->sms->sendWith($account, $to, $message, [
            'territory_id' => $place->id, 'sent_by' => $by?->id, 'via' => $c['sms']['via'], 'kind' => $kind, 'secrets' => $secrets,
        ]);

        return ['ok' => $result['ok'], 'status' => $result['status'], 'error' => $result['error'], 'via' => $c['sms']['via'], 'log_id' => $result['log_id']];
    }

    /**
     * Why a place's own mail server can't be used, or null. It must resolve,
     * and only to public addresses - a church can't point the server at
     * itself or the network it sits in.
     */
    public static function hostProblem(string $host): ?string
    {
        $host = trim($host);
        if ($host === '') {
            return 'Enter the mail server.';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return "Couldn't find the mail server \"{$host}\".";
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That mail server is on a private or local network address, which isn\'t allowed.';
            }
        }

        return null;
    }

    private function log(array $row): ?MessageLog
    {
        try {
            return MessageLog::create($row);
        } catch (\Throwable) {
            return null; // logging a send must never break the send
        }
    }
}
