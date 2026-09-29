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

    protected function isAllTime(ReportContext $context): bool
    {
        return $context->param('fiscal_year_id') === 'all';
    }

    /**
     * The periods a report covers: one fiscal year's due periods, or - for
     * "All time" - every approved submission.
     *
     * @return array{0: array, 1: string, 2: ?FiscalYear} periods, period label, the year (null for all time)
     */
    protected function scope(ReportContext $context): array
    {
        if ($this->isAllTime($context)) {
            $periods = $this->data->allTimePeriods($context);
            $years = array_values(array_unique(array_column($periods, 'year')));
            $label = 'All time'.($years ? ' ('.min($years).(min($years) !== max($years) ? '-'.max($years) : '').')' : '');

            return [$periods, $label, null];
        }
        $year = $this->year($context);

        return [$this->data->yearPeriods($context, $year), 'Fiscal year '.$year->year, $year];
    }

    /** The standard details panel for a fiscal-year (or all-time) report. */
    protected function meta(ReportContext $context, string $periodLabel, string $mode, array $periods): array
    {
        $reported = count(array_filter($periods, fn ($p) => $p['status'] === 'approved'));

        return [
            'Church' => $context->territory->name,
            'Period' => $periodLabel,
            'Reporting' => DemographicsData::cadenceLabel($mode),
            'Periods reported' => $this->isAllTime($context) ? "{$reported} approved" : "{$reported} of ".count($periods).' due',
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
