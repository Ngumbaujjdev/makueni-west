<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Places below that spent more than they planned. Facts (BudgetRollup): `rows`, `words`. */
final class BudgetRollupOverPlanRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        [$one, $many] = $facts->get('words', ['church', 'churches']);
        $over = array_values(array_filter($facts->get('rows', []), fn ($r) => $r['over'] > 0));
        if ($over === []) {
            return null;
        }
        usort($over, fn ($a, $b) => $b['over'] <=> $a['over']);

        return new Insight(
            Insight::CONCERN,
            (count($over) === 1 ? "1 {$one} is" : count($over)." {$many} are").' over plan',
            implode('; ', array_map(fn ($r) => "{$r['name']} by KES ".number_format($r['over'], 2), array_slice($over, 0, 4))).(count($over) > 4 ? '; and '.(count($over) - 4).' more' : '').'.',
            'Open their budgets to see which lines went over, and talk to their treasurers.',
        );
    }
}
