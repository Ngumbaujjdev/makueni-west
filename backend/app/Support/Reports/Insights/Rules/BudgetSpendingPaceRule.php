<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Spending against how much of the period has gone (only while the period
 * is running). Facts: `time_pct`, `out_planned`, `out_actual`, `period_label`.
 */
final class BudgetSpendingPaceRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $time = $facts->get('time_pct');
        $planned = (float) $facts->get('out_planned');
        if ($time === null || $planned <= 0 || $time < 10) {
            return null;
        }
        $spent = (float) $facts->get('out_actual') / $planned * 100;
        $gap = $spent - $time;
        if ($gap >= 15) {
            return new Insight(
                Insight::WATCH,
                'Spending is running ahead',
                round($spent).'% of the money out planned is already spent, with '.round($time).'% of '.$facts->get('period_label').' gone.',
                'Hold back on anything that can wait until later in the period.',
            );
        }
        if ($spent > 0 && $gap <= 5) {
            return new Insight(Insight::GOOD, 'Spending is on track', round($spent).'% of the money out planned is spent, with '.round($time).'% of the period gone.');
        }

        return null;
    }
}
