<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * More gone out than came in so far.
 * Facts: `in_actual`, `out_actual`.
 */
final class BudgetBalanceRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $left = (float) $facts->get('in_actual') - (float) $facts->get('out_actual');
        if ((float) $facts->get('out_actual') <= 0 || $left >= 0) {
            return null;
        }

        return new Insight(
            Insight::CONCERN,
            'More has gone out than came in',
            'Expenses are KES '.number_format(-$left, 2).' more than income so far.',
            'Record any money received that is missing, and pause spending that isn\'t urgent.',
        );
    }
}
