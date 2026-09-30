<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** The ministry or event that draws the most people. Facts: the list under `$key` (name, average, times). */
final class TopGatheringRule implements InsightRule
{
    public function __construct(private string $key, private string $plural) {}

    public static function ministries(): self
    {
        return new self('ministries', 'ministries');
    }

    public static function events(): self
    {
        return new self('events', 'events');
    }

    public function evaluate(ReportFacts $facts): ?Insight
    {
        $held = array_values(array_filter($facts->get($this->key, []), fn ($r) => $r['times'] > 0));
        if ($held === []) {
            return null;
        }
        usort($held, fn ($a, $b) => $b['average'] <=> $a['average']);
        $top = $held[0];

        return new Insight(
            Insight::GOOD,
            "{$top['name']} draws the most people",
            'About '.number_format($top['average']).' each time'.($top['times'] > 1 ? " over {$top['times']} meetings" : '').'.',
            count($held) > 1 ? "Use {$top['name']} to invite people to the smaller {$this->plural} and to Sunday service." : null,
        );
    }
}
