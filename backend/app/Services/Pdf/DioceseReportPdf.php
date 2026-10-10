<?php

namespace App\Services\Pdf;

use App\Enums\DioceseBranding;
use App\Reports\ReportColumn;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Settings\Settings;
use App\Support\Reports\Insights\Insight;
use TCPDF;

/**
 * The PDF every report in docs/specs/reports-spec.md is drawn with - the
 * layout of v1-events-backend's TuqioReportPdf in diocese teal and gold.
 *
 * Header and footer repeat on every page. The footer carries the
 * authenticity QR code: it opens the public Verify page for this run's
 * verification code, which confirms the report came from the system.
 *
 * Layout rules kept from TuqioReportPdf:
 *   - Columns are measured from their content. Number columns get what their
 *     widest value needs and are never cut; text columns share the rest.
 *   - Orientation is automatic and decided once for the whole report:
 *     portrait when every table fits, landscape when any table doesn't
 *     (TuqioReportPdf's orientationFor()). One report, one orientation.
 *   - One type size per role; a value too wide for a text column is
 *     ellipsized, never shrunk. Long headings wrap onto two lines.
 * Insights and recommendations are the last part, and only when the report
 * has any.
 */
class DioceseReportPdf extends TCPDF
{
    public const INK = [33, 43, 54];

    public const MUTE = [98, 110, 126];

    public const LINE = [228, 233, 238];

    public const TINT = [240, 248, 251];

    public const TONES = [
        'primary' => [44, 164, 191], 'success' => [22, 150, 110], 'warning' => [176, 128, 8],
        'danger' => [214, 48, 48], 'purple' => [137, 32, 173], 'pink' => [199, 72, 138], 'muted' => [98, 110, 126],
    ];

    private const INSIGHT_TONES = [
        Insight::GOOD => ['rgb' => [22, 150, 110], 'tint' => [233, 247, 241], 'label' => 'Going well'],
        Insight::WATCH => ['rgb' => [176, 128, 8], 'tint' => [253, 246, 225], 'label' => 'Keep an eye on'],
        Insight::CONCERN => ['rgb' => [214, 48, 48], 'tint' => [253, 236, 236], 'label' => 'Needs attention'],
    ];

    private const LM = 14;

    private const LOGO = 'assets/images/logos/logo-report.png';

    private const MIN_TEXT = 34;     // below this a text column says nothing useful

    private const FLOOR_TEXT = 24;   // never squeeze a text column past this

    private const CELL_INSET = 2.4;

    /** Height of one row of a planned-against-actual chart. */
    private const HBAR_ROW = 5.8;

    private array $teal;

    private array $gold;

    private float $portraitWidth = 210;

    /** @var ReportColumn[] the table being drawn, for the band repeated on continued pages */
    private array $cols = [];

    private array $widths = [];

    private bool $inTable = false;

    private float $rowHeight = 8.6;

    /** The name, line under it and footer note from Settings > Documents & PDF. */
    private array $words;

    public function __construct(
        private ReportData $data,
        private string $verificationCode = '',
        private string $verifyUrl = '',
        private string $generatedBy = 'System',
    ) {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');

        $this->teal = $this->rgb(DioceseBranding::PRIMARY_TEAL->value);
        $this->gold = $this->rgb(DioceseBranding::SECONDARY_GOLD->value);
        $this->portraitWidth = $this->getPageWidth();
        $settings = app(Settings::class);
        $this->words = [
            'name' => (string) $settings->system('documents.org_name'),
            'subtitle' => (string) $settings->system('documents.org_subtitle'),
            'note' => (string) $settings->system('documents.footer_note'),
        ];

        $this->SetCreator('Makueni West Diocese Management System');
        $this->SetAuthor($this->words['name']);
        $this->SetTitle($data->title.' - '.$data->scopeLabel);
        $this->SetSubject($data->periodLabel);
        $this->setCellPaddings(self::CELL_INSET, 0, self::CELL_INSET, 0);
        $this->SetCellHeightRatio(1.25);
        $this->setImageScale(1.25);
    }

    // ───────────────────────────────────────────────────────── page furniture

