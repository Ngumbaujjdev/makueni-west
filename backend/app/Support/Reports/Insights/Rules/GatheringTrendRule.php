<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** One gathering's average attendance against the period before. Facts: `gathering` (average, previous_average), `previous_label`. */
final class GatheringTrendRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $g = $facts->get('gathering');
        $now = $g['average'] ?? null;
        $before = $g['previous_average'] ?? null;
        if (! $now || ! $before) {
            return null;
        }
        $change = (int) round(($now - $before) / $before * 100);
        $against = $facts->get('previous_label');
        if (abs($change) < 5) {
            return new Insight(Insight::GOOD, 'Attendance is steady', "About {$now} each time, close to {$before} in {$against}.");
        }
        if ($change > 0) {
            return new Insight(Insight::GOOD, "Attendance is up {$change}%", "{$now} each time against {$before} in {$against}.");
        }

        return new Insight(
            $change <= -20 ? Insight::CONCERN : Insight::WATCH,
            'Attendance is down '.abs($change).'%',
            "{$now} each time against {$before} in {$against}.",
            'Ask the regulars what changed - the time, the place or the leader - and invite back those who stopped coming.',
        );
    }
}
