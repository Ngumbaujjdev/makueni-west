<?php

namespace App\Reports;

/**
 * A chart in a report, drawn into the PDF (DioceseReportPdf::charts()) and
 * listed in the preview. Excel keeps the tables the charts are drawn from.
 *
 *   bars  - grouped columns per category (money in and out by month)
 *   hbars - one row per category, the first series soft behind the second
 *           (planned against actual per line); a second-series value above
 *           the first is drawn red when `overIsBad`
 *
 * Each series is ['name' => 'Spent', 'tone' => 'danger', 'values' => [...],
 * 'soft' => false]; tones are the PDF's (primary, success, warning, danger,
 * purple, pink, muted).
 */
final class ReportChart
{
    public function __construct(
        public string $title,
        public string $kind,
        public array $categories,
        public array $series,
        public ?string $note = null,
        public bool $overIsBad = false,
    ) {}

    public static function bars(string $title, array $categories, array $series, ?string $note = null): self
    {
        return new self($title, 'bars', $categories, $series, $note);
    }

    public static function hbars(string $title, array $categories, array $series, ?string $note = null, bool $overIsBad = false): self
    {
        return new self($title, 'hbars', $categories, $series, $note, $overIsBad);
    }

    /** Whether there is anything to draw. */
    public function hasValues(): bool
    {
        foreach ($this->series as $s) {
            foreach ($s['values'] as $v) {
                if ((float) $v != 0.0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return ['title' => $this->title, 'kind' => $this->kind, 'categories' => $this->categories, 'series' => $this->series, 'note' => $this->note];
    }
}
