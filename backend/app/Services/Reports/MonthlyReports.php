<?php

namespace App\Services\Reports;

use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Notifications\PlaceNotification;
use App\Services\Activities\Activities;
use App\Services\Settings\Settings;
use App\Support\PlaceAccess;
use App\Support\ReportsAccess;
use Carbon\CarbonImmutable;

/**
 * Monthly reports (docs/specs/monthly-reports-spec.md): when one is due,
 * what state a month is in, and who is told when it is sent, seen or
 * commented on.
 */
final class MonthlyReports
{
    public const TZ = 'Africa/Nairobi';

    public function __construct(private Settings $settings, private Activities $activities) {}

    /** Only the diocese sets it, so every place below has the same day - worked out once. */
    private ?int $dueDay = null;

    public function dueDay(Territory $place): int
    {
        if ($this->dueDay === null) {
            $day = (int) $this->settings->get('reports.monthly_due_day', $place);
            $this->dueDay = $day >= 1 && $day <= 28 ? $day : 5;
        }

        return $this->dueDay;
    }

    /** The month's report is due on this day of the next month. */
    public function dueOn(Territory $place, int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TZ)->addMonthNoOverflow()->day($this->dueDay($place));
    }

    /**
     * A month's state for a place: sent, seen, draft or not_started - and
     * whether it is late (not sent by the end of the due day), or not open
     * yet (the month hasn't started).
     */
    public function state(Territory $place, int $year, int $month, ?MonthlyReport $report): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $due = $this->dueOn($place, $year, $month);
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TZ);
        // Months before monthly reports began aren't asked for - never late.
        $before = ! $report && sprintf('%04d-%02d', $year, $month) < (string) config('app.monthly_reports_from', '2026-09');
        $status = $report?->status ?? ($before ? 'not_tracked' : 'not_started');
        $sent = in_array($status, ['sent', 'seen'], true);
        $sentOn = $report?->sent_at ? CarbonImmutable::instance($report->sent_at)->setTimezone(self::TZ) : null;

        return [
            'year' => $year,
            'month' => $month,
            'label' => $start->format('F Y'),
            'short' => $start->format('M'),
            'status' => $status,
            'id' => $report?->id,
            'due_on' => $due->toDateString(),
            'open' => $start->lte($today),
            'late' => ! $sent && ! $before && $today->gt($due),
            'on_time' => $sent ? $sentOn->startOfDay()->lte($due) : null,
            'due_in_days' => $sent ? null : (int) $today->diffInDays($due, false),
            'sent_at' => $report?->sent_at?->toIso8601String(),
            'seen_at' => $report?->seen_at?->toIso8601String(),
            'comments' => $report ? ($report->comments_count ?? $report->comments()->count()) : 0,
        ];
    }

    /** The place a report is sent to: a church's region, a region's diocese. */
    public function above(Territory $place): ?Territory
    {
        foreach (PlaceAccess::ancestors($place) as $t) {
            if (in_array($t->territory_type?->value, ['region', 'diocese'], true)) {
                return $t;
            }
        }

        return null;
    }

    /** Where a report is opened, for someone acting at this level. */
    public function url(MonthlyReport $report, string $viewerLevel, bool $own): string
    {
        return $own
            ? "/{$viewerLevel}/monthly-reports/report?year={$report->year}&month={$report->month}"
            : "/{$viewerLevel}/monthly-reports/report?id={$report->id}";
    }

    /** Sent: the leaders above who can review it. */
    public function notifySent(MonthlyReport $report): void
    {
        $place = $report->territory;
        $above = $this->above($place);
        if (! $above) {
            return;
        }
        $level = $above->territory_type->value;
        foreach ($this->activities->leadersWith([(int) $above->id], ReportsAccess::ABILITIES['review']) as $user) {
            $user->notify(new PlaceNotification('report', "{$place->name}: {$report->label()} report", 'Sent - open it to read and comment.', $this->url($report, $level, false), $place, 'ri-file-chart-line'));
        }
    }

    /** Seen: the place's leaders who write reports. */
    public function notifySeen(MonthlyReport $report, Territory $by): void
    {
        $place = $report->territory;
        foreach ($this->activities->leadersWith([(int) $place->id], ReportsAccess::ABILITIES['write']) as $user) {
            $user->notify(new PlaceNotification('report', "{$report->label()} report", "{$by->name} has read it.", $this->url($report, $place->territory_type->value, true), $by, 'ri-eye-line'));
        }
    }

    /** A comment: the other side of the thread. */
    public function notifyComment(MonthlyReport $report, Territory $from, string $body): void
    {
        $place = $report->territory;
        $own = (int) $from->id === (int) $place->id;
        $to = $own ? $this->above($place) : $place;
        if (! $to) {
            return;
        }
        $ability = $own ? ReportsAccess::ABILITIES['review'] : ReportsAccess::ABILITIES['write'];
        $short = mb_strlen($body) > 120 ? mb_substr($body, 0, 117).'...' : $body;
        foreach ($this->activities->leadersWith([(int) $to->id], $ability) as $user) {
            $user->notify(new PlaceNotification('report', "Comment on {$place->name}'s {$report->label()} report", "{$from->name}: {$short}", $this->url($report, $to->territory_type->value, ! $own), $from, 'ri-chat-3-line'));
        }
    }
}
