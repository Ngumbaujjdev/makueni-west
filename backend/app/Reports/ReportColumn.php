<?php

namespace App\Reports;

/**
 * One column of a report table. `total` is opt-in: a section only gets a
 * totals row when at least one of its columns asks for one.
 *   sum    - add the period values (baptisms, new members...)
 *   latest - the last reported value (headcounts: adding months is wrong)
 *   avg    - the average of the reported values
 *   null   - no total for this column
 */
final class ReportColumn
{
    public function __construct(
        public string $header,
        public string $align = 'L',
        public ?string $total = null,
        public bool $strong = false,
        public bool $numeric = false,
        public ?string $format = null,
    ) {}

    public static function text(string $header, bool $strong = false): self
    {
        return new self($header, 'L', null, $strong);
    }

    public static function number(string $header, ?string $total = null, bool $strong = false): self
    {
        return new self($header, 'R', $total, $strong, true);
    }

    /** Money: always 2 decimals ("12,345.50") in the PDF and preview, #,##0.00 in Excel. */
    public static function money(string $header, ?string $total = 'sum', bool $strong = false): self
    {
        return new self($header, 'R', $total, $strong, true, 'money');
    }

    public function toArray(): array
    {
        return ['header' => $this->header, 'align' => $this->align, 'total' => $this->total, 'strong' => $this->strong, 'numeric' => $this->numeric, 'format' => $this->format];
    }
}