    public function Header(): void
    {
        $w = $this->getPageWidth();
        $lm = self::LM;

        $this->SetFillColor(...$this->teal);
        $this->Rect(0, 0, $w, 1.6, 'F');
        $this->SetFillColor(...$this->gold);
        $this->Rect(0, 0, 46, 1.6, 'F');

        // A report-sized copy of the logo: the full one is 1972px / 1.5 MB and
        // would be embedded in every PDF (v1-events-backend hit the same thing).
        $logo = public_path(self::LOGO);
        if (! is_file($logo)) {
            $logo = public_path(DioceseBranding::MAIN_LOGO->value);
        }
        if (is_file($logo)) {
            $this->Image($logo, $lm, 7.5, 0, 17, 'PNG', '', 'T', false, 300);
        }
        $this->SetXY($lm + 20, 10.5);
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(...self::INK);
        $this->Cell(90, 5, $this->fit($this->words['name'], 90, 'B', 10), 0, 2, 'L');
        $this->SetFont('helvetica', '', 7.2);
        $this->SetTextColor(...self::MUTE);
        $this->Cell(90, 4, $this->fit($this->words['subtitle'], 90, '', 7.2), 0, 0, 'L');

        $this->SetXY($w / 2, 10.5);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(...$this->teal);
        $this->Cell($w / 2 - $lm, 5, $this->fit($this->data->scopeLabel, $w / 2 - $lm, 'B', 9), 0, 2, 'R');
        $this->SetFont('helvetica', '', 7.2);
        $this->SetTextColor(...self::MUTE);
        $this->Cell($w / 2 - $lm, 4, $this->data->periodLabel, 0, 0, 'R');

        $this->SetDrawColor(...self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($lm, 29, $w - $lm, 29);

        // A table carries on over the page break, so its headings do too.
        if ($this->inTable && $this->getPage() > 1) {
            $this->SetXY($lm, 32);
            $this->eyebrow($this->data->title.' - continued', $w - 2 * $lm);
            $this->SetXY($lm, 37.5);
            $this->headingBand();
        }
    }

    public function Footer(): void
    {
        $w = $this->getPageWidth();
        $lm = self::LM;
        $top = $this->getPageHeight() - 24;

        $this->SetDrawColor(...self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($lm, $top, $w - $lm, $top);

        $textX = $lm;
        if ($this->verifyUrl !== '') {
            $this->write2DBarcode($this->verifyUrl, 'QRCODE,M', $lm, $top + 3.2, 15, 15,
                ['border' => false, 'padding' => 0, 'fgcolor' => self::INK, 'bgcolor' => false], 'N');
            $textX = $lm + 18.5;
        }

        $this->SetXY($textX, $top + 4);
        $this->SetFont('helvetica', 'B', 7.4);
        $this->SetTextColor(...self::INK);
        $this->Cell(110, 3.9, $this->verifyUrl !== '' ? 'Scan to verify this report is genuine' : $this->words['name'], 0, 2, 'L');
        $this->SetFont('helvetica', '', 6.9);
        $this->SetTextColor(...self::MUTE);
        if ($this->verificationCode !== '') {
            $this->Cell(110, 3.5, 'Verification code '.$this->verificationCode, 0, 2, 'L');
        }
        $this->Cell(110, 3.5, 'Generated '.now()->format('j M Y \a\t H:i').' by '.$this->generatedBy, 0, 2, 'L');
        // A document with places to sign doesn't say "no signature needed".
        $note = $this->data->signatures !== [] ? 'Signed copies are kept with the books.' : $this->words['note'];
        if ($note !== '') {
            $this->Cell(110, 3.5, $this->fit($note, 110, '', 6.9), 0, 0, 'L');
        }

        $this->SetXY($w - $lm - 60, $top + 4);
        $this->SetFont('helvetica', 'B', 7.4);
        $this->SetTextColor(...$this->teal);
        $this->Cell(60, 3.9, $this->fit($this->words['name'], 60, 'B', 7.4), 0, 2, 'R');
        $this->SetFont('helvetica', 'B', 7.4);
        $this->SetTextColor(...self::INK);
        $this->Cell(60, 3.9, 'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }

    // ─────────────────────────────────────────────────────────────── building

    public function build(): self
    {
        $this->SetMargins(self::LM, 36, self::LM);
        $this->SetAutoPageBreak(true, 30);

        $sections = array_values(array_filter($this->data->sections, fn (ReportSection $s) => $s->rows !== [] || $s->note));
        if ($this->data->cover) {
            $this->coverPage($this->data->cover);
        }
        $this->AddPage($this->data->orientation ?? $this->orientationFor($sections));

        $cw = $this->contentWidth();
        $this->titleBlock($cw);
        $this->tileStrip($cw);
        $this->metaPanel($cw);
        $this->charts();

        foreach ($sections as $i => $section) {
            $this->section($section, $i === 0);
        }

        if ($this->data->signatures !== []) {
            $this->signatures($this->data->signatures);
        }

        if ($this->data->hasInsights()) {
            $this->insights();
        }

        return $this;
    }

    /**
     * A book's cover (the cashbook): the place's logo large, its name, what
     * the book is and for which account and period, and who prepared it. No
     * letterhead on this page; the footer (QR, page) still runs.
     */
    private function coverPage(array $cover): void
    {
        $this->setPrintHeader(false);
        $this->AddPage('P');
        $w = $this->getPageWidth();
        $lm = self::LM;
        $cw = $w - 2 * $lm;

        // The brand bands, top and bottom, flat.
        $this->SetFillColor(...$this->teal);
        $this->Rect(0, 0, $w, 6, 'F');
        $this->SetFillColor(...$this->gold);
        $this->Rect(0, 0, 70, 6, 'F');

        $logo = $cover['logo_path'] ?? null;
        if (! $logo || ! is_file($logo)) {
            $logo = public_path(self::LOGO);
            if (! is_file($logo)) {
                $logo = public_path(DioceseBranding::MAIN_LOGO->value);
            }
        }
        if (is_file($logo)) {
            $this->Image($logo, ($w - 46) / 2, 34, 46, 0, '', '', 'T', false, 300, '', false, false, 0, 'CM');
        }

        $this->SetXY($lm, 92);
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(...self::INK);
        $this->Cell($cw, 10, $this->fit($this->data->scopeLabel, $cw, 'B', 20), 0, 1, 'C');
        $this->SetX($lm);
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(...self::MUTE);
        $this->Cell($cw, 6, $this->fit(trim($this->words['name'].' · '.$this->words['subtitle'], ' ·'), $cw, '', 10), 0, 1, 'C');

        $y = $this->GetY() + 14;
        $this->SetDrawColor(...$this->gold);
        $this->SetLineWidth(1.1);
        $this->Line($w / 2 - 22, $y, $w / 2 + 22, $y);

        $this->SetXY($lm, $y + 10);
        $this->SetFont('helvetica', 'B', 34);
        $this->SetFontSpacing(1.2);
        $this->SetTextColor(...$this->teal);
        $this->Cell($cw, 16, strtoupper((string) $cover['title']), 0, 1, 'C');
        $this->SetFontSpacing(0);
        if (! empty($cover['subtitle'])) {
            $this->SetX($lm);
            $this->SetFont('helvetica', 'B', 14);
            $this->SetTextColor(...self::INK);
            $this->Cell($cw, 9, $this->fit((string) $cover['subtitle'], $cw, 'B', 14), 0, 1, 'C');
        }
        $this->SetX($lm);
        $this->SetFont('helvetica', '', 11);
        $this->SetTextColor(...self::MUTE);
        $this->Cell($cw, 7, $this->data->periodLabel, 0, 1, 'C');

        $lines = $cover['lines'] ?? [];
        if ($lines !== []) {
            $boxW = 130;
            $x = ($w - $boxW) / 2;
            $top = $this->GetY() + 16;
            $h = 8 + 8 * count($lines);
            $this->SetFillColor(...self::TINT);
            $this->Rect($x, $top, $boxW, $h, 'F');
            $i = 0;
            foreach ($lines as $label => $value) {
                $this->SetXY($x + 8, $top + 5 + $i * 8);
                $this->SetFont('helvetica', 'B', 7);
                $this->SetFontSpacing(0.4);
                $this->SetTextColor(...self::MUTE);
                $this->Cell(42, 5, strtoupper((string) $label), 0, 0, 'L');
                $this->SetFontSpacing(0);
                $this->SetFont('helvetica', 'B', 10);
                $this->SetTextColor(...self::INK);
                $this->Cell($boxW - 58, 5, $this->fit((string) $value, $boxW - 58, 'B', 10), 0, 0, 'L');
                $i++;
            }
        }

        $this->SetFillColor(...$this->teal);
        $this->Rect(0, $this->getPageHeight() - 30, $w, 1.2, 'F');
        $this->setPrintHeader(true);
    }

    /** Boxes to sign in: a line, who signs, their name and the date. */
    private function signatures(array $signatures): void
    {
        $signatures = array_slice($signatures, 0, 4);
        if ($this->GetY() + 34 > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage($this->CurOrientation);
        }
        $lm = self::LM;
        $cw = $this->contentWidth();
        $n = count($signatures);
        $gap = 6;
        $boxW = ($cw - $gap * ($n - 1)) / $n;
        $this->Ln(6);
        $this->SetX($lm);
        $this->eyebrow('Signatures', $cw, 7, $this->teal);
        $y = $this->GetY() + 2;
        foreach ($signatures as $i => $sig) {
            $x = $lm + $i * ($boxW + $gap);
            $this->SetDrawColor(...self::LINE);
            $this->SetLineWidth(0.3);
            $this->RoundedRect($x, $y, $boxW, 30, 1.8, '1111', 'D');
            $this->SetDrawColor(...self::INK);
            $this->SetLineWidth(0.25);
            $this->Line($x + 4, $y + 15, $x + $boxW - 4, $y + 15);
            $this->SetXY($x + 2, $y + 16);
            $this->eyebrow((string) $sig['label'], $boxW - 4, 6.4);
            $this->SetX($x + 2);
            $this->SetFont('helvetica', 'B', 8.6);
            $this->SetTextColor(...self::INK);
            $this->Cell($boxW - 4, 4.6, $this->fit((string) ($sig['name'] ?? ''), $boxW - 4, 'B', 8.6), 0, 2, 'L');
            $this->SetFont('helvetica', '', 7.6);
            $this->SetTextColor(...self::MUTE);
            $this->Cell($boxW - 4, 4, $this->fit('Date: '.($sig['date'] ?? '______________'), $boxW - 4, '', 7.6), 0, 0, 'L');
        }
        $this->SetY($y + 36);
    }

    /** The finished file as a string (PHP method names are case-insensitive, so not output()). */
    public function toPdfString(): string
    {
        return $this->Output('', 'S');
    }

    private function section(ReportSection $section, bool $first): void
    {
        $rows = $section->displayRows();

        // Don't strand a heading at the foot of a page.
        // (The first section too: charts above it can leave it near the foot.)
        if ($this->GetY() + 40 > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage($this->CurOrientation);
        }

        $cw = $this->contentWidth();
        $this->rowHeight = $this->getPageWidth() > 250 ? 7.8 : 8.6;

        $this->SetX(self::LM);
        if (! $first) {
            $this->Ln(3);
        }
        $this->SetFont('helvetica', 'B', 11.5);
        $this->SetTextColor(...self::INK);
        $this->Cell($cw, 6.5, $section->heading, 0, 1, 'L');
        if ($section->note) {
            $this->SetX(self::LM);
            $this->SetFont('helvetica', 'I', 8.2);
            $this->SetTextColor(...self::MUTE);
            $this->MultiCell($cw, 4.4, $section->note, 0, 'L');
        }
        $this->Ln(1.5);

        if ($rows === []) {
            return;
        }

        $this->cols = $section->columns;
        $this->widths = $this->distribute($section->columns, $rows, $cw);
        $this->headingBand();
        $this->inTable = true;
        $this->SetTopMargin(51.5);   // continued pages start below the repeated band

        foreach ($rows as $row) {
            $this->tableRow($row);
        }

        $totals = $section->displayTotals();
        if ($totals !== null) {
            $this->totalsRow($totals);
        }

        $this->inTable = false;
        $this->SetTopMargin(36);
        $this->Ln(4);
    }

    private function titleBlock(float $cw): void
    {
        $lm = self::LM;
        $this->SetX($lm);
        $this->eyebrow($this->data->kicker, $cw, 7, $this->teal);

        $this->SetX($lm);
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(...self::INK);
        $this->Cell($cw, 10, $this->data->title, 0, 1, 'L');

        // A rule the width of the title, not the page.
        $titleW = min($cw, $this->GetStringWidth($this->data->title) + 2);
        $y = $this->GetY() + 0.5;
        $this->SetDrawColor(...$this->gold);
        $this->SetLineWidth(1.1);
        $this->Line($lm, $y, $lm + $titleW, $y);

        $this->SetXY($lm, $y + 2.5);
        $this->SetFont('helvetica', '', 9.5);
        $this->SetTextColor(...self::MUTE);
        $this->Cell($cw, 5, $this->data->periodLabel.'   ·   '.$this->data->scopeLabel, 0, 1, 'L');
        $this->Ln(4);
    }

    private function tileStrip(float $cw): void
    {
        $tiles = array_slice($this->data->tiles, 0, 5);
        if ($tiles === []) {
            return;
        }

        $lm = self::LM;
        $colW = $cw / count($tiles);
        $y = $this->GetY();

        foreach ($tiles as $i => $tile) {
            $x = $lm + $i * $colW;
            if ($i > 0) {
                $this->SetDrawColor(...self::LINE);
                $this->SetLineWidth(0.25);
                $this->Line($x, $y + 1, $x, $y + 15);
            }
            $inner = $colW - ($i ? 5 : 0) - 2;
            $this->SetXY($x + ($i ? 5 : 0), $y + 1.5);
            $this->eyebrow($tile['label'], $inner, 6.4, self::MUTE, 'L', 0.25);
            // A long figure gets smaller type (down to 9pt) before it's ever shortened.
            $size = 13;
            $this->SetFont('helvetica', 'B', $size);
            while ($size > 9 && $this->GetStringWidth((string) $tile['value']) > $inner - 2 * self::CELL_INSET) {
                $size -= 0.5;
                $this->SetFont('helvetica', 'B', $size);
            }
            $this->SetX($x + ($i ? 5 : 0));
            $this->SetTextColor(...(self::TONES[$tile['tone'] ?? 'primary'] ?? $this->teal));
            $this->Cell($inner, 7, $this->fit((string) $tile['value'], $inner + 2, 'B', $size), 0, 1, 'L');
        }

        $this->SetY($y + 20);
    }

    private function metaPanel(float $cw): void
    {
        $meta = $this->data->meta;
        if ($meta === []) {
            return;
        }

        $lm = self::LM;
        $h = 7 + 6.2 * ceil(count($meta) / 2);
        $y = $this->GetY();

        $this->SetFillColor(...self::TINT);
        $this->Rect($lm, $y, $cw, $h, 'F');
        $this->SetFillColor(...$this->teal);
        $this->Rect($lm, $y, 1.6, $h, 'F');

        $i = 0;
        $colW = ($cw - 12) / 2;
        foreach ($meta as $key => $value) {
            $x = $lm + 7 + ($i % 2) * $colW;
            $this->SetXY($x, $y + 4.2 + intdiv($i, 2) * 6.2);
            $this->SetFont('helvetica', 'B', 6.6);
            $this->SetFontSpacing(0.2);
            $this->SetTextColor(...self::MUTE);
            $this->Cell(37, 4.6, strtoupper((string) $key), 0, 0, 'L');
            $this->SetFontSpacing(0);
            $this->SetFont('helvetica', '', 9);
            $this->SetTextColor(...self::INK);
            $this->Cell($colW - 39, 4.6, $this->fit((string) $value, $colW - 39, '', 9), 0, 0, 'L');
            $i++;
        }

        $this->SetY($y + $h + 7);
    }

    private function tableRow(array $row): void
    {
        $this->SetTextColor(...self::INK);
        $this->SetDrawColor(...self::LINE);
        $this->SetLineWidth(0.2);
        foreach ($this->cols as $i => $col) {
            $style = $col->strong ? 'B' : '';
            $text = $this->fit((string) ($row[$i] ?? ''), $this->widths[$i], $style, 9);
            $this->SetFont('helvetica', $style, 9);
            $this->Cell($this->widths[$i], $this->rowHeight, $text, 'B', $i === count($this->cols) - 1 ? 1 : 0, $col->align);
        }
    }

    private function totalsRow(array $cells): void
    {
        $this->SetFillColor(...self::TINT);
        $this->SetTextColor(...$this->teal);
        foreach ($this->cols as $i => $col) {
            $this->SetFont('helvetica', 'B', 9);
            $text = $this->fit((string) ($cells[$i] ?? ''), $this->widths[$i], 'B', 9);
            $this->Cell($this->widths[$i], $this->rowHeight, $text === '-' && $i > 0 ? '' : $text, 0, $i === count($this->cols) - 1 ? 1 : 0, $col->align, true);
        }
    }

    /** "What we noticed" cards, then numbered recommendations - always the last part. */
    private function insights(): void
    {
        if ($this->GetY() + 50 > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage($this->CurOrientation);
        }
        $lm = self::LM;
        $cw = $this->contentWidth();

        $this->Ln(3);
        $this->SetX($lm);
        $this->eyebrow('Insights', $cw, 7, $this->teal);
        $this->SetX($lm);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetTextColor(...self::INK);
        $this->Cell($cw, 7, 'What we noticed', 0, 1, 'L');
        $this->Ln(2);

        foreach ($this->data->insights as $insight) {
            $tone = self::INSIGHT_TONES[$insight->tone] ?? self::INSIGHT_TONES[Insight::WATCH];
            $this->SetFont('helvetica', '', 8.8);
            $detailH = $this->getStringHeight($cw - 12, $insight->detail);
            $h = 5 + 5.2 + $detailH + 3;
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage($this->CurOrientation);
            }
            $y = $this->GetY();

            $this->SetFillColor(...$tone['tint']);
            $this->Rect($lm, $y, $cw, $h, 'F');
            $this->SetFillColor(...$tone['rgb']);
            $this->Rect($lm, $y, 1.4, $h, 'F');

            $this->SetXY($lm + 5, $y + 3);
            $this->SetFont('helvetica', 'B', 6.4);
            $this->SetFontSpacing(0.5);
            $this->SetTextColor(...$tone['rgb']);
            $this->Cell(40, 3.6, strtoupper($tone['label']), 0, 0, 'L');
            $this->SetFontSpacing(0);
            $this->SetXY($lm + 5, $y + 6.8);
            $this->SetFont('helvetica', 'B', 10);
            $this->SetTextColor(...self::INK);
            $this->Cell($cw - 10, 5, $this->fit($insight->title, $cw - 10, 'B', 10), 0, 1, 'L');
            $this->SetX($lm + 5);
            $this->SetFont('helvetica', '', 8.8);
            $this->SetTextColor(...self::MUTE);
            $this->MultiCell($cw - 12, 4.2, $insight->detail, 0, 'L');
            $this->SetY($y + $h + 2.2);
        }

        $recommendations = $this->data->recommendations();
        if ($recommendations === []) {
            return;
        }

        if ($this->GetY() + 30 > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage($this->CurOrientation);
        }
        $this->Ln(3);
        $this->SetX($lm);
        $this->SetFont('helvetica', 'B', 13);
        $this->SetTextColor(...self::INK);
        $this->Cell($cw, 7, 'Recommendations', 0, 1, 'L');
        $this->Ln(1.5);

        foreach ($recommendations as $n => $text) {
            $this->SetFont('helvetica', '', 9.2);
            $h = max(7, $this->getStringHeight($cw - 12, $text) + 2);
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage($this->CurOrientation);
            }
            $y = $this->GetY();
            $this->SetFillColor(...$this->teal);
            $this->RoundedRect($lm, $y + 0.6, 5.4, 5.4, 2.7, '1111', 'F');
            $this->SetXY($lm, $y + 0.6);
            $this->SetFont('helvetica', 'B', 7.4);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(5.4, 5.4, (string) ($n + 1), 0, 0, 'C');
            $this->SetXY($lm + 8, $y + 0.8);
            $this->SetFont('helvetica', '', 9.2);
            $this->SetTextColor(...self::INK);
            $this->MultiCell($cw - 10, 4.6, $text, 0, 'L');
            $this->SetY(max($this->GetY(), $y + $h) + 1.2);
        }
    }

    // ───────────────────────────────────────────────────────── layout helpers

    // --------------------------------------------------------------- charts

    /** "At a glance": the report's charts, drawn with plain shapes in the brand tones. */
    private function charts(): void
    {
        $charts = $this->data->drawableCharts();
        if ($charts === []) {
            return;
        }
        $lm = self::LM;
        $cw = $this->contentWidth();
        // Two line charts in a row sit side by side, so they share the space.
        $rows = [];
        foreach ($charts as $chart) {
            $last = count($rows) - 1;
            if ($chart->kind === 'hbars' && $last >= 0 && count($rows[$last]) === 1 && $rows[$last][0]->kind === 'hbars') {
                $rows[$last][] = $chart;
            } else {
                $rows[] = [$chart];
            }
        }
        foreach ($rows as $r => $row) {
            $h = max(array_map(fn ($c) => $c->kind === 'hbars' ? 14 + count($c->categories) * self::HBAR_ROW : 62, $row));
            // Room for the heading line and the note too.
            if ($this->GetY() + $h + 10 > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage($this->CurOrientation);
            }
            if ($r === 0) {
                $this->SetX($lm);
                $this->eyebrow('At a glance', $cw, 7, $this->teal);
            }
            $gap = count($row) > 1 ? 8 : 0;
            $colW = ($cw - $gap) / count($row);
            $top = $this->GetY();
            foreach ($row as $k => $chart) {
                $x = $lm + $k * ($colW + $gap);
                $this->SetXY($x, $top);
                $this->SetFont('helvetica', 'B', count($row) > 1 ? 10 : 11);
                $this->SetTextColor(...self::INK);
                $titleW = count($row) > 1 ? $colW : $colW * 0.55;
                $this->Cell($titleW, 6, $this->fit($chart->title, $titleW, 'B', count($row) > 1 ? 10 : 11), 0, 0, 'L');
                if (count($row) > 1) {
                    $this->SetXY($x, $top + 6);
                    $this->chartLegend($chart, $x, $colW, 'L');
                    $y = $top + 12;
                } else {
                    $this->chartLegend($chart, $x + $colW * 0.55, $colW * 0.45);
                    $y = $top + 7;
                }
                $chart->kind === 'hbars' ? $this->hbars($chart, $x, $y, $colW) : $this->bars($chart, $x, $y, $colW, $h - 8);
                if ($chart->note) {
                    $this->SetXY($x, $top + $h + (count($row) > 1 ? 0 : 1));
                    $this->SetFont('helvetica', 'I', 7.5);
                    $this->SetTextColor(...self::MUTE);
                    $this->Cell($colW, 4, $this->fit($chart->note, $colW, 'I', 7.5), 0, 0, 'L');
                }
            }
            $this->SetY($top + $h + 5);
            $this->Ln(3);
        }
    }

    /** The series names with their colour keys, right-aligned on the title line. */
    private function chartLegend(\App\Reports\ReportChart $chart, float $x, float $w, string $align = 'R'): void
    {
        $this->SetFont('helvetica', '', 7.5);
        $items = array_map(fn ($s) => $s['name'], $chart->series);
        $total = array_sum(array_map(fn ($n) => $this->GetStringWidth($n) + 7, $items));
        $cx = $align === 'L' ? $x : $x + max(0, $w - $total);
        $y = $this->GetY();
        foreach ($chart->series as $s) {
            $this->SetFillColor(...$this->tone($s));
            $this->Rect($cx, $y + 1.8, 2.6, 2.6, 'F');
            $this->SetXY($cx + 3.4, $y);
            $this->SetTextColor(...self::INK);
            $this->Cell($this->GetStringWidth($s['name']) + 2, 6, $s['name'], 0, 0, 'L');
            $cx += $this->GetStringWidth($s['name']) + 7;
        }
        $this->SetXY($x + $w, $y);
    }

    /** Grouped columns: an axis of short-money ticks, gridlines, one group per category. */
    private function bars(\App\Reports\ReportChart $chart, float $x, float $y, float $w, float $h): void
    {
        $axisW = 14;
        $labelH = 6;
        $plotX = $x + $axisW;
        $plotW = $w - $axisW;
        $plotH = $h - $labelH;
        $max = 0.0;
        foreach ($chart->series as $s) {
            $max = max($max, ...array_map('floatval', $s['values']));
        }
        $top = self::niceCeiling($max);

        // Gridlines and ticks
        $this->SetFont('helvetica', '', 6.6);
        for ($t = 0; $t <= 4; $t++) {
            $gy = $y + $plotH - $plotH * $t / 4;
            $this->SetDrawColor(...self::LINE);
            $this->SetLineWidth(0.2);
            $this->Line($plotX, $gy, $plotX + $plotW, $gy);
            $this->SetTextColor(...self::MUTE);
            $this->SetXY($x, $gy - 2);
            $this->Cell($axisW - 1.5, 4, self::short($top * $t / 4), 0, 0, 'R');
        }

        $n = max(1, count($chart->categories));
        $groupW = $plotW / $n;
        $count = max(1, count($chart->series));
        $barW = min(7, $groupW * 0.72 / $count);
        foreach ($chart->categories as $i => $label) {
            $gx = $plotX + $i * $groupW + ($groupW - $barW * $count) / 2;
            foreach ($chart->series as $k => $s) {
                $v = (float) ($s['values'][$i] ?? 0);
                $bh = $top > 0 ? $plotH * $v / $top : 0;
                if ($bh > 0) {
                    $this->SetFillColor(...$this->tone($s));
                    $this->Rect($gx + $k * $barW, $y + $plotH - $bh, $barW - 0.4, $bh, 'F');
                }
            }
            $this->SetFont('helvetica', 'B', 6.8);
            $this->SetTextColor(...self::INK);
            $this->SetXY($plotX + $i * $groupW, $y + $plotH + 1);
            $this->Cell($groupW, 4, $this->fit((string) $label, $groupW + 2 * self::CELL_INSET, 'B', 6.8), 0, 0, 'C');
        }
    }

    /** One row per category: the first series soft and full width behind, the second solid on top. */
    private function hbars(\App\Reports\ReportChart $chart, float $x, float $y, float $w): void
    {
        $labelW = min(50, $w * 0.38);
        $valueW = 20;
        $plotX = $x + $labelW;
        $plotW = $w - $labelW - $valueW;
        $max = 0.0;
        foreach ($chart->series as $s) {
            $max = max($max, ...array_map('floatval', $s['values']));
        }
        $max = max($max, 1.0);
        $back = $chart->series[0] ?? null;
        $front = $chart->series[1] ?? null;
        foreach ($chart->categories as $i => $label) {
            $ry = $y + $i * self::HBAR_ROW;
            $this->SetXY($x, $ry);
            $this->SetFont('helvetica', '', 7.6);
            $this->SetTextColor(...self::INK);
            $this->Cell($labelW - 1, 5.6, $this->fit((string) $label, $labelW - 1, '', 7.6), 0, 0, 'L');
            // The track
            $this->SetFillColor(...self::LINE);
            $this->Rect($plotX, $ry + 1.2, $plotW, 3.2, 'F');
            $b = (float) ($back['values'][$i] ?? 0);
            if ($back && $b > 0) {
                $this->SetFillColor(...$this->tone($back));
                $this->Rect($plotX, $ry + 1.2, $plotW * min($b, $max) / $max, 3.2, 'F');
            }
            $f = (float) ($front['values'][$i] ?? 0);
            if ($front && $f > 0) {
                $over = $chart->overIsBad && $f > $b && $b > 0;
                $this->SetFillColor(...($over ? self::TONES['danger'] : $this->tone($front)));
                $this->Rect($plotX, $ry + 2.0, $plotW * min($f, $max) / $max, 1.6, 'F');
            }
            $this->SetFont('helvetica', 'B', 7.4);
            $this->SetTextColor(...(($chart->overIsBad && $f > $b && $b > 0) ? self::TONES['danger'] : self::INK));
            $this->SetXY($plotX + $plotW + 1, $ry);
            $this->Cell($valueW - 1, 6, self::short($f).($b > 0 ? ' / '.self::short($b) : ''), 0, 0, 'R');
        }
    }

    /** A series' colour; a soft series is its tone mixed 65% with white. */
    private function tone(array $series): array
    {
        $rgb = self::TONES[$series['tone'] ?? 'primary'] ?? self::TONES['primary'];

        return ! empty($series['soft']) ? array_map(fn ($c) => (int) round($c + (255 - $c) * 0.65), $rgb) : $rgb;
    }

    /** A round number at or above the largest value, for the axis top. */
    private static function niceCeiling(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $pow = 10 ** floor(log10($max));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $pow >= $max) {
                return $step * $pow;
            }
        }

        return 10 * $pow;
    }

