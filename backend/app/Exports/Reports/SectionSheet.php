<?php

namespace App\Exports\Reports;

use App\Reports\ReportColumn;
use App\Reports\ReportSection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One report section as a sheet. Numbers stay numbers. The totals row is
 * only written when the section declares totals: `sum` columns get a real
 * SUM() formula, `latest`/`avg` their computed value.
 */
class SectionSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private ReportSection $section, private string $name) {}

    public function title(): string
    {
        return $this->name;
    }

    public function headings(): array
    {
        return array_map(fn (ReportColumn $c) => $c->header, $this->section->columns);
    }

    public function array(): array
    {
        return array_map(fn (array $row) => array_map(fn ($v) => $v ?? '', $row), $this->section->rows);
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2CA4BF']]]];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $rowCount = count($this->section->rows);
                $lastCol = Coordinate::stringFromColumnIndex(max(1, count($this->section->columns)));

                foreach ($this->section->columns as $i => $col) {
                    if ($col->align === 'R') {
                        $letter = Coordinate::stringFromColumnIndex($i + 1);
                        $sheet->getStyle("{$letter}1:{$letter}".($rowCount + 2))->getAlignment()->setHorizontal('right');
                    }
                }

                $totals = $this->section->totals();
                if ($totals === null) {
                    return;
                }
                $r = $rowCount + 2;
                foreach ($this->section->columns as $i => $col) {
                    $letter = Coordinate::stringFromColumnIndex($i + 1);
                    $cell = "{$letter}{$r}";
                    if ($col->total === 'sum' && $rowCount > 0) {
                        $sheet->setCellValue($cell, "=SUM({$letter}2:{$letter}".($rowCount + 1).')');
                    } elseif ($totals[$i] !== null) {
                        $sheet->setCellValue($cell, $totals[$i]);
                    }
                }
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '1F7F95']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F0F8FB']],
                    'borders' => ['top' => ['borderStyle' => 'thin', 'color' => ['rgb' => '2CA4BF']]],
                ]);
            },
        ];
    }
}
