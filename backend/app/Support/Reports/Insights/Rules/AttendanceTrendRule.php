<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Sunday attendance up or down: against the period before when there is
 * one, otherwise the latest month against the period's usual.
 * Facts: `sunday_average`, `previous_average`, `previous_label`, `months`, `period_label`.
 */
final class AttendanceTrendRule implements InsightRule
{
    private const NOTICE = 5;

    public function evaluate(ReportFacts $facts): ?Insight
    {
        $now = $facts->get('sunday_average');
        $before = $facts->get('previous_average');
        $against = $facts->get('previous_label');
        if (! $now) {
            return null;
        }
        if (! $before) {
            $months = $facts->get('months', []);
            if (count($months) < 2) {
                return null;
            }
            $latest = end($months);
            $period = $facts->get('period_label');
            [$now, $before, $subject, $against] = [$latest['average'], $facts->get('sunday_average'), "Sunday attendance in {$latest['label']}", $period === 'All time' ? 'all time' : "all of {$period}"];
        } else {
            $subject = 'Sunday attendance';
        }
        $change = (int) round(($now - $before) / $before * 100);
        if (abs($change) < self::NOTICE) {
            return new Insight(Insight::GOOD, "{$subject} is steady", 'About '.number_format($now)." a Sunday, close to {$against} (".number_format($before).').');
        }
        if ($change > 0) {
            return new Insight(Insight::GOOD, "{$subject} is up {$change}%", number_format($now).' a Sunday against '.number_format($before)." for {$against}.");
        }

        return new Insight(
            $change <= -15 ? Insight::CONCERN : Insight::WATCH,
            "{$subject} is down ".abs($change).'%',
            number_format($now).' a Sunday against '.number_format($before)." for {$against}.",
            "Follow up members who've stopped coming - a visit or a call from their cell or fellowship leader often brings them back.",
        );
    }
}
