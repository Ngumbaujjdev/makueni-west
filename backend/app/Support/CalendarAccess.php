<?php

namespace App\Support;

use App\Models\CalendarEvent;
use App\Models\Territory;
use App\Models\User;

/**
 * Who sees and changes which calendar events (docs/specs/calendar-spec.md):
 * {level}.calendar.events.read / .manage for the acting place, and the CCI
 * calendar for global admins only.
 */
final class CalendarAccess
{
    public static function permission(string $level, string $action): string
    {
        return "{$level}.calendar.events.{$action}";
    }

    public static function can(?User $user, Territory $place, string $action = 'read'): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $level = $place->territory_type->value;

        return $action === 'read'
            ? BudgetAccess::has($user, self::permission($level, 'read')) || BudgetAccess::has($user, self::permission($level, 'manage'))
            : BudgetAccess::has($user, self::permission($level, 'manage'));
    }

    /** Only your own place's events - and the CCI calendar for global admins. */
    public static function canEdit(?User $user, Territory $place, CalendarEvent $event): bool
    {
        if (! $user) {
            return false;
        }
        if ($event->isCci()) {
            return $user->hasGlobalAccess();
        }

        return (int) $event->territory_id === (int) $place->id && self::can($user, $place, 'manage');
    }
}
