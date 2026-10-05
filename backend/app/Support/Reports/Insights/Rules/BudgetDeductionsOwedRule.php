<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Deductions due on the money received but not all sent yet.
 * Facts: `deductions` - [['name', 'line', 'due', 'sent', 'owed'], ...] (only when worked out on the whole budget).
 */
final class BudgetDeductionsOwedRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $owed = array_values(array_filter((array) $facts->get('deductions', []), fn ($d) => ($d['owed'] ?? 0) > 0));
        if ($owed === []) {
            return null;
        }
        $total = array_sum(array_column($owed, 'owed'));
        $first = $owed[0];

        return new Insight(
            Insight::WATCH,
            'KES '.number_format($total, 2).' still owed in deductions',
            count($owed) === 1
                ? "{$first['name']}: KES ".number_format($first['due'], 2).' due on the money received, KES '.number_format($first['sent'], 2).' sent so far.'
                : implode('; ', array_map(fn ($d) => "{$d['name']} KES ".number_format($d['owed'], 2), $owed)).'.',
            'When it is sent, record it as an expense on the '.($first['line'] ?? 'deduction').' line, so the budget shows it as paid.',
        );
    }
}
