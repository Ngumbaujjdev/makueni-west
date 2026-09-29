<?php

namespace App\Reports;

use App\Enums\TerritoryType;

/**
 * One class per report, modelled on v1-events-backend's app/Reports/Report.
 * A report declares who it's for and builds a ReportData; the PDF, Excel
 * and preview renderers never need to know which report they're drawing.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** The title before the report is built (a queued run shows it in the monitor). */
    public function titleFor(array $params): string
    {
        return $this->title();
    }

    abstract public function build(ReportContext $context): ReportData;

    public function icon(): string
    {
        return 'ri-file-chart-line';
    }

    /** Word used for the module the report belongs to. */
    public function module(): string
    {
        return 'demographics';
    }

    /**
     * What the report is, for the line above its title - "Holy Communion
     * report" becomes "CHURCH HOLY COMMUNION REPORT" (ReportContext::kicker()).
     */
    public function subject(): string
    {
        return $this->title().' report';
    }

    /**
     * Reports only reached from their own page (a metric's report from that
     * metric's page), kept out of the full report list.
     */
    public function lockedOnly(): bool
    {
        return false;
    }

    /** @return TerritoryType[] territory levels this report can run at */
    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    /**
     * Which request params the report reads, for validation and the modal:
     * 'fiscal_year' | 'years' | 'submission'.
     *
     * @return string[]
     */
    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    /** Reports sharing a group are shown together in the export modal. */
    public function group(): ?string
    {
        return null;
    }

    public function supports(TerritoryType $type): bool
    {
        return in_array($type, $this->scopes(), true);
    }

    public function toCatalogue(): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'description' => $this->description(),
            'icon' => $this->icon(),
            'group' => $this->group(),
            'locked_only' => $this->lockedOnly(),
            'inputs' => $this->inputs(),
            'formats' => ['pdf', 'xlsx'],
        ];
    }
}
