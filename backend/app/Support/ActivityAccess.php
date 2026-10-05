<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Territory;
use App\Models\User;

/**
 * Events and initiatives permissions (docs/specs/events-initiatives-spec.md),
 * one ability map per kind - {level}.events.* for events and
 * {level}.initiatives.* for initiatives - for the acting place, through
 * PlaceAccess.
 */
final class ActivityAccess
{
    public const ABILITIES = [
        'event' => [
            'read' => 'events.events.read',
            'manage' => 'events.events.manage',
            'register' => 'events.events.register',
            'below' => 'events.below.read',
        ],
        'initiative' => [
            'read' => 'initiatives.initiatives.read',
            'manage' => 'initiatives.initiatives.manage',
            'register' => 'initiatives.initiatives.register',
            'below' => 'initiatives.below.read',
        ],
    ];

    /** The permission suffix (after "{level}.") for a kind's ability. */
    public static function permission(string $kind, string $ability): string
    {
        return (self::ABILITIES[$kind] ?? self::ABILITIES['event'])[$ability];
    }

    public static function can(?User $user, Territory $place, string $ability, string $kind = 'event'): bool
    {
        $map = self::ABILITIES[$kind] ?? self::ABILITIES['event'];
        // Anyone who can manage or register can also read.
        if ($ability === 'read') {
            return PlaceAccess::can($user, $place, $map, 'read')
                || PlaceAccess::can($user, $place, $map, 'manage')
                || PlaceAccess::can($user, $place, $map, 'register');
        }

        return PlaceAccess::can($user, $place, $map, $ability);
    }

    /** Can the user read either kind here? (Routes that don't yet know the kind.) */
    public static function canReadAny(?User $user, Territory $place): bool
    {
        return self::can($user, $place, 'read', 'event') || self::can($user, $place, 'read', 'initiative');
    }

    /** May the user change this activity? Only its own place's managers, for its kind. */
    public static function canManage(?User $user, Territory $place, Activity $activity): bool
    {
        return (int) $activity->territory_id === (int) $place->id && self::can($user, $place, 'manage', $activity->kind);
    }
}
