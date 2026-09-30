<?php

namespace App\Reports\Attendance;

use App\Support\Reports\Insights\Rules\QuietGatheringRule;
use App\Support\Reports\Insights\Rules\TopGatheringRule;

final class MinistriesReport extends GatheringsReport
{
    public function key(): string
    {
        return 'attendance.ministries';
    }

    public function title(): string
    {
        return 'Ministry gatherings';
    }

    public function description(): string
    {
        return 'Every ministry - how often it met, how many came, and which ones have gone quiet.';
    }

    public function icon(): string
    {
        return 'ri-group-line';
    }

    public function subject(): string
    {
        return 'Ministry gatherings report';
    }

    protected function slug(): string
    {
        return AttendanceData::MINISTRY;
    }

    protected function noun(): string
    {
        return 'ministry';
    }

    protected function plural(): string
    {
        return 'ministries';
    }

    protected function rules(): array
    {
        return [QuietGatheringRule::ministries(), TopGatheringRule::ministries()];
    }
}
