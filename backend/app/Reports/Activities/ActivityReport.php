<?php

namespace App\Reports\Activities;

use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Support\ActivityAccess;
use App\Support\PlaceAccess;

/**
 * Event and initiative reports (docs/specs/events-initiatives-spec.md): for
 * a church, region or the diocese - your own place, or one below you.
 */
abstract class ActivityReport extends Report
{
    public function module(): string
    {
        return 'events';
    }

    /** Which kind this report is about (the summary reads either; its activity decides). */
    protected function kind(): string
    {
        return 'event';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH, TerritoryType::REGION, TerritoryType::DIOCESE];
    }

    public function icon(): string
    {
        return 'ri-calendar-check-line';
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        if (! ActivityAccess::can($user, $territory, 'read', $this->kind())) {
            return $this->kind() === 'initiative' ? 'Your role cannot see initiatives here.' : 'Your role cannot see events here.';
        }
        if (! PlaceAccess::isOwn($user, $territory) && ! PlaceAccess::isBelow($user, $territory)) {
            return 'You can export your own, or those of places below you.';
        }

        return null;
    }

    protected static function money(float $value): string
    {
        return 'KES '.number_format($value, 2);
    }

    protected static function when(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        return $start->format('Y-m-d') === $end->format('Y-m-d')
            ? $start->format('D j M Y, H:i').' - '.$end->format('H:i')
            : $start->format('D j M Y').' - '.$end->format('D j M Y');
    }
}
