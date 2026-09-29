<?php

namespace App\Exports\Reports;

use App\Reports\ReportData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Title, scope, verification code, key figures and the details panel. */
class SummarySheet implements FromArray, WithColumnWidths, WithStyles, WithTitle
{
    private int $figuresHeaderRow = 6;

    private int $detailsHeaderRow = 0;

    public function __construct(private ReportData $data, private string $verificationCode) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function array(): array
    {
        $rows = [
            [$this->data->title],
            [$this->data->kicker.' · '.$this->data->periodLabel.' · '.$this->data->scopeLabel],
            [$this->verificationCode !== '' ? 'Verification code: '.$this->verificationCode : ''],
            [''],
            ['Key figures'],
        ];
        $this->figuresHeaderRow = count($rows);
        foreach ($this->data->tiles as $tile) {
            $rows[] = [$tile['label'], $tile['value']];
        }
        $rows[] = [''];
        $rows[] = ['Details'];
        $this->detailsHeaderRow = count($rows);
        foreach ($this->data->meta as $key => $value) {
            $rows[] = [$key, $value];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 28, 'B' => 44];
    }

    public function styles(Worksheet $sheet): array
    {
        $teal = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2CA4BF']]];

        return [
            1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '212B36']]],
            2 => ['font' => ['color' => ['rgb' => '626E7E']]],
            3 => ['font' => ['bold' => true, 'color' => ['rgb' => '2CA4BF']]],
            $this->figuresHeaderRow => $teal,
            $this->detailsHeaderRow => $teal,
        ];
    }
}
