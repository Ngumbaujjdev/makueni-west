<?php

namespace App\Reports\Monthly;

use App\Enums\TerritoryType;
use App\Models\MonthlyReport;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Reports\MonthlyFigures;

/** One monthly report, laid out as the page: the figures, what happened, and the pastor's words. */
final class MonthlyReportExport extends MonthlyReportBase
{
    private const WORD_LABELS = [
        'achievements' => 'What went well', 'challenges' => 'Challenges', 'prayer_requests' => 'Prayer requests',
        'support_needed' => 'Support we need', 'testimonies' => 'Testimonies', 'next_month' => 'Plans for next month',
    ];

    public function key(): string
    {
        return 'monthly.report';
    }

    public function title(): string
    {
        return 'Monthly report';
    }

    public function description(): string
    {
        return 'One month\'s report: people, attendance, income and expenses, events and initiatives, and the pastor\'s words.';
    }

    public function subject(): string
    {
        return 'monthly report';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH, TerritoryType::REGION];
    }

    public function inputs(): array
    {
        return ['monthly_report'];
    }

    public function build(ReportContext $context): ReportData
    {
        $report = MonthlyReport::with('territory')->findOrFail((int) $context->param('report_id'));
        $f = $report->figures ?? app(MonthlyFigures::class)->for($report->territory, $report->year, $report->month);
        $people = $f['people'] ?? null;
        $att = $f['attendance'] ?? null;
        $money = $f['money'] ?? [];
        $sections = [];

        $figureRows = array_values(array_filter([
            $people && $people['recorded'] ? ['Members', number_format($people['members']).($people['members_change'] !== null ? sprintf(' (%+d)', $people['members_change']) : ''), 'Demographics, '.$people['from']] : null,
            $people && $people['this_month'] ? ['Baptisms · conversions · communion', "{$people['baptisms']} · {$people['conversions']} · {$people['communion']}", 'Demographics'] : null,
            $att ? ['Average Sunday', $att['average_sunday'] === null ? 'Not recorded' : number_format($att['average_sunday'])." over {$att['sundays']} Sundays", 'Attendance'] : null,
            $att ? ['Other gatherings', "{$att['gatherings']} held, ".number_format($att['gathering_attendance']).' attended', 'Attendance'] : null,
            ['Income', self::money($money['income'] ?? null), 'Budgets'],
            ['Expenses', self::money($money['expenses'] ?? null), 'Budgets'],
            ['Left', self::money($money['left'] ?? null), 'Budgets'],
            ! empty($money['share']) ? [$money['share']['name'], self::money($money['share']['sent']).' sent of '.self::money($money['share']['due']), 'Contributions'] : null,
            ! empty($f['churches']) ? ['Churches\' reports', "{$f['churches']['sent']} of {$f['churches']['churches']} sent", 'Monthly reports'] : null,
        ]));
        $sections[] = new ReportSection('Figures', [ReportColumn::text('What', true), ReportColumn::text('This month'), ReportColumn::text('From')], $figureRows);

        $happened = [
            ...array_map(fn ($e) => [$e['date'], $e['title'], 'Our event', $e['came'] ?? $e['expected']], $f['events']['ours'] ?? []),
            ...array_map(fn ($e) => [$e['date'], $e['title'], "With {$e['organiser']}", $e['came'] ?? $e['expected']], $f['events']['took_part'] ?? []),
            ...array_map(fn ($i) => ['-', $i['title'], "{$i['sessions']} session(s)", $i['attendance']], $f['initiatives'] ?? []),
        ];
        if ($report->pastoral_visits !== null) {
            $happened[] = ['-', 'Pastoral visits', '', $report->pastoral_visits];
        }
        $sections[] = new ReportSection('What happened', [ReportColumn::text('Date'), ReportColumn::text('What', true), ReportColumn::text(''), ReportColumn::number('People')], $happened, $happened ? null : 'No events, sessions or visits recorded.');

        $words = array_values(array_filter(array_map(fn ($k) => $report->{$k} ? [self::WORD_LABELS[$k], $report->{$k}] : null, array_keys(self::WORD_LABELS))));
        if ($report->outreach) {
            $words[] = ['Outreach', $report->outreach];
        }
        $sections[] = new ReportSection('In the pastor\'s words', [ReportColumn::text('', true), ReportColumn::text('')], $words, $words ? null : 'Nothing written.');

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: "{$report->territory->name} - {$report->label()}",
            periodLabel: $report->label(),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Average Sunday', 'value' => $att && $att['average_sunday'] !== null ? number_format($att['average_sunday']) : '-', 'tone' => 'primary'],
                ['label' => 'Members', 'value' => $people && $people['recorded'] ? number_format($people['members']) : '-', 'tone' => 'purple'],
                ['label' => 'Income', 'value' => self::money($money['income'] ?? null), 'tone' => 'success'],
                ['label' => 'Expenses', 'value' => self::money($money['expenses'] ?? null), 'tone' => 'danger'],
            ],
            meta: [
                'Status' => ucfirst($report->status),
                'Sent' => $report->sent_at?->format('j M Y') ?? 'Not yet',
                'Prepared by' => $context->preparedBy(),
                'Prepared' => now()->format('j M Y, H:i'),
            ],
            sections: $sections,
            insights: [],
        );
    }
}
