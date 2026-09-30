<?php

namespace App\Support;

use App\Enums\AssignmentType;
use App\Enums\TerritoryType;
use App\Models\Budget;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;

/**
 * Who may see and act on which budgets. A church user works on their own
 * church's budgets only and can never approve; approving, rejecting,
 * activating, closing and deductions belong to the diocese approvers.
 * Global admins may do everything.
 *
 * Decided by the role the user is acting in, not every role they hold: a
 * pastor who also sits on the Diocese Council acts as a pastor while in
 * their church role. The API keeps no "current role", so the page sends it
 * as X-Assignment-Id (one of the user's own active assignments); without it,
 * their primary assignment. Permissions are that role's, at that
 * territory's scope - the same list AuthController::getUserPermissions()
 * hands the frontend.
 */
final class BudgetAccess
{
    public const APPROVE_PERMISSION = 'diocesebudgetmanagement.budgetplanning.budgetapprovalworkflow.approve';

    /** The permission prefix church roles hold for their own budgets. */
    public const CHURCH_PERMISSION_PREFIX = 'financialmanagement.budgetmanagement.budgetplanning';

    public const ASSIGNMENT_HEADER = 'X-Assignment-Id';

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

    /** The church a church-tier user is acting for, or null for anyone else (diocese, region, global). */
    public static function churchId(?User $user): ?int
    {
        if (! $user || $user->hasGlobalAccess()) {
            return null;
        }
        $territory = self::assignment($user)?->territory;

        return $territory && $territory->territory_type === TerritoryType::CHURCH ? $territory->id : null;
    }

    public static function canApprove(?User $user): bool
    {
        return self::has($user, self::APPROVE_PERMISSION);
    }

    public static function canSee(?User $user, Budget $budget): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasGlobalAccess() || self::canApprove($user)) {
            return true;
        }
        $churchId = self::churchId($user);
        if ($churchId !== null) {
            return $budget->territory_type === 'church' && (int) $budget->territory_id === $churchId;
        }
        $territory = Territory::withoutGlobalScopes()->find($budget->territory_id);

        return $territory !== null && self::assignment($user)?->getAccessibleTerritories()->contains('id', $territory->id);
    }
}
