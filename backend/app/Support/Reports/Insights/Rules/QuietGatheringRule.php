<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Ministries that haven't met for a while, or never. Facts: the list under
 * `$key` (rows with name, status).
 */
final class QuietGatheringRule implements InsightRule
{
    public function __construct(private string $key, private string $noun, private string $plural) {}

    public static function ministries(): self
    {
        return new self('ministries', 'ministry', 'ministries');
    }

    public function evaluate(ReportFacts $facts): ?Insight
    {
        $rows = $facts->get($this->key, []);
        if ($rows === []) {
            return null;
        }
        $quiet = array_column(array_filter($rows, fn ($r) => $r['status'] === 'quiet'), 'name');
        $never = array_column(array_filter($rows, fn ($r) => $r['status'] === 'never'), 'name');
        if ($quiet === [] && $never === []) {
            return new Insight(Insight::GOOD, 'Every '.$this->noun.' is meeting', count($rows).' '.(count($rows) === 1 ? $this->noun : $this->plural).' met in the last 60 days.');
        }
        $parts = [];
        if ($quiet) {
            $parts[] = 'No meeting in 60+ days: '.implode(', ', $quiet).'.';
        }
        if ($never) {
            $parts[] = 'Never recorded: '.implode(', ', $never).'.';
        }
        $count = count($quiet) + count($never);

        return new Insight(
            Insight::WATCH,
            "{$count} ".($count === 1 ? "{$this->noun} isn't" : "{$this->plural} aren't").' meeting',
            implode(' ', $parts),
            'Check in with their leaders - a quiet ministry may need a new leader, a better time, or to be merged or retired under Gathering types.',
        );
    }
}
