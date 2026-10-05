<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Expense lines that have gone over what was planned.
 * Facts: `over_lines` ([{name, over}], biggest first), `period_label`.
 */
final class BudgetOverPlanRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $over = $facts->get('over_lines', []);
        if (! $over) {
            return null;
        }
        $first = $over[0];
        $title = count($over) === 1
            ? "{$first['name']} is over plan by KES ".number_format($first['over'], 2)
            : count($over).' lines are over plan';
        $detail = count($over) === 1
            ? "More has been spent on {$first['name']} than the budget planned for ".$facts->get('period_label').'.'
            : 'Over plan: '.implode(', ', array_map(fn ($l) => "{$l['name']} (KES ".number_format($l['over'], 2).')', array_slice($over, 0, 4))).'.';

        return new Insight(Insight::CONCERN, $title, $detail, 'Check what the extra spending was for, and either cut back or change the budget so it matches what is really needed.');
    }
}
