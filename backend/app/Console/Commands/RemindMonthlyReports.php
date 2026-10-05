<?php

namespace App\Console\Commands;

use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Notifications\PlaceNotification;
use App\Services\Activities\Activities;
use App\Services\Messaging\PlaceMessenger;
use App\Services\Reports\MonthlyReports;
use App\Support\ReportsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reminds churches and regions of a monthly report not sent yet
 * (docs/specs/monthly-reports-spec.md): 3 days before the due day, on it,
 * and 3 days after - in the app, and by SMS through the place's own or the
 * diocese's sender (logged in Settings > Messages). Each place gets each
 * reminder once, even if this runs twice.
 */
class RemindMonthlyReports extends Command
{
    protected $signature = 'reports:remind {--date= : Pretend today is this day (YYYY-MM-DD)}';

    protected $description = 'Remind churches and regions of a monthly report that is due or late';

    private const STAGES = [-3 => 'soon', 0 => 'today', 3 => 'late'];

    public function handle(MonthlyReports $reports, Activities $activities, PlaceMessenger $messenger): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date'), MonthlyReports::TZ) : CarbonImmutable::now(MonthlyReports::TZ);
        $today = $today->startOfDay();
        $places = Territory::whereIn('territory_type', ReportsAccess::REPORTING_LEVELS)->get();
        $sent = 0;

        foreach ($places as $place) {
            // The month whose due day is near: last month, or (a due day in the first days) the one before.
            foreach ([$today->subMonthNoOverflow(), $today->subMonthsNoOverflow(2)] as $m) {
                $due = $reports->dueOn($place, $m->year, $m->month);
                $offset = (int) $due->diffInDays($today, false);
                $stage = self::STAGES[$offset] ?? null;
                if (! $stage) {
                    continue;
                }
                $report = MonthlyReport::where('territory_id', $place->id)->where('year', $m->year)->where('month', $m->month)->first();
                if ($report && in_array($report->status, ['sent', 'seen'], true)) {
                    continue;
                }
                if (! Cache::add("report-remind:{$place->id}:{$m->format('Y-m')}:{$stage}", true, now()->addDays(5))) {
                    continue;
                }
                $sent += $this->remind($place, $m, $due, $stage, $activities, $messenger);
            }
        }
        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    private function remind(Territory $place, CarbonImmutable $month, CarbonImmutable $due, string $stage, Activities $activities, PlaceMessenger $messenger): int
    {
        $name = $month->format('F');
        $when = $due->format('D j M');
        [$title, $body] = match ($stage) {
            'soon' => ["{$name}'s report is due in 3 days", "Due on {$when}. The figures are already filled in - add a few words and send it."],
            'today' => ["{$name}'s report is due today", 'The figures are already filled in - add a few words and send it.'],
            default => ["{$name}'s report is 3 days late", "It was due on {$when}. It only takes a few minutes - the figures are filled in."],
        };
        $level = $place->territory_type->value;
        $url = "/{$level}/monthly-reports/report?year={$month->year}&month={$month->month}";
        $count = 0;
        foreach ($activities->leadersWith([(int) $place->id], ReportsAccess::ABILITIES['write']) as $user) {
            $user->notify(new PlaceNotification('reminder', $title, $body, $url, $place, 'ri-file-chart-line'));
            $count++;
        }
        $text = match ($stage) {
            'soon' => "{$place->name}: {$name}'s monthly report is due on {$when}. Open Monthly reports to send it.",
            'today' => "{$place->name}: {$name}'s monthly report is due today. Open Monthly reports to send it.",
            default => "{$place->name}: {$name}'s monthly report was due on {$when}. Please send it when you can.",
        };
        foreach ($activities->leadersWith([(int) $place->id], ReportsAccess::ABILITIES['send']) as $user) {
            if ($user->phone) {
                $messenger->sms($place, $user->phone, $text, 'report_reminder');
                $count++;
            }
        }

        return $count;
    }
}
