<?php

namespace App\Reports\Activities;

use App\Enums\TerritoryType;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Support\EventsAccess;
use App\Support\PlaceAccess;

/**
 * Event reports (docs/specs/events-initiatives-spec.md): for a church,
 * region or the diocese - your own place, or one below you.
 */
abstract class ActivityReport extends Report
{
    public function module(): string
    {
        return 'events';
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
        if (! EventsAccess::can($user, $territory, 'read')) {
            return 'Your role cannot see events here.';
        }
        if (! PlaceAccess::isOwn($user, $territory) && ! PlaceAccess::isBelow($user, $territory)) {
            return 'You can export your own events, or those of places below you.';
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
