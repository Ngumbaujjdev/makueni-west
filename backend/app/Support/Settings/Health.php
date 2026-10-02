<?php

namespace App\Support\Settings;

use App\Models\MessageLog;
use App\Services\Settings\Settings;
use App\Services\Sms\Sms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings > System health (docs/specs/settings-spec.md, S4): one tile per
 * thing the system depends on - email, SMS, background jobs, the
 * scheduler and disk space - each "ok" or "check" with a plain sentence.
 */
final class Health
{
    public const HEARTBEAT = 'mwd:scheduler-heartbeat';

    public function __construct(private Settings $settings, private Sms $sms) {}

    public function report(bool $withBalance = true): array
    {
        return [
            'tiles' => [$this->email(), $this->sms($withBalance), $this->queue(), $this->scheduler(), $this->storage()],
            'counts' => $this->counts(),
            'app' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'settings_changed' => $this->changedCount(),
            ],
        ];
    }

    private function tile(string $key, string $label, string $icon, string $status, string $value, string $detail, array $actions = [], ?string $fix = null): array
    {
        return compact('key', 'label', 'icon', 'status', 'value', 'detail', 'actions', 'fix');
    }

    private function email(): array
    {
        $mailer = config('mail.default');
        $last = MessageLog::latestFor('mail');
        $from = config('mail.from.address');
        if ($mailer !== 'smtp') {
            return $this->tile('email', 'Email', 'ri-mail-send-line', 'check', 'Log only', 'Emails are written to the log, not sent. Set up a mail server so password resets and reports reach people.', ['test-email'], 'email');
        }
        $detail = 'SMTP · '.(config('mail.mailers.smtp.host') ?: 'no server').($from ? " · from {$from}" : '');
        if ($last && $last->status === 'failed') {
            return $this->tile('email', 'Email', 'ri-mail-send-line', 'check', 'Last one failed', "{$detail}. Last email to {$last->to} failed: {$last->error}", ['test-email'], 'email');
        }

        return $this->tile('email', 'Email', 'ri-mail-send-line', 'ok', 'Working', $detail.($last ? '. Last sent '.$last->created_at->diffForHumans().'.' : '.'), ['test-email'], 'email');
    }

    private function sms(bool $withBalance): array
    {
        $place = $this->settings->systemPlace();
        $driver = $this->settings->get('sms.driver', $place);
        $last = MessageLog::latestFor('sms');
        if ($driver !== 'africastalking') {
            return $this->tile('sms', 'SMS', 'ri-message-3-line', 'check', 'Log only', 'Messages are written to the log, not sent. Add your Africa\'s Talking details to send real SMS.', ['test-sms'], 'sms');
        }
        $sender = $this->settings->get('sms.sender_id', $place) ?: 'default sender';
        $balance = $withBalance ? $this->sms->balance() : null;
        $detail = "Africa's Talking".($this->settings->get('sms.sandbox', $place) ? ' (sandbox)' : '')." · {$sender}".($balance ? " · balance {$balance}" : '');
        if ($last && $last->status === 'failed') {
            return $this->tile('sms', 'SMS', 'ri-message-3-line', 'check', 'Last one failed', "{$detail}. Last SMS to {$last->to} failed: {$last->error}", ['test-sms'], 'sms');
        }

        return $this->tile('sms', 'SMS', 'ri-message-3-line', 'ok', 'Working', $detail.($last ? '. Last sent '.$last->created_at->diffForHumans().'.' : '.'), ['test-sms'], 'sms');
    }

    private function queue(): array
    {
        try {
            $waiting = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $failed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return $this->tile('queue', 'Background jobs', 'ri-stack-line', 'check', 'Unknown', "Couldn't read the job tables.");
        }
        $oldMinutes = $oldest ? (int) floor((time() - (int) $oldest) / 60) : 0;
        $detail = "{$waiting} waiting · {$failed} failed".($oldMinutes > 0 ? " · oldest {$oldMinutes} min" : '').'. Reports and messages go through here.';
        if ($failed > 0) {
            return $this->tile('queue', 'Background jobs', 'ri-stack-line', 'check', "{$failed} failed", $detail, ['retry-failed']);
        }
        if ($oldMinutes >= 15) {
            return $this->tile('queue', 'Background jobs', 'ri-stack-line', 'check', 'Stuck', "{$detail} Is the queue worker running (php artisan queue:work)?");
        }

        return $this->tile('queue', 'Background jobs', 'ri-stack-line', 'ok', $waiting ? "{$waiting} waiting" : 'Working', $detail);
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT);
        if (! $beat) {
            return $this->tile('scheduler', 'Scheduler', 'ri-time-line', 'check', 'Not running', 'Nothing has run on schedule yet - daily clean-ups and reminders won\'t happen. Start php artisan schedule:work (or a cron entry).');
        }
        $seconds = now()->diffInSeconds(\Carbon\Carbon::parse($beat), true);
        $ago = \Carbon\Carbon::parse($beat)->diffForHumans();

        return $seconds <= 180
            ? $this->tile('scheduler', 'Scheduler', 'ri-time-line', 'ok', 'Working', "Last ran {$ago}.")
            : $this->tile('scheduler', 'Scheduler', 'ri-time-line', 'check', 'Stopped', "Last ran {$ago} - it should run every minute.");
    }

    private function storage(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return $this->tile('storage', 'Storage', 'ri-hard-drive-2-line', 'check', 'Unknown', "Couldn't read the disk.");
        }
        $pct = (int) round($free / $total * 100);
        $gb = fn ($b) => number_format($b / 1024 ** 3, 1).' GB';
        $detail = "{$gb($free)} free of {$gb($total)}. Logos, uploads and report files.";

        return $this->tile('storage', 'Storage', 'ri-hard-drive-2-line', $pct < 10 ? 'check' : 'ok', "{$pct}% free", $detail);
    }

    private function counts(): array
    {
        try {
            $month = MessageLog::where('created_at', '>=', now()->startOfMonth());

            return [
                'emails' => (clone $month)->where('channel', 'mail')->count(),
                'sms' => (clone $month)->where('channel', 'sms')->count(),
                'failed' => (clone $month)->where('status', 'failed')->count(),
            ];
        } catch (\Throwable) {
            return ['emails' => 0, 'sms' => 0, 'failed' => 0];
        }
    }

    private function changedCount(): int
    {
        try {
            return \App\Models\Setting::count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
