<?php

namespace App\Reports\Activities;

use App\Models\Activity;
use App\Models\FiscalYear;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Activities\Activities;
use App\Services\Activities\Sessions;

/** A place's initiatives running in a year: how often they meet, sessions held, attendance and who takes part. */
final class InitiativeYearReport extends ActivityReport
{
    protected function kind(): string
    {
        return 'initiative';
    }

    public function module(): string
    {
        return 'initiatives';
    }

    public function key(): string
    {
        return 'initiative.year';
    }

    public function title(): string
    {
        return 'Initiatives this year';
    }

    public function description(): string
    {
        return 'Every initiative running in the year: how often it meets, sessions held, average attendance, places taking part and income.';
    }

    public function subject(): string
    {
        return 'initiatives report';
    }

    public function icon(): string
    {
        return 'ri-seedling-line';
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
        $items = Activity::with(['registrations', 'sessions'])->where('kind', 'initiative')->where('territory_id', $context->territory->id)
            ->inYear($year, 'initiative')->orderBy('starts_at')->get();

        $rows = $items->map(function (Activity $a) use ($service) {
            $s = Sessions::summary($a);

            return [
                $a->title, $a->typeLabel(), Activity::FREQUENCIES[$a->frequency] ?? '-', $a->starts_at->format('j M Y').' - '.$a->ends_at->format('j M Y'),
                ucfirst($a->status), "{$s['held']} of {$s['total']}", $s['average'] ?? '-', $service->totals($a)['places'], $service->money($a)['in'],
            ];
        })->values()->all();
        $held = $items->sum(fn (Activity $a) => $a->sessions->where('status', 'held')->count());

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Initiatives this year',
            periodLabel: (string) $year,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Initiatives', 'value' => (string) $items->count(), 'tone' => 'primary'],
                ['label' => 'Running', 'value' => (string) $items->where('status', 'published')->count(), 'tone' => 'purple'],
                ['label' => 'Sessions held', 'value' => (string) $held, 'tone' => 'success'],
                ['label' => 'Done', 'value' => (string) $items->where('status', 'completed')->count(), 'tone' => 'secondary'],
            ],
            meta: ['Year' => (string) $year, 'Prepared by' => $context->preparedBy(), 'Prepared' => now()->format('j M Y, H:i')],
            sections: [new ReportSection('Initiatives', [
                ReportColumn::text('Initiative', true), ReportColumn::text('Kind'), ReportColumn::text('Meets'), ReportColumn::text('Runs'),
                ReportColumn::text('Status'), ReportColumn::text('Sessions held'), ReportColumn::number('Average attendance'),
                ReportColumn::number('Places', 'sum'), ReportColumn::money('Income'),
            ], $rows, $rows ? null : "No initiatives in {$year}.")],
            insights: [],
        );
    }
}
