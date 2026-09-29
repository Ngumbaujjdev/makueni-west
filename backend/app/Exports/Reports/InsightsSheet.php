<?php

namespace App\Exports\Reports;

use App\Reports\ReportData;
use App\Support\Reports\Insights\Insight;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The last sheet - only added when the report has insights. */
class InsightsSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    private const TONES = [Insight::GOOD => 'Going well', Insight::WATCH => 'Keep an eye on', Insight::CONCERN => 'Needs attention'];

    public function __construct(private ReportData $data) {}

    public function title(): string
    {
        return 'Insights & recommendations';
    }

    public function headings(): array
    {
        return ['', 'What we noticed', 'Detail', 'Recommendation'];
    }

    public function array(): array
    {
        return array_map(fn (Insight $i) => [self::TONES[$i->tone] ?? $i->tone, $i->title, $i->detail, $i->recommendation ?? ''], $this->data->insights);
    }

    public function columnWidths(): array
    {
        return ['A' => 18, 'B' => 38, 'C' => 50, 'D' => 60];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A:D')->getAlignment()->setWrapText(true)->setVertical('top');

        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2CA4BF']]]];
    }
}
