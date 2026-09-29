<?php

namespace App\Support\Reports\Insights;

/**
 * One finding in a report: what we noticed (title + detail, with the numbers
 * behind it) and, where there's something to do about it, a recommendation.
 */
final class Insight
{
    public const GOOD = 'good';

    public const WATCH = 'watch';

    public const CONCERN = 'concern';

    public function __construct(
        public string $tone,
        public string $title,
        public string $detail,
        public ?string $recommendation = null,
    ) {}

    public function toArray(): array
    {
        return ['tone' => $this->tone, 'title' => $this->title, 'detail' => $this->detail, 'recommendation' => $this->recommendation];
    }
}