    /** 250K, 1.5M - axis and bar labels. */
    private static function short(float $v): string
    {
        $a = abs($v);
        $fmt = fn ($n) => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        return match (true) {
            $a >= 1e6 => $fmt($v / 1e6).'M',
            $a >= 1e4 => round($v / 1e3).'K',
            $a >= 1e3 => $fmt($v / 1e3).'K',
            default => (string) round($v),
        };
    }

    private function contentWidth(): float
    {
        return $this->getPageWidth() - 2 * self::LM;
    }

    private function fit(string $text, float $width, string $style = '', float $size = 9): string
    {
        $this->SetFont('helvetica', $style, $size);
        $usable = $width - 2 * self::CELL_INSET - 0.4;
        if ($usable <= 0 || $this->GetStringWidth($text) <= $usable) {
            return $text;
        }
        while ($text !== '' && $this->GetStringWidth($text.'…') > $usable) {
            $text = mb_substr($text, 0, mb_strlen($text) - 1);
        }

        return rtrim($text).'…';
    }

    /** Letterspaced small caps - every label in the document uses this. */
    private function eyebrow(string $text, float $w, float $size = 7, array $rgb = self::MUTE, string $align = 'L', float $spacing = 0.7): void
    {
        $this->SetFont('helvetica', 'B', $size);
        $this->SetFontSpacing($spacing);
        $this->SetTextColor(...$rgb);
        $label = strtoupper($text);
        $usable = $w - 2 * self::CELL_INSET;
        $cut = false;
        while ($label !== '' && $this->GetStringWidth($label.($cut ? '…' : '')) > $usable) {
            $label = mb_substr($label, 0, mb_strlen($label) - 1);
            $cut = true;
        }
        $this->Cell($w, 4.4, $cut ? rtrim($label).'…' : $label, 0, 1, $align);
        $this->SetFontSpacing(0);
    }

