<?php

namespace App\Reports\Demographics;

use App\Support\Reports\Insights\Rules\ReportingGapsRule;
use App\Support\Reports\Insights\Rules\SacramentsRule;

/** Conversions on its own - the report behind the Conversions tab on Spiritual Activities. */
final class ConversionsReport extends ActivityReport
{
    public function key(): string
    {
        return 'demographics.conversions';
    }

    public function title(): string
    {
        return 'Conversions';
    }

    public function description(): string
    {
        return 'Conversions for each period, compared with membership, with insights.';
    }

    public function icon(): string
    {
        return 'ri-heart-line';
    }

    protected function field(): string
    {
        return 'conversions_count';
    }

    protected function tone(): string
    {
        return 'purple';
    }

    protected function rules(): array
    {
        return [new SacramentsRule, new ReportingGapsRule];
    }
}
