<?php

namespace App\Support\Reports\Insights;

/**
 * The numbers a report hands its insight rules. A plain bag so any module
 * (demographics now, attendance or budget later) can fill it with its own
 * keys and write rules against them.
 */
final class ReportFacts
{
    public function __construct(private array $data = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
