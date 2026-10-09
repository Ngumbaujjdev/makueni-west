<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;

/**
 * Who may do what in Accounting (docs/specs/accounting-spec.md) - one set
 * of abilities for every level, checked as "{level}.{permission}" for the
 * role the user is acting in (PlaceAccess). A place's own people write into
 * its books as their role allows; the region and diocese read the places
 * below them with "below", never write there. Never upwards or sideways.
 */
final class AccountingAccess
{
    public const ABILITIES = [
        'read' => 'accounting.books.read',
        'receipt' => 'accounting.receipts.create',
        'prepare' => 'accounting.payments.prepare',
        'authorise' => 'accounting.payments.authorise',
        'pay' => 'accounting.payments.pay',
        'journal' => 'accounting.journals.post',
        'accounts' => 'accounting.accounts.manage',
        'chart' => 'accounting.chart.manage',
        'below' => 'accounting.below.read',
    ];

    /** Abilities that let someone open the books (they can see what they write). */
    private const READS = ['read', 'receipt', 'prepare', 'authorise', 'pay', 'journal', 'accounts', 'chart'];

    /**
     * The place a request is for: the acting place, or one below it that the
     * user may read. Null when it isn't theirs to see.
     */
    public static function place(?User $user, ?int $territoryId = null): ?Territory
    {
        $place = PlaceAccess::place($user, $territoryId);
        if (! $place) {
            return null;
        }

        return self::canRead($user, $place) ? $place : null;
    }

    /** May the user read this place's books? Their own with any Accounting ability; one below with "below". */
    public static function canRead(?User $user, Territory $place): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        if (PlaceAccess::isOwn($user, $place)) {
            foreach (self::READS as $ability) {
                if (self::canAt($user, $place, $ability)) {
                    return true;
                }
            }

            return false;
        }
        $acting = PlaceAccess::acting($user);

        return $acting && PlaceAccess::isBelow($user, $place) && self::canAt($user, $acting, 'below');
    }

    /** May the user do this in the place's books? Only in their own place. */
    public static function can(?User $user, Territory $place, string $ability): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return PlaceAccess::isOwn($user, $place) && self::canAt($user, $place, $ability);
    }

    /** Everything the user may do here, for the page to offer. */
    public static function abilities(?User $user, Territory $place): array
    {
        $out = [];
        foreach (array_keys(self::ABILITIES) as $ability) {
            $out[$ability] = $ability === 'below' ? self::belowAnywhere($user) : self::can($user, $place, $ability);
        }
        $out['read'] = self::canRead($user, $place);
        $out['own'] = (bool) $user && ($user->hasGlobalAccess() || PlaceAccess::isOwn($user, $place));

        return $out;
    }

    private static function belowAnywhere(?User $user): bool
    {
        $acting = PlaceAccess::acting($user);

        return (bool) $user && ($user->hasGlobalAccess() || ($acting && self::canAt($user, $acting, 'below')));
    }

    private static function canAt(User $user, Territory $place, string $ability): bool
    {
        return PlaceAccess::can($user, $place, self::ABILITIES, $ability);
    }
}
