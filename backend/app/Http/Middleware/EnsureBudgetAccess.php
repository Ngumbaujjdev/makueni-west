<?php

namespace App\Http\Middleware;

use App\Models\Budget;
use App\Support\BudgetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /budgets route (see BudgetAccess and docs/specs/budgets-spec.md):
 * - a budget in the URL must be one the user can see - their own place's,
 *   or (read-only) one below it;
 * - changing a budget needs the prepare permission and must be the user's
 *   own place's - a place below is view only;
 * - creating needs the prepare permission; listing is checked by the
 *   controller, which also allows a place below.
 */
class EnsureBudgetAccess
{
    /** Controller actions that change a budget. Everything else only reads. */
    private const WRITE_ACTIONS = ['form', 'formFor', 'store', 'update', 'destroy', 'start', 'close', 'reopen'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $action = $request->route()?->getActionMethod();
        $writes = in_array($action, self::WRITE_ACTIONS, true);

        $budget = $request->route('budget');
        // A plain id (before route binding) is resolved so it's checked too.
        if ($budget !== null && ! $budget instanceof Budget) {
            $budget = Budget::find((int) $budget);
            if (! $budget) {
                return response()->json(['success' => false, 'status' => 404, 'message' => 'Budget not found.'], 404);
            }
        }

        if ($budget instanceof Budget) {
            if (! BudgetAccess::canSee($user, $budget)) {
                return $this->deny('You can only see your own budgets, or those of places below you.');
            }
            if ($writes && ! BudgetAccess::canWrite($user, $budget)) {
                return $this->deny(BudgetAccess::isOwn($user, $budget->territory_type, (int) $budget->territory_id)
                    ? 'You do not have permission to change budgets.'
                    : 'View only - this budget belongs to '.($budget->territory?->name ?? 'another place').'.');
            }
        } elseif ($writes && ! BudgetAccess::can($user, 'prepare')) {
            return $this->deny('You do not have permission to prepare budgets.');
        }

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
