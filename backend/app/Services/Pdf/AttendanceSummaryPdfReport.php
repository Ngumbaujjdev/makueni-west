<?php

namespace App\Services\Pdf;

use App\Enums\DioceseBranding;
use App\Models\FiscalMonth;
use App\Models\FiscalYear;
use App\Models\GatheringCategory;
use App\Models\GatheringType;

/**
 * Formats AttendanceReportWidgetService::widgetsFor()'s output as a
 * branded PDF - deliberately does no aggregation of its own, since that
 * service already computes every number and insight sentence the
 * dashboard tab shows. One class handles all 3 gathering categories
 * (Sunday Service/Ministry Gatherings/Special Events) by branching on
 * $category->is_weekly, the same way the widget service itself branches,
 * rather than three near-identical report classes.
 */
class AttendanceSummaryPdfReport extends DiocesePdfReport
{
    public function __construct(
        private readonly string $churchName,
        private readonly GatheringCategory $category,
        private readonly FiscalYear $year,
        private readonly ?FiscalMonth $month,
        private readonly array $widgets,
        private readonly string $reportId,
        private readonly ?GatheringType $gatheringType = null,
    ) {
        parent::__construct();
    }

    public function build(): static
    {
        $subtitle = "{$this->churchName} \xC2\xB7 {$this->periodLabel()}";
        if ($this->gatheringType) {
            $subtitle .= " \xC2\xB7 {$this->gatheringType->name}";
        }

        $this->initReport($this->category->name.' Attendance Report', $subtitle);

        $this->addKpiBoxes($this->kpiBoxes());

        if ($this->category->is_weekly) {
            $this->addCoverageSummary();
        } else {
            $this->addBreakdownTable();
        }

        $this->addInsightsList('Insights', $this->widgets['insights'] ?? []);
        $this->addReportFooter($this->reportId, $this->qrContent());

        return $this;
    }

    /**
     * Encodes the same authentication metadata ifms-core-server's own
     * report QR codes carry - church, category (+ drilled-down type, if
     * any), period, report ID, and generation timestamp.
     */
    private function qrContent(): string
    {
        $lines = [
            'Makueni West Diocese - '.$this->category->name.' Attendance Report',
            'Church: '.$this->churchName,
            'Period: '.$this->periodLabel(),
        ];

        if ($this->gatheringType) {
            $lines[] = 'Gathering Type: '.$this->gatheringType->name;
        }

        $lines[] = 'Report ID: '.$this->reportId;
        $lines[] = 'Generated: '.now()->toDateTimeString();

        return implode("\n", $lines);
    }

    private function periodLabel(): string
    {
        return $this->month ? "{$this->month->name} {$this->year->year}" : "FY {$this->year->year}";
    }

    /**
     * Reuses the same 4 stat cards the dashboard tab shows - same labels/
     * values, just drawn as PDF KPI boxes instead of Bootstrap cards.
     */
    private function kpiBoxes(): array
    {
        $palette = [
            DioceseBranding::PRIMARY_TEAL,
            DioceseBranding::SUCCESS_GREEN,
            DioceseBranding::SECONDARY_GOLD,
            DioceseBranding::ACCENT_RED,
        ];

        return collect($this->widgets['stats'] ?? [])
            ->values()
            ->map(fn (array $stat, int $i) => [
                'label' => $stat['label'],
                'value' => $stat['value'],
                'color' => $palette[$i % count($palette)],
            ])
            ->all();
    }

    /**
     * Sunday Service tab: coverage ratio + the "Best Month / Weekly
     * Average / This Month vs. Last" strip - the PDF's stand-in for the
     * dashboard's trend chart (TCPDF draws tables, not line charts).
     */
    private function addCoverageSummary(): void
    {
        $coverage = $this->widgets['coverage'] ?? null;

        if ($coverage) {
            $this->addSectionTitle('Coverage');
            $this->addTable(
                ['Recorded', 'Elapsed', 'Coverage %'],
                [[$coverage['recorded'], $coverage['elapsed'], $coverage['percentage'].'%']]
            );
        }

        $columns = $this->widgets['stat_columns'] ?? [];

        if (! empty($columns)) {
            $this->addSectionTitle('Summary');
            $this->addTable(
                array_map(fn ($c) => $c['label'], $columns),
                [array_map(fn ($c) => $c['value'], $columns)]
            );
        }
    }

    /**
     * Ministry Gatherings / Special Events tabs: the ranked
     * gathering-type breakdown table.
     */
    private function addBreakdownTable(): void
    {
        $breakdown = $this->widgets['breakdown'] ?? [];

        if (empty($breakdown)) {
            return;
        }

        $this->addSectionTitle('Gathering Type Breakdown');
        $this->addTable(
            ['Type', 'Times Held', 'Total Attendance', 'Average', 'Last Held', 'Status'],
            array_map(fn (array $row) => [
                $row['name'],
                $row['times_held'],
                $row['total_attendance'],
                $row['average_attendance'],
                $row['last_held'] ?? '-',
                $this->statusLabel($row['status']),
            ], $breakdown),
            [40, 22, 30, 22, 30, 30]
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'on_track' => 'On Track',
            'inactive' => 'Inactive',
            'never_held' => 'Never Held',
            default => ucfirst($status),
        };
    }
}
