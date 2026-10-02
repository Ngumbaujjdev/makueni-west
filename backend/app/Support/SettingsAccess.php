<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;
use App\Support\Settings\SettingsRegistry;

/**
 * Whose Settings a user is looking at, and what they may do there
 * (docs/specs/settings-spec.md). The place is the one the user is acting
 * for - the same X-Assignment-Id rule as budgets (BudgetAccess) - and only
 * a global admin may pick another place (?territory_id=). Permissions are
 * "{level}.settings.hub.{section}.{read|update}" of the acting role.
 */
final class SettingsAccess
{
    /** The church, region or diocese whose settings these are, or null (refuse). */
    public static function place(?User $user, ?int $territoryId = null): ?Territory
    {
        if (! $user) {
            return null;
        }
        $isLevel = fn (?Territory $t) => $t && in_array($t->territory_type?->value, SettingsRegistry::LEVELS, true);

        if ($user->hasGlobalAccess()) {
            $place = $territoryId ? Territory::find($territoryId) : BudgetAccess::assignment($user)?->territory;
            $place = $isLevel($place) ? $place : Territory::where('territory_type', 'diocese')->orderBy('id')->first();

            return $isLevel($place) ? $place : null;
        }

        $place = BudgetAccess::assignment($user)?->territory;
        if (! $isLevel($place) || ($territoryId && $territoryId !== (int) $place->id)) {
            return null;
        }

        return $place;
    }

    public static function level(Territory $place): string
    {
        return $place->territory_type->value;
    }

    /** read: the section's read OR update permission; update/manage: that exact permission. */
    public static function can(?User $user, Territory $place, string $section, string $action = 'read'): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $level = self::level($place);
        $has = fn (string $a) => BudgetAccess::has($user, SettingsRegistry::permission($level, $section, $a));

        return $action === 'read' ? ($has('read') || $has('update') || $has('manage')) : $has($action);
    }
}
