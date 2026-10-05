<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivitySession;
use Carbon\CarbonImmutable;

/**
 * An initiative's sessions (docs/specs/events-initiatives-spec.md, L2):
 * generated from how often it meets between its first and last day, and
 * regenerated when that changes - keeping every session that was held or has
 * attendance, replacing only the untouched planned ones.
 */
final class Sessions
{
    public const MAX = 104;

    /** Dates are the diocese's own days, not UTC's. */
    public const LOCAL_TZ = 'Africa/Nairobi';

    /** @return string[] Y-m-d dates the initiative meets on */
    public static function dates(Activity $activity): array
    {
        if ($activity->kind !== 'initiative' || ! $activity->frequency) {
            return [];
        }
        $start = CarbonImmutable::parse($activity->starts_at)->setTimezone(self::LOCAL_TZ)->startOfDay();
        $end = CarbonImmutable::parse($activity->ends_at)->setTimezone(self::LOCAL_TZ)->startOfDay();
        $dates = [];

        switch ($activity->frequency) {
            case 'once':
                $dates[] = $start;
                break;
            case 'weekly':
            case 'fortnightly':
                $day = $start;
                if ($activity->meeting_day !== null) {
                    while ($day->dayOfWeek !== (int) $activity->meeting_day) {
                        $day = $day->addDay();
                    }
                }
                for (; $day->lte($end) && count($dates) < self::MAX; $day = $day->addDays($activity->frequency === 'weekly' ? 7 : 14)) {
                    $dates[] = $day;
                }
                break;
            case 'monthly':
            case 'quarterly':
                $step = $activity->frequency === 'monthly' ? 1 : 3;
                for ($i = 0; count($dates) < self::MAX; $i++) {
                    $month = $start->startOfMonth()->addMonthsNoOverflow($i * $step);
                    $day = $month->day(min($start->day, $month->daysInMonth));
                    if ($day->gt($end)) {
                        break;
                    }
                    $dates[] = $day;
                }
                break;
        }

        return array_map(fn (CarbonImmutable $d) => $d->toDateString(), $dates);
    }

    /** Make the sessions match the schedule; held or recorded ones stay. */
    public static function sync(Activity $activity, ?int $userId = null): void
    {
        if ($activity->kind !== 'initiative') {
            return;
        }
        $existing = $activity->sessions()->get();
        $untouched = $existing->filter(fn (ActivitySession $s) => $s->untouched());
        ActivitySession::whereIn('id', $untouched->pluck('id')->all() ?: [0])->delete();
        $kept = $existing->diff($untouched)->map(fn (ActivitySession $s) => $s->held_on->toDateString())->all();

        // Generated sessions aren't history - one line per session would bury what people did.
        ActivitySession::disableAuditing();
        try {
            foreach (self::dates($activity) as $date) {
                if (! in_array($date, $kept, true)) {
                    ActivitySession::create(['activity_id' => $activity->id, 'number' => 0, 'held_on' => $date, 'status' => 'planned', 'updated_by' => $userId]);
                }
            }
        } finally {
            ActivitySession::enableAuditing();
        }
        self::renumber($activity);
    }

    /** Sessions are numbered 1..n in date order. */
    public static function renumber(Activity $activity): void
    {
        foreach ($activity->sessions()->get()->values() as $i => $session) {
            if ($session->number !== $i + 1) {
                $session->forceFill(['number' => $i + 1])->saveQuietly();
            }
        }
    }

    /** Held, total, attendance and the next planned date. */
    public static function summary(Activity $activity): array
    {
        $sessions = $activity->relationLoaded('sessions') ? $activity->sessions : $activity->sessions()->get();
        $held = $sessions->where('status', 'held');
        $attendance = (int) $held->sum(fn (ActivitySession $s) => (int) $s->attendance());
        $today = now(self::LOCAL_TZ)->toDateString();

        return [
            'total' => $sessions->where('status', '!=', 'cancelled')->count(),
            'held' => $held->count(),
            'attendance' => $attendance,
            'average' => $held->count() ? (int) round($attendance / $held->count()) : null,
            'next' => $sessions->where('status', 'planned')->first(fn (ActivitySession $s) => $s->held_on->toDateString() >= $today)?->held_on?->toDateString(),
        ];
    }
}
