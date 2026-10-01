<?php

namespace App\Reports;

/**
 * A table in a report. Rows hold raw values (ints stay ints, a missing
 * figure is null) so Excel keeps numbers as numbers; the PDF and preview
 * format them with display().
 */
final class ReportSection
{
    /**
     * @param  ReportColumn[]  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        public string $heading,
        public array $columns,
        public array $rows,
        public ?string $note = null,
        public string $totalsLabel = 'Total',
    ) {}

    public function hasTotals(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->total !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw totals row, or null when no column asked for a total. The
     * first column carries the label; columns without a total stay blank.
     *
     * @return array<int, mixed>|null
     */
    public function totals(): ?array
    {
        if (! $this->hasTotals() || $this->rows === []) {
            return null;
        }

        $out = [];
        foreach ($this->columns as $i => $column) {
            $values = array_values(array_filter(array_column($this->rows, $i), fn ($v) => is_int($v) || is_float($v)));
            $out[$i] = match ($column->total) {
                'sum' => array_sum($values),
                'latest' => $values === [] ? null : end($values),
                'avg' => $values === [] ? null : round(array_sum($values) / count($values), 1),
                default => null,
            };
        }
        $out[0] ??= $this->totalsLabel;

        return $out;
    }

    /** A value as its column shows it: money always with 2 decimals. */
    public static function displayCell(mixed $value, ?ReportColumn $column): string
    {
        if ($column?->format === 'money' && (is_int($value) || is_float($value))) {
            return number_format((float) $value, 2);
        }

        return self::display($value);
    }

    public static function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_int($value)) {
            return number_format($value);
        }
        if (is_float($value)) {
            return floor($value) == $value ? number_format($value) : number_format($value, 1);
        }

        return (string) $value;
    }

    /** Rows formatted for display (PDF, preview). */
    public function displayRows(?int $limit = null): array
    {
        $rows = $limit === null ? $this->rows : array_slice($this->rows, 0, $limit);

        return array_map(fn (array $row) => $this->displayRow($row), $rows);
    }

    /** @param array<int, mixed> $row */
    private function displayRow(array $row): array
    {
        $out = [];
        foreach ($row as $i => $v) {
            $out[$i] = self::displayCell($v, $this->columns[$i] ?? null);
        }

        return $out;
    }

    /** The totals row formatted for display, or null. */
    public function displayTotals(): ?array
    {
        $totals = $this->totals();

        return $totals === null ? null : $this->displayRow($totals);
    }

    public function toPreview(int $limit = 5): array
    {
        $totals = $this->totals();

        return [
            'heading' => $this->heading,
            'note' => $this->note,
            'columns' => array_map(fn (ReportColumn $c) => $c->toArray(), $this->columns),
            'row_count' => count($this->rows),
            'rows' => $this->displayRows($limit),
            'has_totals' => $totals !== null,
            'totals' => $totals === null ? null : $this->displayTotals(),
        ];
    }
}
