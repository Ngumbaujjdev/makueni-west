<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Places below with no budget for the period - or, when every one has
 * one, that they all do. Facts (BudgetRollup): `rows`, `words`, `places`,
 * `none`, `period_label`.
 */
final class BudgetRollupCoverageRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        [$one, $many] = $facts->get('words', ['church', 'churches']);
        $places = (int) $facts->get('places', 0);
        $none = (int) $facts->get('none', 0);
        $label = $facts->get('period_label');
        if ($places === 0) {
            return null;
        }
        if ($none === 0) {
            return new Insight(Insight::GOOD, "Every {$one} has a budget for {$label}", "All {$places} {$many} have planned the period.");
        }
        $names = array_column(array_filter($facts->get('rows', []), fn ($r) => $r['status'] === 'none'), 'name');
        $shown = array_slice($names, 0, 5);

        return new Insight(
            $none * 2 >= $places ? Insight::CONCERN : Insight::WATCH,
            ($none === 1 ? "1 {$one} has" : "{$none} {$many} have")." no budget for {$label}",
            implode(', ', $shown).(count($names) > 5 ? ' and '.(count($names) - 5).' more' : '').'.',
            'Ask them to prepare one, or copy their last budget, so their money in and out can be followed.',
        );
    }
}
