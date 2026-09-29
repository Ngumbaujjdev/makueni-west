<?php

namespace App\Support\Reports\Insights;

/**
 * Runs a report's chosen rules and orders the findings concern -> watch ->
 * good, so the things that need attention come first.
 */
final class InsightEngine
{
    private const ORDER = [Insight::CONCERN => 0, Insight::WATCH => 1, Insight::GOOD => 2];

    /**
     * @param  InsightRule[]  $rules
     * @return Insight[]
     */
    public static function run(array $rules, ReportFacts $facts, int $limit = 8): array
    {
        $insights = array_values(array_filter(array_map(fn (InsightRule $rule) => $rule->evaluate($facts), $rules)));
        usort($insights, fn (Insight $a, Insight $b) => self::ORDER[$a->tone] <=> self::ORDER[$b->tone]);

        return array_slice($insights, 0, $limit);
    }
}
