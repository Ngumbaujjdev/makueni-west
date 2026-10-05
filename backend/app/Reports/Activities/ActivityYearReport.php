<?php

namespace App\Reports\Activities;

use App\Models\Activity;
use App\Models\FiscalYear;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Activities\Activities;

/** A place's events for a year, with how many places took part and how many came. */
final class ActivityYearReport extends ActivityReport
{
    public function key(): string
    {
        return 'activity.year';
    }

    public function title(): string
    {
        return 'Events this year';
    }

    public function description(): string
    {
        return 'Every event of the year: when, what, status, places taking part, expected and came, and income.';
    }

    public function subject(): string
    {
        return 'events report';
    }

    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    public function build(ReportContext $context): ReportData
    {
        $yearId = $context->param('fiscal_year_id');
        $year = ($yearId && $yearId !== 'all' ? FiscalYear::whereKey($yearId)->value('year') : null) ?: (int) now()->year;
        $service = app(Activities::class);
        $events = Activity::with('registrations')->where('kind', 'event')->where('territory_id', $context->territory->id)
            ->whereYear('starts_at', $year)->orderBy('starts_at')->get();

        $rows = $events->map(function (Activity $a) use ($service) {
            $t = $service->totals($a);

            return [$a->starts_at->format('j M Y'), $a->title, $a->typeLabel(), ucfirst($a->status), $t['places'], $t['expected'], $t['came'] ?? '-', $service->money($a)['in']];
        })->values()->all();
        $byStatus = $events->countBy('status');

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Events this year',
            periodLabel: (string) $year,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Events', 'value' => (string) $events->count(), 'tone' => 'primary'],
                ['label' => 'Done', 'value' => (string) ($byStatus['completed'] ?? 0), 'tone' => 'success'],
                ['label' => 'Coming up', 'value' => (string) ($byStatus['published'] ?? 0), 'tone' => 'purple'],
                ['label' => 'Cancelled', 'value' => (string) ($byStatus['cancelled'] ?? 0), 'tone' => 'danger'],
            ],
            meta: ['Year' => (string) $year, 'Prepared by' => $context->preparedBy(), 'Prepared' => now()->format('j M Y, H:i')],
            sections: [new ReportSection('Events', [
                ReportColumn::text('Date'), ReportColumn::text('Event', true), ReportColumn::text('Kind'), ReportColumn::text('Status'),
                ReportColumn::number('Places', 'sum'), ReportColumn::number('Expected', 'sum'), ReportColumn::number('Came', 'sum'), ReportColumn::money('Income'),
            ], $rows, $rows ? null : "No events in {$year}.")],
            insights: [],
        );
    }
}
