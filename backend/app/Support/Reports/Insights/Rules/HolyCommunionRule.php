<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Holy Communion participation as a share of members in the latest period. Facts: `latest`. */
final class HolyCommunionRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $latest = $facts->get('latest');
        $total = (int) ($latest['total_members'] ?? 0);
        if (! $latest || $total === 0 || ($latest['communion_participants_count'] ?? null) === null) {
            return null;
        }
        $share = (int) round($latest['communion_participants_count'] / $total * 100);
        if ($share > 100) {
            // Counted per service, so over a half-year or year people are
            // counted more than once - a percentage of members would mislead.
            return new Insight(
                Insight::GOOD,
                'Holy Communion was well attended',
                number_format($latest['communion_participants_count'])." participations in {$latest['label']}, across {$total} members.",
            );
        }
        if ($share < 30) {
            return new Insight(
                Insight::WATCH,
                "{$share}% of members took Holy Communion",
                "{$latest['communion_participants_count']} of {$total} members in {$latest['label']}.",
                'Teach on the meaning of Holy Communion and prepare members through catechism classes.',
            );
        }
        if ($share >= 60) {
            return new Insight(Insight::GOOD, "{$share}% of members took Holy Communion", "{$latest['communion_participants_count']} of {$total} members in {$latest['label']}.");
        }

        return null;
    }
}