    /**
     * The whole report's orientation: landscape if any table needs it.
     *
     * @param  ReportSection[]  $sections
     */
    public function orientationFor(array $sections): string
    {
        foreach ($sections as $section) {
            if ($section->rows !== [] && $this->chooseOrientation($section->columns, $section->displayRows()) === 'L') {
                return 'L';
            }
        }

        return 'P';
    }

    /** What each column needs, in mm. A heading only has to fit its longest word - long headings wrap. */
    private function naturalWidths(array $cols, array $rows): array
    {
        $chrome = 2 * self::CELL_INSET + 1.2;
        $need = [];
        foreach ($cols as $i => $col) {
            $this->SetFont('helvetica', 'B', 7.4);
            $widest = max(array_map(fn ($word) => $this->GetStringWidth($word), explode(' ', strtoupper($col->header))));
            $this->SetFont('helvetica', $col->strong ? 'B' : '', 9);
            foreach ($rows as $row) {
                $widest = max($widest, $this->GetStringWidth((string) ($row[$i] ?? '')));
            }
            $need[$i] = $widest + $chrome;
        }

        return $need;
    }

    /**
     * Portrait unless the columns genuinely won't fit: every number column gets
     * what it needs AND the text columns keep enough room to say something.
     *
     * @param  ReportColumn[]  $cols
     */
    public function chooseOrientation(array $cols, array $rows): string
    {
        $need = $this->naturalWidths($cols, $rows);
        $flex = array_keys(array_filter($cols, fn (ReportColumn $c) => $c->align === 'L'));
        $fixed = array_sum(array_diff_key($need, array_flip($flex)));

        return $fixed + count($flex) * self::MIN_TEXT <= $this->portraitWidth - 2 * self::LM ? 'P' : 'L';
    }

