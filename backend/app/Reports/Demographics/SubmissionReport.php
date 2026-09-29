<?php

namespace App\Reports\Demographics;

use App\Models\ChurchDemographic;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\ReportFacts;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\SundaySchoolTeachersRule;
use App\Support\Reports\Insights\Rules\YouthShareRule;
use Illuminate\Validation\ValidationException;

/** One submission, compared with the approved submission before it. No totals - it's a comparison. */
final class SubmissionReport extends Report
{
    private const GROUPS = [
        'Membership' => ['total_members', 'male_count', 'female_count', 'youth_count', 'seniors_count'],
        'Groups' => ['womens_fellowship_count', 'mens_fellowship_count', 'sunday_school_male_count', 'sunday_school_female_count', 'sunday_school_teachers_count'],
        'Changes & sacraments' => DemographicsData::FLOWS,
    ];

    public function __construct(private DemographicsData $data) {}

    public function key(): string
    {
        return 'demographics.submission';
    }

    public function title(): string
    {
        return 'Submission report';
    }

    public function description(): string
    {
        return 'One submission, figure by figure, compared with the approved submission before it.';
    }

    public function icon(): string
    {
        return 'ri-file-list-3-line';
    }

    public function inputs(): array
    {
        return ['submission'];
    }

    public function build(ReportContext $context): ReportData
    {
        $record = ChurchDemographic::with(['fiscalYear', 'fiscalMonth', 'fiscalSemiAnnual', 'reviewer'])
            ->where('territory_id', $context->territory->id)
            ->find($context->param('demographic_id'));
        if (! $record) {
            throw ValidationException::withMessages(['demographic_id' => 'That submission does not belong to this church.']);
        }

        $key = fn (ChurchDemographic $r) => ((int) $r->fiscalYear?->year) * 100 + DemographicsData::endMonth($r);
        $previous = $this->data->approvedRows($context)
            ->filter(fn ($r) => $r->id !== $record->id && $key($r) < $key($record))
            ->last();
        $label = DemographicsData::label($record);
        $prevLabel = $previous ? DemographicsData::label($previous) : null;

        $sections = [];
        foreach (self::GROUPS as $heading => $fields) {
            $sections[] = new ReportSection(
                $heading,
                [ReportColumn::text('Figure', true), ReportColumn::number($label, null, true), ReportColumn::number($prevLabel ?? 'Previous'), ReportColumn::number('Change')],
                array_map(function ($f) use ($record, $previous) {
                    $now = DemographicsData::int($record->{$f});
                    $before = $previous ? DemographicsData::int($previous->{$f}) : null;
                    $change = $now !== null && $before !== null ? ($now - $before > 0 ? '+' : '').number_format($now - $before) : '-';

                    return [DemographicsData::LABELS[$f], $now, $before, $change];
                }, $fields),
                $heading === 'Membership' && ! $previous ? 'No earlier approved submission to compare with.' : null,
            );
        }

        $figures = DemographicsData::toFigures($record);
        $status = ucwords(str_replace('_', ' ', (string) $record->status));

        return new ReportData(
            kicker: $context->kicker('demographics'),
            title: $this->title(),
            periodLabel: $label,
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Total members', 'value' => number_format((int) $record->total_members), 'tone' => 'primary'],
                ['label' => 'New members', 'value' => number_format((int) $record->new_members_count), 'tone' => 'success'],
                ['label' => 'Baptisms', 'value' => number_format((int) $record->baptisms_count), 'tone' => 'primary'],
                ['label' => 'Status', 'value' => $status, 'tone' => $record->status === 'approved' ? 'success' : 'warning'],
            ],
            meta: array_filter([
                'Church' => $context->territory->name,
                'Period' => $label,
                'Status' => $status,
                'Submitted' => $record->submitted_at?->format('j M Y'),
                'Compared with' => $prevLabel ?? 'Nothing earlier',
                'Prepared by' => $context->preparedBy(),
            ]),
            sections: $sections,
            insights: InsightEngine::run([
                new YouthShareRule,
                GenderBalanceRule::members(),
                GenderBalanceRule::sundaySchool(),
                new SundaySchoolTeachersRule,
                new HolyCommunionRule,
            ], new ReportFacts(['rows' => [$figures], 'latest' => $figures])),
        );
    }
}
