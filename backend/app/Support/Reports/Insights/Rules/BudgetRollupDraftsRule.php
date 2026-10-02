<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Places below whose budget is still a draft, so no money can be recorded on it. Facts (BudgetRollup): `rows`, `drafts`, `words`. */
final class BudgetRollupDraftsRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $drafts = (int) $facts->get('drafts', 0);
        if ($drafts === 0) {
            return null;
        }
        [$one, $many] = $facts->get('words', ['church', 'churches']);
        $names = array_column(array_filter($facts->get('rows', []), fn ($r) => $r['status'] === 'draft'), 'name');

        return new Insight(
            Insight::WATCH,
            ($drafts === 1 ? "1 {$one}'s budget is" : "{$drafts} {$many}' budgets are").' still a draft',
            implode(', ', array_slice($names, 0, 5)).($drafts > 5 ? ' and '.($drafts - 5).' more' : '').'.',
            'Money can only be recorded once a budget is started - remind them to start using it.',
        );
    }
}
