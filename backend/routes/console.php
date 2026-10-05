<?php

use App\Models\ReportRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Report files are kept for a week; the run row (and its verification code)
// stays so a printed copy can still be verified. See docs/specs/reports-spec.md.
Artisan::command('reports:prune', function () {
    $count = 0;
    ReportRun::whereNotNull('file_path')->where('expires_at', '<', now())->each(function (ReportRun $run) use (&$count) {
        Storage::disk('local')->delete($run->file_path);
        $run->forceFill(['file_path' => null])->save();
        $count++;
    });
    $this->info("Removed {$count} expired report file(s).");
})->purpose('Delete report files older than a week');

Schedule::command('reports:prune')->daily();

// Message text is kept for the preview for MessageLog::KEEP_BODY_DAYS, then
// cleared - the row (who, when, status) stays. docs/specs/settings-spec.md, S6c.
Artisan::command('messages:prune-bodies', function () {
    $count = \App\Models\MessageLog::whereNotNull('body')
        ->where('created_at', '<', now()->subDays(\App\Models\MessageLog::KEEP_BODY_DAYS))
        ->update(['body' => null, 'body_cleared_at' => now()]);
    $this->info("Cleared the text of {$count} old message(s).");
})->purpose('Clear the text of messages older than 90 days');

Schedule::command('messages:prune-bodies')->daily();

// Settings > System health checks this heartbeat to tell whether the
// scheduler (php artisan schedule:work, or cron) is running.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Support\Settings\Health::HEARTBEAT, now()->toIso8601String()))
    ->everyMinute()->name('settings-heartbeat');
