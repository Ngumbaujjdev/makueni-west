<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * No budget for the period, a budget still a draft, or nothing recorded for
 * a while. Facts: `status` (null = no budget), `period_label`, `days_since_entry`, `running`.
 */
final class BudgetStatusRule implements InsightRule
{
    private const QUIET_DAYS = 14;

    public function evaluate(ReportFacts $facts): ?Insight
    {
        $label = $facts->get('period_label');

        return match (true) {
            $facts->get('status') === null => new Insight(Insight::WATCH, "No budget for {$label}", "Nothing has been planned for {$label} yet.", 'Prepare one - "Copy amounts" fills it from the last budget in a minute.'),
            $facts->get('status') === 'draft' => new Insight(Insight::WATCH, "The {$label} budget is still a draft", 'Money can be recorded once it is in use.', 'Open it and press Start using when it is ready.'),
            $facts->get('running') && $facts->get('days_since_entry') === null => new Insight(Insight::WATCH, 'Nothing recorded yet', "No money in or out has been recorded for {$label}.", 'Record Sunday\'s offerings and the bills paid so the budget shows where things stand.'),
            $facts->get('running') && $facts->get('days_since_entry') >= self::QUIET_DAYS => new Insight(Insight::WATCH, 'Nothing recorded for '.$facts->get('days_since_entry').' days', 'The last money in or out was recorded '.$facts->get('days_since_entry').' days ago.', 'Catch up on the offerings and payments since then.'),
            default => null,
        };
    }
}
