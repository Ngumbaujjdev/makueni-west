<?php

namespace App\Support;

use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Support\Collection;

/**
 * Who holds a role at a place - "the Senior Pastor of this church", "the
 * Regional Overseer above it" - or a permission there. Effective, active
 * assignments of users who can sign in. Shared by the approvals engine and
 * anything else that needs the people in a role.
 */
final class PlaceRoles
{
    /** @return Collection<int, User> */
    public static function holders(Territory $place, array $roleNames): Collection
    {
        return UserTerritoryAssignment::with(['user', 'role'])->where('territory_id', $place->id)->effective()->get()
            ->filter(fn ($a) => self::usable($a->user) && in_array($a->role?->name, $roleNames, true))
            ->map(fn ($a) => $a->user)->unique('id')->values();
    }

    /** Holders of "{level}.{suffix}" at the place. @return Collection<int, User> */
    public static function withPermission(Territory $place, string $suffix): Collection
    {
        $level = $place->territory_type->value;

        return UserTerritoryAssignment::with(['user', 'role.permissions'])->where('territory_id', $place->id)->effective()->get()
            ->filter(fn ($a) => self::usable($a->user) && $a->role?->permissions->contains(fn ($p) => $p->name === "{$level}.{$suffix}" && $p->territory_scope === $level))
            ->map(fn ($a) => $a->user)->unique('id')->values();
    }

    /** The nearest place above at this level (region or diocese), or the nearest region-or-diocese when no level is given. */
    public static function above(Territory $place, ?string $level = null): ?Territory
    {
        foreach (PlaceAccess::ancestors($place) as $t) {
            $type = $t->territory_type->value;
            if ($level ? $type === $level : in_array($type, ['region', 'diocese'], true)) {
                return $t;
            }
        }

        return null;
    }

    public static function usable(?User $user): bool
    {
        return $user !== null && ! in_array(strtolower((string) $user->status), ['inactive', 'suspended', 'disabled', 'deleted'], true);
    }
}
