<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Periods that were due but have no approved submission. Facts: `missing` (labels), `period_noun`. */
final class ReportingGapsRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $missing = $facts->get('missing', []);
        if ($missing === []) {
            return null;
        }
        $noun = $facts->get('period_noun', 'period');
        $count = count($missing);
        $list = implode(', ', array_slice($missing, 0, 6)).($count > 6 ? '…' : '');

        return new Insight(
            $count > 2 ? Insight::CONCERN : Insight::WATCH,
            "{$count} ".($count === 1 ? $noun : "{$noun}s").' not reported',
            "No approved submission for {$list}.",
            'Submit the missing '.($count === 1 ? $noun : "{$noun}s").' so the figures and trends in this report are complete.',
        );
    }
}
