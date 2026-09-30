<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;
use Carbon\CarbonImmutable;

/** A Sunday well above the usual. Facts: `top_sundays`, `sunday_average`. */
final class PeakSundayRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $top = $facts->get('top_sundays', [])[0] ?? null;
        $avg = $facts->get('sunday_average');
        if (! $top || ! $avg || $top['total'] < $avg * 1.25) {
            return null;
        }
        $above = (int) round(($top['total'] - $avg) / $avg * 100);

        return new Insight(
            Insight::GOOD,
            'Best Sunday: '.CarbonImmutable::parse($top['date'])->format('j M Y'),
            number_format($top['total'])." attended, {$above}% above the usual ".number_format($avg).'.',
            'Note what drew people that day - a special service, a guest or an event - and plan more Sundays like it.',
        );
    }
}
