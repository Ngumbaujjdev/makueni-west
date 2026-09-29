<?php

namespace App\Reports\Demographics;

use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;

/** Departures (members transferred out) on its own - the report behind the Departures tab on Spiritual Activities. */
final class DeparturesReport extends ActivityReport
{
    public function key(): string
    {
        return 'demographics.departures';
    }

    public function title(): string
    {
        return 'Departures';
    }

    public function description(): string
    {
        return 'Departures (members transferred out) for each period, compared with membership, with insights.';
    }

    public function icon(): string
    {
        return 'ri-user-unfollow-line';
    }

    protected function field(): string
    {
        return 'transferred_out_count';
    }

    protected function tone(): string
    {
        return 'danger';
    }

    protected function rules(): array
    {
        return [new DeparturesVsNewMembersRule, new ReportingGapsRule];
    }
}
