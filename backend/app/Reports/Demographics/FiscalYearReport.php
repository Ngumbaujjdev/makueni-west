<?php

namespace App\Reports\Demographics;

use App\Models\FiscalYear;
use App\Reports\Report;
use App\Reports\ReportContext;

/** Shared plumbing for the Demographics reports that cover one fiscal year. */
abstract class FiscalYearReport extends Report
{
    public function __construct(protected DemographicsData $data) {}

    protected function year(ReportContext $context): FiscalYear
    {
        $id = $context->param('fiscal_year_id');

        return ($id ? FiscalYear::find($id) : null)
            ?? FiscalYear::where('year', now()->year)->first()
            ?? FiscalYear::orderByDesc('year')->firstOrFail();
    }

    /** The standard details panel for a fiscal-year report. */
    protected function meta(ReportContext $context, FiscalYear $year, string $mode, array $periods): array
    {
        $reported = count(array_filter($periods, fn ($p) => $p['status'] === 'approved'));

        return [
            'Church' => $context->territory->name,
            'Fiscal year' => (string) $year->year,
            'Reporting' => DemographicsData::cadenceLabel($mode),
            'Periods reported' => "{$reported} of ".count($periods).' due',
            'Prepared by' => $context->preparedBy(),
            'Prepared' => now()->format('j M Y, H:i'),
        ];
    }

    protected function missingLabels(array $periods): array
    {
        return array_values(array_map(fn ($p) => $p['label'], array_filter($periods, fn ($p) => $p['status'] !== 'approved')));
    }

    /** Sum of a field across periods, null when nothing was reported. */
    protected function sum(array $periods, string $field): ?int
    {
        $values = array_filter(array_column($periods, $field), fn ($v) => $v !== null);

        return $values === [] ? null : (int) array_sum($values);
    }

    /** The last reported value of a field. */
    protected function latest(array $periods, string $field): ?int
    {
        $values = array_values(array_filter(array_column($periods, $field), fn ($v) => $v !== null));

        return $values === [] ? null : (int) end($values);
    }
}
