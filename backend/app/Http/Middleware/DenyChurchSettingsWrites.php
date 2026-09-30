<?php

namespace App\Http\Middleware;

use App\Support\BudgetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The diocese's shared budget settings (types, categories, periods,
 * deductions) are read-only for church users: they may look, not change.
 * A church's own budget lines are handled by BudgetLineController.
 */
class DenyChurchSettingsWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && BudgetAccess::churchId($request->user()) !== null) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'Budget settings are set by the diocese.',
            ], 403);
        }

        return $next($request);
    }
}
