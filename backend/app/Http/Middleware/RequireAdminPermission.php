<?php

namespace App\Http\Middleware;

use App\Support\BudgetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the access-control APIs (roles, permissions, modules, module
 * groups, users, assignments, territory structure) - they used to need only
 * a login, so any pastor's token could rewrite what every role may do
 * (docs/specs/settings-spec.md, S0).
 *
 * Usage: RequireAdminPermission::class.':rolemanagement,permissions[,self]'
 * - reads (GET/HEAD) pass with the .read of ANY listed System
 *   Administration page (a page may read its neighbours' data, e.g. Role
 *   Management lists permissions);
 * - writes need the .update of the FIRST listed page;
 * - "self" also lets a user read their own record (route {user}/{userId}/{id});
 * - "open-reads" lets any signed-in user read (only writes are guarded);
 * - no pages listed = global admins only.
 *
 * Permissions are those of the role the user is acting in (X-Assignment-Id,
 * via BudgetAccess::has), not every role they hold. Global admins pass.
 */
class RequireAdminPermission
{
    private const PREFIX = 'diocesesettings.systemadministration.';

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $user = $request->user();
        if ($user?->hasGlobalAccess()) {
            return $next($request);
        }

        $reading = $request->isMethodSafe();
        if ($reading && in_array('open-reads', $options, true)) {
            return $next($request);
        }
        if ($reading && in_array('self', $options, true) && $this->isSelf($request, (int) $user?->id)) {
            return $next($request);
        }

        $pages = array_values(array_diff($options, ['self', 'open-reads']));
        $allowed = $pages !== [] && ($reading
            ? collect($pages)->contains(fn (string $page) => BudgetAccess::has($user, self::PREFIX."{$page}.read"))
            : BudgetAccess::has($user, self::PREFIX."{$pages[0]}.update"));

        if (! $allowed) {
            return response()->json([
                'success' => false,
                'message' => "You don't have permission to do this.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /** Whether the route points at the signed-in user's own record. */
    private function isSelf(Request $request, int $userId): bool
    {
        foreach (['user', 'userId', 'id'] as $param) {
            $value = $request->route($param);
            if ($value !== null) {
                return (int) (is_object($value) ? $value->getKey() : $value) === $userId;
            }
        }

        return false;
    }
}
