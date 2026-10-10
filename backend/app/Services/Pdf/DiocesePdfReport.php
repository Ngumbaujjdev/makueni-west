<?php

namespace App\Services\Pdf;

use App\Enums\DioceseBranding;
use TCPDF;

/**
 * Shared boilerplate for every branded PDF report this app generates -
 * logo header, KPI boxes, a bordered/zebra-striped table grid, an
 * insights bullet list, and a report-ID/QR footer.
 *
 * Modeled on the sibling project ifms-core-server's TCPDF reports
 * (app/Http/Controllers/Api/MainReportsController.php there), but unlike
 * that codebase - where every report type copy-pastes this same
 * boilerplate inline - it's factored into one base class here so a
 * second report type (e.g. Demographics & Growth, later) only needs its
 * own content-building subclass, not a re-implementation of the chrome.
 *
 * Colors/logo come from App\Enums\DioceseBranding, the same enum the
 * mail templates already use - not re-declared here, so the PDF and the
 * rest of the app can't drift out of sync on what "diocese teal" means.
 */
abstract class DiocesePdfReport extends TCPDF
{
    private const MARGIN = 15;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(true, self::MARGIN);
        $this->setCreator('Makueni West Diocese Management System');
    }

    /**
     * Logo + title + subtitle block that opens every report. Call once,
     * before any content methods below.
     */
    protected function initReport(string $title, string $subtitle): void
    {
        $this->SetTitle($title);
        $this->AddPage();

        $logoPath = public_path(DioceseBranding::MAIN_LOGO->value);
        if (is_file($logoPath)) {
            $logoWidth = 28;
            $this->Image($logoPath, ($this->getPageWidth() - $logoWidth) / 2, self::MARGIN, $logoWidth, 0, 'PNG');
            $this->SetY(self::MARGIN + 22);
        }

        $this->applyTextColor(DioceseBranding::TEXT_BLACK);
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 8, $title, 0, 1, 'C');

        $this->SetFont('helvetica', '', 10);
        $this->applyTextColor(DioceseBranding::DARK_GRAY);
        $this->Cell(0, 6, $subtitle, 0, 1, 'C');

        $this->Ln(3);
        $this->applyDrawColor(DioceseBranding::LIGHT_GRAY);
        $this->Line(self::MARGIN, $this->GetY(), $this->getPageWidth() - self::MARGIN, $this->GetY());
        $this->Ln(5);
    }

    /**
     * A row of evenly-spaced KPI boxes, e.g. [['label' => 'Total
     * Gatherings', 'value' => '37', 'color' => DioceseBranding::PRIMARY_TEAL], ...].
     * `color` is optional, defaults to primary teal.
     */
    protected function addKpiBoxes(array $boxes): void
    {
        if (empty($boxes)) {
            return;
        }

        $gap = 4;
        $usableWidth = $this->getPageWidth() - (2 * self::MARGIN);
        $boxWidth = ($usableWidth - ($gap * (count($boxes) - 1))) / count($boxes);
        $innerWidth = $boxWidth - 4;
        $startY = $this->GetY();
        $x = self::MARGIN;

        $topPad = 3;
        $valueLineHeight = 6;
        $labelLineHeight = 3.2;
        $gapBetween = 2;
        $bottomPad = 3;

        // Two-pass: a value isn't always a short number - "Most Active
        // Type" is a descriptive string (e.g. "Tuesday Fellowship (2x)")
        // that doesn't fit one line at this box width, and Cell() (used
        // here previously) doesn't wrap - it just spills past the box
        // border. Measure every box's line count up front with
        // getNumLines() so the whole row can share one common height,
        // keeping the boxes visually aligned regardless of which one
        // wraps.
        $this->SetFont('helvetica', 'B', 14);
        $valueLines = array_map(fn (array $box) => $this->getNumLines((string) $box['value'], $innerWidth), $boxes);
        $this->SetFont('helvetica', '', 7.5);
        $labelLines = array_map(fn (array $box) => $this->getNumLines(strtoupper((string) $box['label']), $innerWidth), $boxes);

        $rowHeights = array_map(
            fn (int $i) => $topPad + ($valueLines[$i] * $valueLineHeight) + $gapBetween + ($labelLines[$i] * $labelLineHeight) + $bottomPad,
            array_keys($boxes)
        );
        $boxHeight = max(26, ...$rowHeights);

        foreach ($boxes as $i => $box) {
            $color = $box['color'] ?? DioceseBranding::PRIMARY_TEAL;

            $this->applyDrawColor($color);
            $this->Rect($x, $startY, $boxWidth, $boxHeight, 'D');

            $this->SetXY($x + 2, $startY + $topPad);
            $this->applyTextColor($color);
            $this->SetFont('helvetica', 'B', 14);
            $this->MultiCell($innerWidth, $valueLineHeight, (string) $box['value'], 0, 'L', false, 1);

            $this->SetXY($x + 2, $startY + $topPad + ($valueLines[$i] * $valueLineHeight) + $gapBetween);
            $this->applyTextColor(DioceseBranding::DARK_GRAY);
            $this->SetFont('helvetica', '', 7.5);
            $this->MultiCell($innerWidth, $labelLineHeight, strtoupper((string) $box['label']), 0, 'L', false, 1);

            $x += $boxWidth + $gap;
        }

        $this->SetXY(self::MARGIN, $startY + $boxHeight + 6);
    }

    /**
     * A bold, colored section heading - "TICKET STATUS BREAKDOWN" style.
     */
    protected function addSectionTitle(string $title): void
    {
        $this->SetFont('helvetica', 'B', 12);
        $this->applyTextColor(DioceseBranding::TEXT_BLACK);
        $this->Cell(0, 8, $title, 0, 1, 'L');
        $this->Ln(1);
    }

    /**
     * A bordered table: filled/white header row, zebra-striped body.
     * $widths is a list of column widths in mm, summing to roughly the
     * usable page width; omit to split evenly.
     */
    protected function addTable(array $headers, array $rows, ?array $widths = null): void
    {
        $usableWidth = $this->getPageWidth() - (2 * self::MARGIN);
        $widths ??= array_fill(0, count($headers), $usableWidth / count($headers));
        $rowHeight = 7;

        $this->SetFont('helvetica', 'B', 9);
        $this->applyFillColor(DioceseBranding::PRIMARY_TEAL);
        $this->SetTextColor(255, 255, 255);
        foreach ($headers as $i => $header) {
            $this->Cell($widths[$i], $rowHeight, $header, 1, 0, 'L', true);
        }
        $this->Ln();

        $this->SetFont('helvetica', '', 9);
        foreach ($rows as $rowIndex => $row) {
            $stripe = $rowIndex % 2 === 1;
            $this->applyFillColor(DioceseBranding::LIGHT_GRAY);
            $this->applyTextColor(DioceseBranding::TEXT_BLACK);
            foreach (array_values($row) as $i => $cell) {
                $this->Cell($widths[$i], $rowHeight, (string) $cell, 1, 0, 'L', $stripe);
            }
            $this->Ln();
        }

        $this->Ln(4);
    }

    /**
     * "RECOMMENDATIONS & INSIGHTS" style bullet list.
     */
    protected function addInsightsList(string $sectionTitle, array $lines): void
    {
        if (empty($lines)) {
            return;
        }

        $this->addSectionTitle($sectionTitle);
        $this->SetFont('helvetica', '', 10);
        $this->applyTextColor(DioceseBranding::TEXT_BLACK);

        foreach ($lines as $line) {
            $this->Cell(5, 6, chr(149), 0, 0, 'L');
            $this->MultiCell(0, 6, $line, 0, 'L');
        }

        $this->Ln(2);
    }

    /**
     * QR code + report ID + generated-on timestamp + "computer-generated
     * document" disclaimer - called once, at the very end of the report
     * content. $qrContent, when given, is encoded as a small "Report
     * Authentication" QR code above the disclaimer text - matching the
     * pattern ifms-core-server's own reports use, minus a live
     * verification endpoint (out of scope here; this is a lightweight
     * authenticity marker a viewer can read the encoded text from, not a
     * scan-to-verify flow).
     */
    protected function addReportFooter(string $reportId, ?string $qrContent = null): void
    {
        $this->Ln(4);
        $this->applyDrawColor(DioceseBranding::LIGHT_GRAY);
        $this->Line(self::MARGIN, $this->GetY(), $this->getPageWidth() - self::MARGIN, $this->GetY());
        $this->Ln(4);

        if ($qrContent !== null) {
            $qrSize = 20;
            $this->write2DBarcode($qrContent, 'QRCODE,L', ($this->getPageWidth() - $qrSize) / 2, $this->GetY(), $qrSize, $qrSize, [], 'C');
            $this->SetY($this->GetY() + $qrSize + 2);
        }

        $this->SetFont('helvetica', 'I', 8);
        $this->applyTextColor(DioceseBranding::DARK_GRAY);
        $this->MultiCell(0, 5, 'This is a computer-generated document and does not require a signature.', 0, 'C');
        $this->Cell(0, 5, 'Generated on: '.now()->format('F j, Y g:i A'), 0, 1, 'C');
        $this->Cell(0, 5, 'Report ID: '.$reportId, 0, 1, 'C');
    }

    private function applyTextColor(DioceseBranding $color): void
    {
        [$r, $g, $b] = $this->hexToRgb($color->value);
        $this->SetTextColor($r, $g, $b);
    }

    private function applyFillColor(DioceseBranding $color): void
    {
        [$r, $g, $b] = $this->hexToRgb($color->value);
        $this->SetFillColor($r, $g, $b);
    }

    private function applyDrawColor(DioceseBranding $color): void
    {
        [$r, $g, $b] = $this->hexToRgb($color->value);
        $this->SetDrawColor($r, $g, $b);
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
