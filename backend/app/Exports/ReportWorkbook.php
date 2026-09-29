<?php

namespace App\Exports;

use App\Exports\Reports\InsightsSheet;
use App\Exports\Reports\SectionSheet;
use App\Exports\Reports\SummarySheet;
use App\Reports\ReportData;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel version of a report (docs/specs/reports-spec.md): a Summary sheet,
 * one sheet per section, and - only when the report has any - a final
 * Insights & recommendations sheet.
 */
class ReportWorkbook implements WithMultipleSheets
{
    public function __construct(private ReportData $data, private string $verificationCode = '') {}

    public function sheets(): array
    {
        $sheets = [new SummarySheet($this->data, $this->verificationCode)];
        $used = ['Summary'];
        foreach ($this->data->sections as $section) {
            $name = self::sheetName($section->heading, $used);
            $used[] = $name;
            $sheets[] = new SectionSheet($section, $name);
        }
        if ($this->data->hasInsights()) {
            $sheets[] = new InsightsSheet($this->data);
        }

        return $sheets;
    }

    /** Excel sheet names: 31 characters, no []:*?/\, unique in the workbook. */
    private static function sheetName(string $heading, array $used): string
    {
        $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', '', $heading)) ?: 'Sheet', 0, 28);
        $name = $base;
        for ($n = 2; in_array($name, $used, true); $n++) {
            $name = "{$base} {$n}";
        }

        return $name;
    }
}
