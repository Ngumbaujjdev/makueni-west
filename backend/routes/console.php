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

// Monthly reports: a reminder 3 days before the due day, on it, and 3 days
// after - in the app and by SMS. docs/specs/monthly-reports-spec.md.
Schedule::command('reports:remind')->dailyAt('08:00')->timezone('Africa/Nairobi');

// Messages scheduled for later go out when they're due. docs/specs/messages-spec.md.
Schedule::command('messages:send-scheduled')->everyMinute()->withoutOverlapping();

// Visitors' details are removed after the months set in Settings > Visitors
// with no visit (0 = never). Members are never touched; counts stay.
// docs/specs/people-and-care-spec.md, "Retention".
Artisan::command('people:retention', function (\App\Services\Settings\Settings $settings) {
    $count = 0;
    $today = \Carbon\CarbonImmutable::now('Africa/Nairobi')->startOfDay();
    $visitors = fn () => \App\Models\Person::query()->where('status', 'visitor')->whereNotNull('stage')->whereNull('anonymised_at');
    foreach (\App\Models\Territory::whereIn('id', $visitors()->distinct()->pluck('territory_id'))->get() as $church) {
        $months = (int) $settings->get('people.retention_visitor_months', $church);
        if ($months <= 0) {
            continue;
        }
        $cutoff = $today->subMonthsNoOverflow($months)->toDateString();
        $visitors()->where('territory_id', $church->id)->whereRaw('coalesce(last_visit_on, date(created_at)) < ?', [$cutoff])->get()
            ->each(function (\App\Models\Person $p) use (&$count) {
                $p->anonymise();
                $count++;
            });
    }
    $this->info("Removed the details of {$count} visitor(s) with no recent visit.");
})->purpose("Remove visitors' details after the months each church chose");

Schedule::command('people:retention')->dailyAt('02:30')->timezone('Africa/Nairobi');

// Facilities (P5): the day before a service, text the people on duty - each church at its own time (off by default).
Schedule::command('facilities:duty-reminders')->hourlyAt(0)->timezone('Africa/Nairobi');

// Approvals (docs/specs/accounting-spec.md, A4): remind late approvers, then pass it up.
Schedule::command('approvals:escalate')->hourly()->withoutOverlapping();
