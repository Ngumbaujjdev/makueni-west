<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;
use Carbon\CarbonImmutable;

/** Sundays that happened but nobody recorded. Facts: `coverage` (recorded, elapsed, percentage, missing dates). */
final class SundayCoverageRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $c = $facts->get('coverage');
        if (! $c || ($c['elapsed'] ?? 0) === 0) {
            return null;
        }
        $missing = $c['missing'] ?? [];
        if ($missing === []) {
            return new Insight(Insight::GOOD, 'Every Sunday is recorded', "All {$c['elapsed']} Sundays so far have a count.");
        }
        $count = count($missing);
        $dates = implode(', ', array_map(fn ($d) => CarbonImmutable::parse($d)->format('j M'), array_slice($missing, 0, 5))).($count > 5 ? ' and '.($count - 5).' more' : '');

        return new Insight(
            $count >= 3 || ($c['percentage'] ?? 100) < 75 ? Insight::CONCERN : Insight::WATCH,
            "{$count} ".($count === 1 ? 'Sunday' : 'Sundays').' not recorded',
            "{$c['recorded']} of {$c['elapsed']} Sundays recorded ({$c['percentage']}%). Missing: {$dates}.",
            'Agree who sends the count after each service - an usher or the church secretary - and record the missing Sundays while people still remember them.',
        );
    }
}
