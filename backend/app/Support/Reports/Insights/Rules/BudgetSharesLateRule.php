<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Months whose share (e.g. the diocese share) was not all sent after the
 * month ended - seen in the app, no message needed.
 * Facts: `late_shares` - BudgetRollup::contributionsOf() rows with status "late".
 */
final class BudgetSharesLateRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $late = (array) $facts->get('late_shares', []);
        if ($late === []) {
            return null;
        }
        $total = array_sum(array_column($late, 'owed'));
        $months = array_unique(array_column($late, 'label'));

        return new Insight(
            Insight::CONCERN,
            (count($months) === 1 ? '1 month\'s' : count($months).' months\'').' share still to send',
            implode(', ', array_slice($months, 0, 6)).(count($months) > 6 ? ' and '.(count($months) - 6).' more' : '').' - KES '.number_format($total, 2).' in all.',
            'Send it, then record it as an expense on the '.($late[0]['line'] ?? 'share').' line - Contributions shows each month.',
        );
    }
}
