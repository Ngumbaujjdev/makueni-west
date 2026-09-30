<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;
use Carbon\CarbonImmutable;

/**
 * How regularly one ministry or event meets: never, gone quiet, or how
 * often in the period. Facts: `gathering` (name, noun, times, status, last,
 * share), `period_label`, `monthly`.
 */
final class GatheringActivityRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $g = $facts->get('gathering');
        if (! $g) {
            return null;
        }
        $period = $facts->get('period_label');
        if ($g['status'] === 'never') {
            return new Insight(Insight::WATCH, "{$g['name']} hasn't been recorded yet",
                "No meeting of this {$g['noun']} has been recorded.",
                $g['noun'] === 'ministry' ? 'If it still meets, ask its leader to send the count after each meeting. If not, mark it inactive under Gathering types.' : null);
        }
        if ($g['status'] === 'quiet') {
            return new Insight(Insight::CONCERN, "{$g['name']} hasn't met for over 60 days",
                'Last met on '.CarbonImmutable::parse($g['last'])->format('j M Y').'.',
                'Check in with its leader - it may need a new leader or a better time, or to be merged with another ministry.');
        }
        $months = collect($facts->get('monthly', []));
        $withMeeting = $months->filter(fn ($m) => $m['meetings'] > 0)->count();
        $detail = "Met {$g['times']} ".($g['times'] === 1 ? 'time' : 'times')." in {$period}".($months->count() > 1 ? ", in {$withMeeting} of {$months->count()} months" : '').'.';
        if ($g['share'] !== null && $g['share'] >= 25 && $g['noun'] === 'ministry') {
            $detail .= " It draws {$g['share']}% of everyone at ministry gatherings.";
        }

        // A ministry that met in under half the months meets only now and then (events are occasional by nature).
        if ($g['noun'] === 'ministry' && $months->count() >= 3 && $withMeeting / $months->count() < 0.5) {
            return new Insight(Insight::WATCH, "{$g['name']} meets only now and then", $detail,
                'Agree a regular day and time with its leader, and record every meeting so the pattern is clear.');
        }

        return new Insight(Insight::GOOD, "{$g['name']} is meeting", $detail);
    }
}
