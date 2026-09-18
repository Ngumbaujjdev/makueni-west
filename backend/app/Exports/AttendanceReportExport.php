<?php

namespace App\Exports;

use App\Models\GatheringCategory;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel counterpart to Pdf\AttendanceSummaryPdfReport - same
 * AttendanceReportWidgetService::widgetsFor() output, same two-shape
 * branching on $category->is_weekly, just tabular instead of drawn.
 *
 * Implements FromArray (not FromCollection+WithMapping, the way
 * BudgetsExport does) because the source here is already the widget
 * service's pre-shaped plain-array output, not a query of Eloquent
 * models to map row by row.
 */
class AttendanceReportExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    private array $headers;

    private array $rows;

    public function __construct(GatheringCategory $category, array $widgets)
    {
        [$this->headers, $this->rows] = $category->is_weekly
            ? $this->weeklyRows($widgets)
            : $this->breakdownRows($widgets);
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2CA4BF'],
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Attendance Report';
    }

    /** Sunday Service: coverage ratio + the "Best Month/Weekly Average/..." strip, one row. */
    private function weeklyRows(array $widgets): array
    {
        $coverage = $widgets['coverage'] ?? ['recorded' => 0, 'elapsed' => 0, 'percentage' => 0];
        $columns = $widgets['stat_columns'] ?? [];

        $headers = ['Recorded', 'Elapsed', 'Coverage %'];
        $row = [$coverage['recorded'], $coverage['elapsed'], $coverage['percentage'].'%'];

        foreach ($columns as $column) {
            $headers[] = $column['label'];
            $row[] = $column['value'];
        }

        return [$headers, [$row]];
    }

    /** Ministry Gatherings/Special Events: the ranked gathering-type breakdown table. */
    private function breakdownRows(array $widgets): array
    {
        $headers = ['Type', 'Times Held', 'Total Attendance', 'Average', 'Last Held', 'Status'];

        $rows = array_map(fn (array $row) => [
            $row['name'],
            $row['times_held'],
            $row['total_attendance'],
            $row['average_attendance'],
            $row['last_held'] ?? '-',
            $this->statusLabel($row['status']),
        ], $widgets['breakdown'] ?? []);

        return [$headers, $rows];
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
