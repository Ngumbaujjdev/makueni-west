<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Membership growth or decline between the first and last reported period. Facts: `rows` (oldest first). */
final class MembershipTrendRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $rows = array_values(array_filter($facts->get('rows', []), fn ($r) => ($r['total_members'] ?? null) !== null));
        if (count($rows) < 2) {
            return null;
        }
        $first = $rows[0];
        $last = end($rows);
        $from = (int) $first['total_members'];
        $to = (int) $last['total_members'];
        if ($from === 0) {
            return null;
        }
        $pct = round(($to - $from) / $from * 100, 1);
        $span = "{$first['label']} to {$last['label']}";

        if ($pct >= 2) {
            return new Insight(Insight::GOOD, "Membership grew {$pct}%", number_format($from).' → '.number_format($to)." members, {$span}.");
        }
        if ($pct <= -2) {
            return new Insight(
                Insight::CONCERN,
                'Membership fell '.abs($pct).'%',
                number_format($from).' → '.number_format($to)." members, {$span}.",
                'Follow up with members who have stopped attending, and look at what changed over this period.',
            );
        }

        return new Insight(Insight::GOOD, 'Membership held steady', number_format($to)." members, within 2% of {$first['label']}.");
    }
}
