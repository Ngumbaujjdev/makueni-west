<?php

namespace App\Reports\Demographics;

use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;

/** Holy Communion participation on its own - the report behind the Holy Communion tab on Spiritual Activities. */
final class HolyCommunionReport extends ActivityReport
{
    public function key(): string
    {
        return 'demographics.holy_communion';
    }

    public function title(): string
    {
        return 'Holy Communion';
    }

    public function description(): string
    {
        return 'Holy Communion participation for each period, compared with membership, with insights.';
    }

    public function icon(): string
    {
        return 'ri-cup-line';
    }

    protected function field(): string
    {
        return 'communion_participants_count';
    }

    protected function tone(): string
    {
        return 'warning';
    }

    protected function rules(): array
    {
        return [new HolyCommunionRule, new ReportingGapsRule];
    }
}