    /**
     * Share the width out. Numbers always get what they need; when text columns
     * want more than is left, the squeeze comes off the widest first and none
     * drops below a floor.
     */
    private function distribute(array $cols, array $rows, float $cw): array
    {
        $need = $this->naturalWidths($cols, $rows);
        $flex = array_keys(array_filter($cols, fn (ReportColumn $c) => $c->align === 'L'));
        $total = array_sum($need);

        if (! $flex || $total > $cw && array_sum(array_diff_key($need, array_flip($flex))) >= $cw) {
            foreach ($need as $i => $v) {
                $need[$i] = $cw * $v / $total;
            }

            return array_values($need);
        }

        if ($total <= $cw) {
            // Spare room goes to the text columns, and to the numbers so a
            // short table still spans the page.
            $spare = $cw - $total;
            foreach ($need as $i => $v) {
                $need[$i] += $spare * $v / $total;
            }
        } else {
            $excess = $total - $cw;
            $room = 0;
            foreach ($flex as $i) {
                $room += max(0, $need[$i] - self::FLOOR_TEXT);
            }
            foreach ($flex as $i) {
                $give = max(0, $need[$i] - self::FLOOR_TEXT);
                $need[$i] -= $room > 0 ? $excess * $give / $room : 0;
            }
            if (array_sum($need) > $cw + 0.1) {
                $t = array_sum($need);
                foreach ($need as $i => $v) {
                    $need[$i] = $cw * $v / $t;
                }
            }
        }
        ksort($need);

        return array_values($need);
    }

