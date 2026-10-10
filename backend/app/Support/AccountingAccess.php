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
        'reconcile' => 'accounting.reconcile.do',
        'petty' => 'accounting.pettycash.spend',
        'close' => 'accounting.periods.close',
        'reopen' => 'accounting.periods.reopen',
        'collect' => 'accounting.collections.record',
        'confirm' => 'accounting.collections.confirm',
        'request' => 'accounting.requisitions.create',
        'rules' => 'accounting.approvalrules.manage',
        'procure' => 'accounting.procurement.manage',
        'payroll' => 'accounting.payroll.manage',
        'payrollread' => 'accounting.payroll.read',
        'paybill' => 'accounting.paybill.manage',
    ];

    /** Abilities that let someone open the books (they can see what they write). */
    private const READS = ['read', 'receipt', 'prepare', 'authorise', 'pay', 'journal', 'accounts', 'chart', 'reconcile', 'petty', 'close', 'reopen'];

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

    /**
     * May the user see this place's collections? Whoever reads the books, and
     * the ushers and elders who count or confirm them (without the rest of the books).
     */
    public static function canSeeCollections(?User $user, Territory $place): bool
    {
        return self::canRead($user, $place) || self::can($user, $place, 'collect') || self::can($user, $place, 'confirm');
    }

    /** Requisitions: those who read the books see all of them; anyone who may ask sees their own. */
    public static function canSeeRequisitions(?User $user, Territory $place): bool
    {
        return self::canRead($user, $place) || self::can($user, $place, 'request');
    }

    /** Payroll - salaries are private: whoever runs it, and those given payroll.read (not the general book-readers). */
    public static function canSeePayroll(?User $user, Territory $place): bool
    {
        if ($user?->hasGlobalAccess()) {
            return true;
        }
        if (PlaceAccess::isOwn($user, $place)) {
            return self::can($user, $place, 'payroll') || self::can($user, $place, 'payrollread');
        }
        $acting = PlaceAccess::acting($user);

        return $acting && PlaceAccess::isBelow($user, $place) && self::canAt($user, $acting, 'below') && self::canAt($user, $acting, 'payrollread');
    }

    /** Procurement: whoever reads the books sees it; whoever buys works in it. */
    public static function canSeeProcurement(?User $user, Territory $place): bool
    {
        return self::canRead($user, $place) || self::can($user, $place, 'procure');
    }

    /** May the user change the approval rules? The diocese's finance officer (and global admins). */
    public static function canManageRules(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $acting = PlaceAccess::acting($user);

        return $acting && $acting->territory_type->value === 'diocese' && self::canAt($user, $acting, 'rules');
    }

    /**
     * May the user reopen a closed month of this place? The one write into a
     * place below: the level above reopens a church's or region's month, the
     * diocese its own (there is nobody above it).
     */
    public static function canReopen(?User $user, Territory $place): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $acting = PlaceAccess::acting($user);
        if (! $acting || ! self::canAt($user, $acting, 'reopen')) {
            return false;
        }

        return PlaceAccess::isBelow($user, $place)
            || (PlaceAccess::isOwn($user, $place) && $place->territory_type->value === 'diocese');
    }

    /** Everything the user may do here, for the page to offer. */
    public static function abilities(?User $user, Territory $place): array
    {
        $out = [];
        foreach (array_keys(self::ABILITIES) as $ability) {
            $out[$ability] = $ability === 'below' ? self::belowAnywhere($user) : self::can($user, $place, $ability);
        }
        $out['read'] = self::canRead($user, $place);
        $out['reopen'] = self::canReopen($user, $place);
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
