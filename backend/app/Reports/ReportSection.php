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

        return array_map(fn (array $row) => array_map(fn ($v) => self::display($v), $row), $rows);
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
            'totals' => $totals === null ? null : array_map(fn ($v) => self::display($v), $totals),
        ];
    }
}
