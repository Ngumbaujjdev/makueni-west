<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;

/**
 * Who sees and changes staff (docs/specs/hr-spec.md). Salaries are private:
 * the place's own staff for whoever holds an HR permission there; the staff
 * of the places below for whoever reads below (the region overseeing its
 * churches, the diocese everyone). Writing is in your own place - except a
 * transfer, which the level above both places makes.
 */
final class HrAccess
{
    public const LEVELS = ['church', 'region', 'diocese'];

    public const ABILITIES = [
        'read' => 'hr.staff.read',
        'manage' => 'hr.staff.manage',
        'setup' => 'hr.setup.manage',
        'below' => 'hr.below.read',
    ];

    /** The place a request is for, when the user may see its staff. */
    public static function place(?User $user, ?int $territoryId = null): ?Territory
    {
        $place = PlaceAccess::place($user, $territoryId);

        return $place && self::canRead($user, $place) ? $place : null;
    }

    public static function canRead(?User $user, Territory $place): bool
    {
        if (! $user || ! in_array($place->territory_type->value, self::LEVELS, true)) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        if (PlaceAccess::isOwn($user, $place)) {
            return self::can($user, $place, 'read') || self::can($user, $place, 'manage') || self::can($user, $place, 'setup');
        }

        return self::canReadBelow($user) && PlaceAccess::isBelow($user, $place);
    }

    /** Does the user read the staff of the places below the one they act for? */
    public static function canReadBelow(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $acting = PlaceAccess::acting($user);

        return $acting && $acting->territory_type->value !== 'church' && PlaceAccess::can($user, $acting, self::ABILITIES, 'below');
    }

    /** May the user do this here? Only in their own place. */
    public static function can(?User $user, Territory $place, string $ability): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return PlaceAccess::isOwn($user, $place) && PlaceAccess::can($user, $place, self::ABILITIES, $ability);
    }

    /**
     * May the user move someone from one place to another? Whoever manages
     * staff at a place above both (or at one of them, when the other is below it).
     */
    public static function canTransfer(?User $user, Territory $from, Territory $to): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $acting = PlaceAccess::acting($user);
        if (! $acting || $acting->territory_type->value === 'church' || ! PlaceAccess::can($user, $acting, self::ABILITIES, 'manage')) {
            return false;
        }
        $mine = [(int) $acting->id, ...PlaceAccess::descendantIds($acting)];

        return in_array((int) $from->id, $mine, true) && in_array((int) $to->id, $mine, true);
    }

    /** Everything the user may do here, for the page to offer. */
    public static function abilities(?User $user, Territory $place): array
    {
        $own = (bool) $user && ($user->hasGlobalAccess() || PlaceAccess::isOwn($user, $place));

        return [
            'read' => self::canRead($user, $place),
            'manage' => self::can($user, $place, 'manage'),
            'setup' => self::can($user, $place, 'setup'),
            'below' => self::canReadBelow($user) && $own && $place->territory_type->value !== 'church',
            'transfer' => $own && $place->territory_type->value !== 'church' && self::can($user, $place, 'manage'),
            'own' => $own,
        ];
    }
}
