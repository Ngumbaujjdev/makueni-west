<?php

namespace App\Reports\Facilities;

use App\Models\MaintenanceJob;
use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/**
 * Repairs and their cost (P5 round 3): what was reported and fixed in the
 * year, what it cost - each month and by item - and what is still open.
 */
final class RepairsCostReport extends FacilitiesReport
{
    public function key(): string
    {
        return 'facilities.repairs';
    }

    public function title(): string
    {
        return 'Repairs and their cost';
    }

    public function description(): string
    {
        return 'Repairs reported and fixed in the year, what they cost each month and by item, and those still open - the urgent ones first.';
    }

    public function icon(): string
    {
        return 'ri-tools-line';
    }

    public function subject(): string
    {
        return 'repairs';
    }

    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    public function build(ReportContext $context): ReportData
    {
        $church = $context->territory;
        [$from, $to, $label] = $this->year($context);
        $jobs = MaintenanceJob::with(['equipment', 'room', 'assignee'])->where('territory_id', $church->id)
            ->where(fn ($q) => $q->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->orWhereBetween('done_on', [$from->toDateString(), $to->toDateString()]))
            ->orderBy('created_at')->get();
        $done = $jobs->where('status', 'done');
        $open = MaintenanceJob::with(['equipment', 'room'])->where('territory_id', $church->id)->where('status', '!=', 'done')->orderByRaw("priority = 'urgent' desc")->orderBy('created_at')->get();
        $cost = (float) $done->sum('cost');
        $all = $label === 'all time';
        $months = $all
            ? $done->filter(fn ($j) => $j->done_on)->map(fn ($j) => $j->done_on->startOfYear())->unique()->sort()->values()
            : collect(range(0, 11))->map(fn ($i) => $from->addMonths($i)->startOfMonth())->filter(fn ($m) => $m->lte($to));
        $fmt = $all ? 'Y' : 'Y-m';
        $perMonth = $months->map(fn ($m) => round((float) $done->filter(fn ($j) => $j->done_on?->format($fmt) === $m->format($fmt))->sum('cost'), 2))->values()->all();
        $what = fn (MaintenanceJob $j) => $j->equipment?->name ?? ($j->room ? "Room: {$j->room->name}" : '-');
        $byItem = $done->groupBy($what)->map(fn ($list, $name) => [$name, $list->count(), round((float) $list->sum('cost'), 2)])->sortByDesc(2)->values();
        $statuses = MaintenanceJob::STATUSES;

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $label === 'all time' ? 'All repairs' : "Repairs in {$label}",
            periodLabel: $label === 'all time' ? 'All time' : $from->format('j M Y').' - '.$to->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Fixed', 'value' => (string) $done->count(), 'tone' => 'success'],
                ['label' => 'They cost', 'value' => self::money($cost), 'tone' => 'primary'],
                ['label' => 'Still open', 'value' => (string) $open->count(), 'tone' => 'warning'],
                ['label' => 'Urgent and open', 'value' => (string) $open->where('priority', 'urgent')->count(), 'tone' => 'danger'],
            ],
            meta: ['Year' => $label, 'Prepared by' => $context->preparedBy(), 'Recorded in Budgets' => $done->whereNotNull('budget_entry_id')->count().' of '.$done->count().' fixed'],
            sections: [
                new ReportSection('Fixed', [
                    ReportColumn::text('Done'), ReportColumn::text('Repair', true), ReportColumn::text('What'), ReportColumn::text('Who fixed it'), ReportColumn::money('Cost'), ReportColumn::text('In Budgets'),
                ], $done->sortBy('done_on')->map(fn ($j) => [$j->done_on?->format('j M') ?? '-', $j->title, $what($j), $j->assignee ? trim("{$j->assignee->firstname} {$j->assignee->lastname}") : '-', $j->cost !== null ? (float) $j->cost : null, $j->budget_entry_id ? 'Yes' : 'No'])->values()->all(), $done->isEmpty() ? "Nothing fixed in {$label}." : null),
                new ReportSection('Cost by item', [ReportColumn::text('Item or room', true), ReportColumn::number('Repairs', 'sum'), ReportColumn::money('Cost')], $byItem->all(), $byItem->isEmpty() ? 'Nothing yet.' : null),
                new ReportSection('Still open', [
                    ReportColumn::text('Reported'), ReportColumn::text('Repair', true), ReportColumn::text('What'), ReportColumn::text('Where it is'), ReportColumn::text('Urgent'),
                ], $open->map(fn ($j) => [$j->created_at?->format('j M Y') ?? '-', $j->title, $what($j), $statuses[$j->status][0] ?? $j->status, $j->priority === 'urgent' ? 'Urgent' : '-'])->values()->all(), $open->isEmpty() ? 'Nothing waiting to be fixed.' : null),
            ],
            insights: [],
            charts: $cost > 0 ? [ReportChart::bars($all ? 'Repairs cost each year' : 'Repairs cost each month', $months->map(fn ($m) => $m->format($all ? 'Y' : 'M'))->values()->all(), [
                ['name' => 'Cost (KES)', 'tone' => 'warning', 'values' => $perMonth],
            ], 'By the month each repair was done.')] : [],
        );
    }
}
