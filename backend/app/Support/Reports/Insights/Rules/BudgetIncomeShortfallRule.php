<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Income arriving slower than the period is passing (or short at its end).
 * Facts: `time_pct` (null once the period is over), `in_planned`, `in_actual`, `period_label`, `ended`.
 */
final class BudgetIncomeShortfallRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $planned = (float) $facts->get('in_planned');
        if ($planned <= 0) {
            return null;
        }
        $received = (float) $facts->get('in_actual');
        $pct = $received / $planned * 100;
        $ended = (bool) $facts->get('ended');
        $time = $ended ? 100 : $facts->get('time_pct');
        if ($time === null || $time < 25) {
            return null;
        }
        if ($pct < $time - 20) {
            return new Insight(
                $ended ? Insight::CONCERN : Insight::WATCH,
                $ended ? 'Income fell short' : 'Income is behind',
                'KES '.number_format($received, 2).' received of KES '.number_format($planned, 2).' planned ('.round($pct).'%)'.($ended ? '' : ', with '.round($time).'% of the period gone').'.',
                'Make sure every Sunday\'s offerings and tithes are recorded, and plan spending around what has come in.',
            );
        }
        if ($pct >= 100) {
            return new Insight(Insight::GOOD, 'Income has reached the plan', 'KES '.number_format($received, 2).' received - '.round($pct).'% of what was planned.');
        }

        return null;
    }
}
