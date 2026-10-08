<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;

/**
 * People & care permissions (docs/specs/people-and-care-spec.md): one
 * ability map per module - Members, Visitors, Pastoral care, Ministries and
 * Facilities - for the acting place, through PlaceAccess.
 *
 * Named records (people, care notes, visitors...) are church-private: only
 * the church's own leaders read them (canNamed). The region and the diocese
 * get totals only, never a name (canTotals).
 */
final class PeopleAccess
{
    public const MODULES = ['members', 'visitors', 'pastoral', 'ministries', 'facilities'];

    public const ABILITIES = [
        'members' => [
            'read' => 'members.members.read',
            'manage' => 'members.members.manage',
            'export' => 'members.members.export',
            // The Transfers and Insights pages' own read permissions (the menu shows a page through its permission).
            'transfers' => 'members.transfers.read',
            'insights' => 'members.insights.read',
            'below' => 'members.below.read',
        ],
        'visitors' => [
            'read' => 'visitors.visitors.read',
            'manage' => 'visitors.visitors.manage',
            'below' => 'visitors.below.read',
        ],
        'pastoral' => [
            'read' => 'pastoral.care.read',
            'manage' => 'pastoral.care.manage',
            'confidential' => 'pastoral.care.confidential',
            'below' => 'pastoral.below.read',
        ],
        'ministries' => [
            'read' => 'ministries.ministries.read',
            'manage' => 'ministries.ministries.manage',
            'below' => 'ministries.below.read',
        ],
        'facilities' => [
            'read' => 'facilities.facilities.read',
            'manage' => 'facilities.facilities.manage',
            'book' => 'facilities.facilities.book',
        ],
    ];

    /** Abilities that only make sense at the church (the rest of the map is church-only too, bar "below"). */
    public const BELOW_LEVELS = ['region', 'diocese'];

    /** The permission suffix (after "{level}.") for a module's ability. */
    public static function permission(string $module, string $ability): string
    {
        return self::ABILITIES[$module][$ability];
    }

    /**
     * May the user work with this church's named records? Only at the
     * church they act for, with the module's ability - write implies read.
     * A global admin may look in for support (and it is audited by the
     * caller), but only on a church.
     */
    public static function canNamed(?User $user, Territory $place, string $module, string $ability = 'read'): bool
    {
        if (! $user || PlaceAccess::level($place) !== 'church') {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        if (! PlaceAccess::isOwn($user, $place)) {
            return false;
        }
        $map = self::ABILITIES[$module] ?? [];
        if ($ability === 'read') {
            // Anyone who can change the records can read them.
            foreach (array_keys($map) as $a) {
                if ($a !== 'below' && PlaceAccess::can($user, $place, $map, $a)) {
                    return true;
                }
            }

            return false;
        }

        return PlaceAccess::can($user, $place, $map, $ability);
    }

    /** May the user see the totals (counts, never names) of the churches below this region or diocese? */
    public static function canTotals(?User $user, Territory $place, string $module): bool
    {
        if (! $user || ! in_array(PlaceAccess::level($place), self::BELOW_LEVELS, true) || ! isset(self::ABILITIES[$module]['below'])) {
            return false;
        }

        return PlaceAccess::can($user, $place, self::ABILITIES[$module], 'below');
    }
}
