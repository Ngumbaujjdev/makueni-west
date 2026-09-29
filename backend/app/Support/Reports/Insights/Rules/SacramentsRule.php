<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Baptisms and conversions across the report. Facts: `rows`. */
final class SacramentsRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $rows = $facts->get('rows', []);
        if (count($rows) < 2) {
            return null;
        }
        $baptisms = array_sum(array_map(fn ($r) => (int) ($r['baptisms_count'] ?? 0), $rows));
        $conversions = array_sum(array_map(fn ($r) => (int) ($r['conversions_count'] ?? 0), $rows));

        if ($baptisms === 0 && $conversions === 0) {
            return new Insight(
                Insight::WATCH,
                'No baptisms or conversions recorded',
                'Across '.count($rows).' reported periods.',
                'Plan an evangelism outreach and a baptism class for new believers.',
            );
        }

        return new Insight(Insight::GOOD, "{$baptisms} baptisms and {$conversions} conversions", 'Across '.count($rows).' reported periods.');
    }
}
