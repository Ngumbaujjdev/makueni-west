<?php

namespace App\Reports\Monthly;

use App\Enums\TerritoryType;
use App\Models\MonthlyReport;
use App\Reports\Budget\BudgetRollup;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Reports\MonthlyReports;
use Carbon\CarbonImmutable;

/** Who sent their monthly report and who hasn't yet, for a month, grouped by subregion or region. */
final class MonthlyStatusReport extends MonthlyReportBase
{
    public function key(): string
    {
        return 'monthly.status';
    }

    public function title(): string
    {
        return 'Monthly reports sent';
    }

    public function description(): string
    {
        return 'For one month: which places sent their report, which were read, and which are late.';
    }

    public function subject(): string
    {
        return 'monthly reports status';
    }

    public function scopes(): array
    {
        return [TerritoryType::REGION, TerritoryType::DIOCESE];
    }

    public function inputs(): array
    {
        return ['report_month'];
    }

    public function build(ReportContext $context): ReportData
    {
        $last = CarbonImmutable::now(MonthlyReports::TZ)->subMonthNoOverflow();
        $year = (int) ($context->param('year') ?: $last->year);
        $month = (int) ($context->param('month') ?: $last->month);
        $place = $context->territory;
        $rollup = app(BudgetRollup::class);
        $service = app(MonthlyReports::class);
        $levels = $place->territory_type === TerritoryType::DIOCESE ? ['region', 'church'] : ['church'];
        $places = collect($levels)->flatMap(fn ($l) => $rollup->placesBelow($place, $l));
        $reports = MonthlyReport::whereIn('territory_id', $places->pluck('id')->all() ?: [0])->where('year', $year)->where('month', $month)->get()->keyBy('territory_id');
        $words = ['sent' => 'Sent', 'seen' => 'Seen', 'draft' => 'Started', 'not_started' => 'Not started'];

        $rows = $places->map(function ($p) use ($reports, $service, $year, $month, $words) {
            $r = $reports->get($p['id']);
            $state = $service->state(\App\Models\Territory::find($p['id']), $year, $month, $r);

            return [$p['group'] ?? '-', $p['name'], ucfirst($p['type']), $words[$state['status']].($state['late'] ? ' (late)' : ''), $r?->sent_at?->format('j M Y') ?? '-'];
        })->values()->all();
        $sent = $reports->whereIn('status', ['sent', 'seen'])->count();
        $late = collect($rows)->filter(fn ($r) => str_contains($r[3], 'late'))->count();
        $label = CarbonImmutable::create($year, $month, 1)->format('F Y');

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: "Monthly reports - {$label}",
            periodLabel: $label,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Places', 'value' => (string) count($rows), 'tone' => 'primary'],
                ['label' => 'Sent', 'value' => (string) $sent, 'tone' => 'success'],
                ['label' => 'Seen', 'value' => (string) $reports->where('status', 'seen')->count(), 'tone' => 'purple'],
                ['label' => 'Late', 'value' => (string) $late, 'tone' => 'danger'],
            ],
            meta: ['Month' => $label, 'Prepared by' => $context->preparedBy(), 'Prepared' => now()->format('j M Y, H:i')],
            sections: [new ReportSection('Reports', [
                ReportColumn::text($place->territory_type === TerritoryType::DIOCESE ? 'Region' : 'Subregion'), ReportColumn::text('Place', true), ReportColumn::text('Level'), ReportColumn::text('Status'), ReportColumn::text('Sent on'),
            ], $rows, $rows ? null : 'No places below.')],
            insights: [],
        );
    }
}
