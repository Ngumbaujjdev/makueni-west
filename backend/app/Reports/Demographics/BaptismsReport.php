<?php

namespace App\Reports\Demographics;

use App\Support\Reports\Insights\Rules\ReportingGapsRule;
use App\Support\Reports\Insights\Rules\SacramentsRule;

/** Baptisms on its own - the report behind the Baptisms tab on Spiritual Activities. */
final class BaptismsReport extends ActivityReport
{
    public function key(): string
    {
        return 'demographics.baptisms';
    }

    public function title(): string
    {
        return 'Baptisms';
    }

    public function description(): string
    {
        return 'Baptisms for each period, compared with membership, with insights.';
    }

    public function icon(): string
    {
        return 'ri-drop-line';
    }

    protected function field(): string
    {
        return 'baptisms_count';
    }

    protected function tone(): string
    {
        return 'primary';
    }

    protected function rules(): array
    {
        return [new SacramentsRule, new ReportingGapsRule];
    }
}
