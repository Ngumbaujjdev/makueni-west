<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Youth (13-35) as a share of members in the latest period. Facts: `latest`. */
final class YouthShareRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $latest = $facts->get('latest');
        $total = (int) ($latest['total_members'] ?? 0);
        if (! $latest || $total === 0 || ($latest['youth_count'] ?? null) === null) {
            return null;
        }
        $share = (int) round($latest['youth_count'] / $total * 100);

        if ($share < 20) {
            return new Insight(
                Insight::WATCH,
                "Youth are {$share}% of members",
                "{$latest['youth_count']} of {$total} members are aged 13-35.",
                'Invest in youth ministry - a regular youth service or mentorship pairs can help young people stay and bring friends.',
            );
        }
        if ($share >= 35) {
            return new Insight(Insight::GOOD, "A young congregation: {$share}% youth", "{$latest['youth_count']} of {$total} members are aged 13-35.");
        }

        return null;
    }
}
