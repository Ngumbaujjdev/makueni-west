<?php

namespace App\Support;

use App\Enums\AssignmentType;
use App\Models\Budget;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;

/**
 * Who may see and change which budgets (docs/specs/budgets-spec.md).
 *
 * Every level is independent: a church, a region and the diocese each make
 * and use their own budgets - nobody from another level changes them, and
 * there is no approval. Viewing goes top to bottom, read-only: the diocese
 * can look at every region's and church's budgets, a region at its
 * churches'. Never upwards or sideways. Global admins may do everything.
 *
 * Decided by the role the user is acting in, not every role they hold: the
 * page sends it as X-Assignment-Id (one of the user's own active
 * assignments); without it, their primary assignment. Permissions are that
 * role's, at that territory's scope - the same list
 * AuthController::getUserPermissions() hands the frontend.
 */
final class BudgetAccess
{
    /** The levels that keep budgets. */
    public const LEVELS = ['church', 'region', 'diocese'];

    public const ASSIGNMENT_HEADER = 'X-Assignment-Id';

    /** Ability → permission, prefixed with the acting level: "{level}.{permission}". */
    private const ABILITIES = [
        'read' => 'budgets.budgets.read',
        'prepare' => 'budgets.budgets.prepare',
        'export' => 'budgets.budgets.export',
        'below' => 'budgets.below.read',
        'spending.read' => 'budgets.spending.read',
        'record' => 'budgets.spending.record',
        'settings.read' => 'settings.budgetsettings.read',
        'settings' => 'settings.budgetsettings.update',
        'settings.read' => 'settings.budgetsettings.read',
        'settings.update' => 'settings.budgetsettings.update',
    ];

    /** The assignment the user is acting in (cached for the request). */
    public static function assignment(?User $user): ?UserTerritoryAssignment
    {
        if (! $user) {
            return null;
        }
        $request = request();
        $key = "budget_access.assignment.{$user->id}";
        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $query = fn () => $user->activeAssignments()->with(['role.permissions', 'territory']);
        $requested = $request->header(self::ASSIGNMENT_HEADER);
        $assignment = ($requested && ctype_digit((string) $requested) ? $query()->whereKey((int) $requested)->first() : null)
            ?? $query()->where('assignment_type', AssignmentType::PRIMARY)->first();

        $request->attributes->set($key, $assignment);

        return $assignment;
    }

    /** Whether the acting role holds a permission (global admins hold them all). */
    public static function has(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $assignment = self::assignment($user);
        if (! $assignment?->role || ! $assignment->territory) {
            return false;
        }
        $scope = $assignment->territory->territory_type->value;

        return $assignment->role->permissions->contains(fn ($p) => $p->name === $permission && $p->territory_scope === $scope);
    }

    /**
     * The place the user acts for - ['type' => 'church', 'id' => 13] - or
     * null for a global admin, or a role at a level without budgets.
     *
     * @return array{type: string, id: int}|null
     */
    public static function acting(?User $user): ?array
    {
        if (! $user || $user->hasGlobalAccess()) {
            return null;
        }
        $territory = self::assignment($user)?->territory;
        $type = $territory?->territory_type?->value;

        return $territory && in_array($type, self::LEVELS, true) ? ['type' => $type, 'id' => (int) $territory->id] : null;
    }

    /** The church a church-level user is acting for, or null. */
    public static function churchId(?User $user): ?int
    {
        $place = self::acting($user);

        return $place && $place['type'] === 'church' ? $place['id'] : null;
    }

    /** Whether the user may do something with budgets at their own level. */
    public static function can(?User $user, string $ability): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess()) {
            return true;
        }
        $place = self::acting($user);

        return $place !== null && isset(self::ABILITIES[$ability])
            && self::has($user, "{$place['type']}.".self::ABILITIES[$ability]);
    }

    /** Whether the place is the one the user acts for. */
    public static function isOwn(?User $user, string $type, int $id): bool
    {
        $place = self::acting($user);

        return $place !== null && $place['type'] === $type && $place['id'] === $id;
    }

    /** Whether the place sits somewhere below the one the user acts for. */
    public static function isBelow(?User $user, int $territoryId): bool
    {
        $place = self::acting($user);
        if ($place === null || $place['id'] === $territoryId) {
            return false;
        }
        $territory = Territory::find($territoryId);
        for ($depth = 0; $territory && $territory->parent_territory_id && $depth < 6; $depth++) {
            if ((int) $territory->parent_territory_id === $place['id']) {
                return true;
            }
            $territory = Territory::find($territory->parent_territory_id);
        }

        return false;
    }

    /** Read a place's budgets: your own with read, or one below with "below". */
    public static function canView(?User $user, string $type, int $id): bool
    {
        if ($user?->hasGlobalAccess()) {
            return true;
        }

        return (self::isOwn($user, $type, $id) && self::can($user, 'read'))
            || (self::can($user, 'below') && self::isBelow($user, $id));
    }

    public static function canSee(?User $user, Budget $budget): bool
    {
        return self::canView($user, $budget->territory_type, (int) $budget->territory_id);
    }

    /** Change a budget: only your own place's, with the ability. */
    public static function canWrite(?User $user, Budget $budget, string $ability = 'prepare'): bool
    {
        if ($user?->hasGlobalAccess()) {
            return true;
        }

        return self::isOwn($user, $budget->territory_type, (int) $budget->territory_id) && self::can($user, $ability);
    }

    /**
     * The place a request works on: the acting place, or - for a global
     * admin, or to look at a place below - the territory_id asked for.
     *
     * @return array{type: string, id: int}|null null when the user may not
     *                                           view it (or it isn't a budget level)
     */
    public static function place(?User $user, ?int $territoryId = null): ?array
    {
        $acting = self::acting($user);
        if ($territoryId === null || ($acting && $acting['id'] === $territoryId)) {
            return $acting;
        }
        $type = Territory::find($territoryId)?->territory_type?->value;
        if (! in_array($type, self::LEVELS, true)) {
            return null;
        }

        return self::canView($user, $type, $territoryId) ? ['type' => $type, 'id' => $territoryId] : null;
    }
}
