<?php

namespace App\Reports\Attendance;

use App\Support\Reports\Insights\Rules\TopGatheringRule;

final class EventsReport extends GatheringsReport
{
    public function key(): string
    {
        return 'attendance.events';
    }

    public function title(): string
    {
        return 'Special events';
    }

    public function description(): string
    {
        return 'Crusades, baptism services, dedications and other events - when they were held and who came.';
    }

    public function icon(): string
    {
        return 'ri-star-line';
    }

    public function subject(): string
    {
        return 'Special events report';
    }

    protected function slug(): string
    {
        return AttendanceData::EVENT;
    }

    protected function noun(): string
    {
        return 'event';
    }

    protected function plural(): string
    {
        return 'events';
    }

    protected function rules(): array
    {
        return [TopGatheringRule::events()];
    }
}
