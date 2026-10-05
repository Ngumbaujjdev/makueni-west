<?php

namespace App\Reports\Activities;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Activities\Activities;

/** One event: who's coming and who came, the fees, the money, and how it went. */
final class ActivitySummaryReport extends ActivityReport
{
    public function key(): string
    {
        return 'activity.summary';
    }

    public function title(): string
    {
        return 'Event summary';
    }

    public function description(): string
    {
        return 'One event: the places taking part, how many are coming and came, fees, money in and out, and how it went.';
    }

    public function subject(): string
    {
        return 'event summary';
    }

    public function inputs(): array
    {
        return ['activity'];
    }

    public function build(ReportContext $context): ReportData
    {
        $activity = Activity::with(['territory', 'registrations.territory'])->findOrFail((int) $context->param('activity_id'));
        $service = app(Activities::class);
        $totals = $service->totals($activity);
        $money = $service->money($activity);
        $regs = $activity->registrations->where('status', 'registered')->sortBy(fn ($r) => $service->regionOf($r->territory)?->name.' '.$r->territory?->name);

        $rows = $regs->map(fn (ActivityRegistration $r) => [
            $r->territory?->name, $service->regionOf($r->territory)?->name ?? '-',
            $r->youth, $r->adults, $r->children, $r->leaders, $r->expected(), $r->came() ?? '-', (float) $r->fee_due, (float) $r->fee_paid,
        ])->values()->all();

        $sections = [new ReportSection('Who is coming', [
            ReportColumn::text('Place', true), ReportColumn::text('Region'), ReportColumn::number('Youth', 'sum'), ReportColumn::number('Adults', 'sum'),
            ReportColumn::number('Children', 'sum'), ReportColumn::number('Leaders', 'sum'), ReportColumn::number('Expected', 'sum', true),
            ReportColumn::number('Came', 'sum'), ReportColumn::money('Fee due'), ReportColumn::money('Fee paid'),
        ], $rows, $rows ? null : 'No place has registered.')];
        if ($money['entries']) {
            $sections[] = new ReportSection('Money', [ReportColumn::text('Date'), ReportColumn::text('What for', true), ReportColumn::money('In'), ReportColumn::money('Out')],
                array_map(fn ($e) => [$e['date'], $e['description'], $e['direction'] === 'in' ? $e['amount'] : 0, $e['direction'] === 'out' ? $e['amount'] : 0], $money['entries']));
        }

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $activity->title,
            periodLabel: self::when($activity->starts_at, $activity->ends_at),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Places', 'value' => (string) $totals['places'], 'tone' => 'primary'],
                ['label' => 'Expected', 'value' => number_format($totals['expected']), 'tone' => 'purple'],
                ['label' => 'Came', 'value' => $totals['came'] === null ? '-' : number_format($totals['came']), 'tone' => 'success'],
                ['label' => 'Money in', 'value' => self::money($money['in']), 'tone' => 'success'],
            ],
            meta: [
                'Organised by' => $activity->territory?->name,
                'Kind' => $activity->typeLabel(),
                'Where' => $activity->venue ?: '-',
                'Status' => ucfirst($activity->status),
                'Prepared by' => $context->preparedBy(),
                'Prepared' => now()->format('j M Y, H:i'),
            ],
            sections: $sections,
            insights: [],
        );
    }
}
