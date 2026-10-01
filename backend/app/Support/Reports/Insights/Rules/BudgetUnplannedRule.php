<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Money spent on lines the budget didn't plan for.
 * Facts: `unplanned` ([{name, actual}]).
 */
final class BudgetUnplannedRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $lines = array_values(array_filter($facts->get('unplanned', []), fn ($l) => $l['actual'] > 0));
        if (! $lines) {
            return null;
        }
        $total = array_sum(array_column($lines, 'actual'));

        return new Insight(
            Insight::WATCH,
            'KES '.number_format($total, 2).' spent outside the plan',
            'On '.implode(', ', array_column($lines, 'name')).' - lines the budget did not plan for.',
            'If this will happen again, add these lines to the next budget.',
        );
    }
}
