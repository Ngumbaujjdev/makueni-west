<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** How many members come on a typical Sunday. Facts: `membership` (total_members, rate), `sunday_average`. */
final class AttendanceVsMembershipRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $m = $facts->get('membership');
        $avg = $facts->get('sunday_average');
        if (! $m || ($m['rate'] ?? null) === null || ! $avg) {
            return null;
        }
        $rate = $m['rate'];
        $detail = number_format($avg).' at an average Sunday, from '.number_format($m['total_members']).' members'.($m['as_of'] ? " ({$m['as_of']})" : '').'.';

        if ($rate > 110) {
            return new Insight(Insight::WATCH, 'More people attend than are members', $detail,
                'Invite regular visitors to a membership class, and check the member count in the latest Demographics submission is up to date.');
        }
        if ($rate >= 70) {
            return new Insight(Insight::GOOD, "About {$rate}% of members come on a typical Sunday", $detail);
        }

        return new Insight(
            $rate < 40 ? Insight::CONCERN : Insight::WATCH,
            "Only about {$rate}% of members come on a typical Sunday",
            $detail,
            'Find out who has drifted away - fellowship leaders can visit the members they haven\'t seen, and the member list may need cleaning up.',
        );
    }
}
