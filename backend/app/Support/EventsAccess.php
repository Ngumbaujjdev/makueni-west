<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Territory;
use App\Models\User;

/**
 * Events and initiatives permissions (docs/specs/events-initiatives-spec.md):
 * {level}.events.events.read / manage / register and {level}.events.below.read
 * for the acting place, through PlaceAccess.
 */
final class EventsAccess
{
    public const ABILITIES = [
        'read' => 'events.events.read',
        'manage' => 'events.events.manage',
        'register' => 'events.events.register',
        'below' => 'events.below.read',
    ];

    public static function can(?User $user, Territory $place, string $ability): bool
    {
        // Anyone who can manage or register can also read.
        if ($ability === 'read') {
            return PlaceAccess::can($user, $place, self::ABILITIES, 'read')
                || PlaceAccess::can($user, $place, self::ABILITIES, 'manage')
                || PlaceAccess::can($user, $place, self::ABILITIES, 'register');
        }

        return PlaceAccess::can($user, $place, self::ABILITIES, $ability);
    }

    /** May the user change this activity? Only its own place's managers. */
    public static function canManage(?User $user, Territory $place, Activity $activity): bool
    {
        return (int) $activity->territory_id === (int) $place->id && self::can($user, $place, 'manage');
    }
}
