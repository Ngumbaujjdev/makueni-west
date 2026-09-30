<?php

namespace App\Reports\Attendance;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use Carbon\CarbonImmutable;

/** Boys and girls at each Sunday service - the Analytics page's Children tab as a report. */
final class ChildrenReport extends AttendanceReport
{
    public function key(): string
    {
        return 'attendance.children';
    }

    public function title(): string
    {
        return "Children's attendance";
    }

    public function subject(): string
    {
        return "Children's attendance report";
    }

    public function description(): string
    {
        return 'Boys and girls at every Sunday service, their share of the congregation, and the balance between them.';
    }

    public function icon(): string
    {
        return 'ri-parent-line';
    }

    public function build(ReportContext $context): ReportData
    {
        $data = $this->data($context);
        $a = $data->analytics();
        $ch = $a['children'];
        $share = fn ($boys, $girls, $total) => $total ? round(($boys + $girls) / $total * 100).'%' : null;

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: "Children's attendance",
            periodLabel: $this->periodLabel($data),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Children a Sunday', 'value' => $this->int($ch['boys'] + $ch['girls']), 'tone' => 'primary'],
                ['label' => 'Boys', 'value' => $this->int($ch['boys']), 'tone' => 'purple'],
                ['label' => 'Girls', 'value' => $this->int($ch['girls']), 'tone' => 'success'],
                ['label' => 'Share of a Sunday', 'value' => $ch['children_share'] === null ? '-' : "{$ch['children_share']}%", 'tone' => 'warning'],
            ],
            meta: $this->meta($context, $data, ['Girls among children' => $ch['girls_share'] === null ? '-' : "{$ch['girls_share']}%"]),
            sections: [
                new ReportSection(
                    'Every Sunday',
                    [
                        ReportColumn::text('Sunday', true),
                        ReportColumn::number('Boys', 'avg'),
                        ReportColumn::number('Girls', 'avg'),
                        ReportColumn::number('Children', 'avg', true),
                        ReportColumn::number('Everyone', 'avg'),
                        ReportColumn::text('Children share'),
                    ],
                    array_map(fn ($w) => [
                        CarbonImmutable::parse($w['date'])->format('D j M Y'),
                        $w['children_male_count'], $w['children_female_count'],
                        $w['children_male_count'] + $w['children_female_count'], $w['total'],
                        $share($w['children_male_count'], $w['children_female_count'], $w['total']),
                    ], $a['sunday']['weekly']),
                    null,
                    'Average Sunday',
                ),
                new ReportSection(
                    'Month by month',
                    [
                        ReportColumn::text('Month', true),
                        ReportColumn::number('Sundays', 'sum'),
                        ReportColumn::number('Boys'),
                        ReportColumn::number('Girls'),
                        ReportColumn::text('Children share'),
                    ],
                    array_map(fn ($m) => [$m['label'], $m['sundays'], $m['boys'], $m['girls'], "{$m['children_share']}%"], $ch['months']),
                    'Boys and girls are averages per Sunday in that month.',
                    'Total',
                ),
            ],
            insights: InsightEngine::run([GenderBalanceRule::sundayChildren()], $this->facts($data)),
        );
    }
}
