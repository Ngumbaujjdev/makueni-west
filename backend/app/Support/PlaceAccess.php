<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;

/**
 * Who acts for which place, for the Church life modules
 * (docs/specs/events-initiatives-spec.md): the same acting-role rules as
 * Budgets - the X-Assignment-Id header picks the role, you write to your
 * own place and view the places below, never upwards or sideways. Each
 * module keeps its ability map in its own XxxAccess (e.g. EventsAccess).
 *
 * The acting-role plumbing is BudgetAccess's (assignment, has, isBelow);
 * BudgetAccess itself is left as it is.
 */
final class PlaceAccess
{
    public const LEVELS = ['church', 'region', 'diocese'];

    /**
     * The place a request is for. A global admin may name any church,
     * region or diocese (?territory_id=), else their own role's place or the
     * diocese; anyone else gets their acting place, or a place below it
     * when they name one (to view). Null when it isn't theirs.
     */
    public static function place(?User $user, ?int $territoryId = null): ?Territory
    {
        if (! $user) {
            return null;
        }
        $isLevel = fn (?Territory $t) => $t && in_array($t->territory_type?->value, self::LEVELS, true);

        if ($user->hasGlobalAccess()) {
            $place = $territoryId ? Territory::find($territoryId) : BudgetAccess::assignment($user)?->territory;
            $place = $isLevel($place) ? $place : Territory::where('territory_type', 'diocese')->orderBy('id')->first();

            return $isLevel($place) ? $place : null;
        }

        $acting = BudgetAccess::assignment($user)?->territory;
        if (! $isLevel($acting)) {
            return null;
        }
        if (! $territoryId || $territoryId === (int) $acting->id) {
            return $acting;
        }
        $named = Territory::find($territoryId);

        return $isLevel($named) && BudgetAccess::isBelow($user, $territoryId) ? $named : null;
    }

    /** The place the user acts for (never one below). */
    public static function acting(?User $user): ?Territory
    {
        return self::place($user);
    }

    public static function level(Territory $place): string
    {
        return $place->territory_type->value;
    }

    /** The full permission name, scoped to the acting role's level. */
    public static function has(?User $user, string $permission): bool
    {
        return BudgetAccess::has($user, $permission);
    }

    /** "{level}.{suffix}" through a module's ability map, at the place's level. */
    public static function can(?User $user, Territory $place, array $abilities, string $ability): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $suffix = $abilities[$ability] ?? null;

        return $suffix !== null && self::has($user, self::level($place).'.'.$suffix);
    }

    /** Is this the place the user acts for? (Global admins act everywhere.) */
    public static function isOwn(?User $user, Territory $place): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }

        return (int) BudgetAccess::assignment($user)?->territory_id === (int) $place->id;
    }

    /** Is the place under the one the user acts for? */
    public static function isBelow(?User $user, Territory $place): bool
    {
        return (bool) $user && ($user->hasGlobalAccess() || BudgetAccess::isBelow($user, (int) $place->id));
    }

    /** The places above, nearest first (never the place itself). */
    public static function ancestors(Territory $place): array
    {
        $out = [];
        for ($t = $place->parent_territory_id ? Territory::find($place->parent_territory_id) : null, $depth = 0; $t && $depth < 6; $depth++) {
            $out[] = $t;
            $t = $t->parent_territory_id ? Territory::find($t->parent_territory_id) : null;
        }

        return $out;
    }

    /** Every place below, at any depth (ids). */
    public static function descendantIds(Territory $place): array
    {
        $ids = [];
        for ($frontier = [(int) $place->id], $depth = 0; $frontier && $depth < 5; $depth++) {
            $frontier = Territory::whereIn('parent_territory_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
