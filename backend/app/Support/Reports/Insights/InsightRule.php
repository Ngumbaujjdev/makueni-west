<?php

namespace App\Support\Reports\Insights;

interface InsightRule
{
    /** An insight when the facts say something worth saying, otherwise null. */
    public function evaluate(ReportFacts $facts): ?Insight;
}
