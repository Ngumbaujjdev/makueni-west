<?php

namespace App\Reports;

use App\Support\Reports\Insights\Insight;

/**
 * Everything a finished report says, in one shape - the PDF, the Excel
 * workbook and the modal preview all read this and nothing else.
 */
final class ReportData
{
    /**
     * @param  array<int, array{label: string, value: string, tone?: string, hint?: string}>  $tiles
     * @param  array<string, string>  $meta
     * @param  ReportSection[]  $sections
     * @param  Insight[]  $insights
     */
    public function __construct(
        public string $kicker,
        public string $title,
        public string $periodLabel,
        public string $scopeLabel,
        public array $tiles = [],
        public array $meta = [],
        public array $sections = [],
        public array $insights = [],
        /** @var ReportChart[] drawn into the PDF after the details panel */
        public array $charts = [],
        /**
         * A cover page before the report (a book such as the cashbook):
         * {title, subtitle?, lines?: [label => value], logo_path?: absolute PNG/JPEG path}.
         */
        public ?array $cover = null,
        /** Signature boxes after the tables: [{label, name?, date?}] - a voucher, a receipt, a count. */
        public array $signatures = [],
        /** 'P' or 'L' to fix the orientation; null picks it from the tables. */
        public ?string $orientation = null,
    ) {}

    /** @return ReportChart[] the charts with something to draw */
    public function drawableCharts(): array
    {
        return array_values(array_filter($this->charts, fn (ReportChart $c) => $c->hasValues()));
    }

    public function hasInsights(): bool
    {
        return $this->insights !== [];
    }

    /** @return string[] recommendations from the insights, in order */
    public function recommendations(): array
    {
        return array_values(array_filter(array_map(fn (Insight $i) => $i->recommendation, $this->insights)));
    }

    public function toPreview(int $rowLimit = 5): array
    {
        return [
            'kicker' => $this->kicker,
            'title' => $this->title,
            'period_label' => $this->periodLabel,
            'scope_label' => $this->scopeLabel,
            'tiles' => $this->tiles,
            'meta' => $this->meta,
            'sections' => array_map(fn (ReportSection $s) => $s->toPreview($rowLimit), $this->sections),
            'insights' => array_map(fn (Insight $i) => $i->toArray(), $this->insights),
            'charts' => array_map(fn (ReportChart $c) => $c->toArray(), $this->drawableCharts()),
        ];
    }
}