    /** The teal heading band, repeated at the top of every continued page. */
    private function headingBand(): void
    {
        $h = 11.5;
        $x0 = self::LM;
        $y0 = $this->GetY();

        $this->SetFillColor(...$this->teal);
        $this->Rect($x0, $y0, array_sum($this->widths), $h, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 7.2);

        $x = $x0;
        foreach ($this->cols as $i => $col) {
            $cw = $this->widths[$i];
            // Inset by hand: TCPDF zeroes cell padding inside Header().
            $inner = $cw - 2 * self::CELL_INSET;
            $ix = $x + self::CELL_INSET;
            $label = strtoupper($col->header);

            if ($this->GetStringWidth($label) <= $inner || ! str_contains($label, ' ')) {
                $this->SetXY($ix, $y0);
                $this->Cell($inner, $h, $this->fit($label, $cw, 'B', 7.2), 0, 0, $col->align);
                $this->SetFont('helvetica', 'B', 7.2);
            } else {
                $words = explode(' ', $label);
                $best = 1;
                $diff = PHP_INT_MAX;
                for ($k = 1; $k < count($words); $k++) {
                    $a = $this->GetStringWidth(implode(' ', array_slice($words, 0, $k)));
                    $b = $this->GetStringWidth(implode(' ', array_slice($words, $k)));
                    if (abs($a - $b) < $diff) {
                        $diff = abs($a - $b);
                        $best = $k;
                    }
                }
                $this->SetXY($ix, $y0 + 1.4);
                $this->Cell($inner, 4.4, implode(' ', array_slice($words, 0, $best)), 0, 0, $col->align);
                $this->SetXY($ix, $y0 + 5.8);
                $this->Cell($inner, 4.4, implode(' ', array_slice($words, $best)), 0, 0, $col->align);
            }
            $x += $cw;
        }

        $this->SetXY($x0, $y0 + $h);
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
