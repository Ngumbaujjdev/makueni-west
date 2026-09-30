<?php

namespace App\Http\Middleware;

use App\Models\Budget;
use App\Models\BudgetLineItem;
use App\Support\BudgetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /budgets route (see BudgetAccess for the rules):
 * - a church user is pinned to their own church: listing, exporting and
 *   creating always use it, whatever territory the request sends, and each
 *   action needs the matching church budget-planning permission;
 * - a budget (or line item) in the URL must be one the user can see;
 * - the diocese-side actions need the diocese approve permission.
 */
class EnsureBudgetAccess
{
    /** Controller actions only a diocese approver may take. */
    private const APPROVER_ACTIONS = ['approve', 'reject', 'activate', 'close', 'applyDeduction', 'reverseDeduction', 'recalculateDeductions'];

    /** What a church user needs for each action (anything not listed needs read). */
    private const CHURCH_ACTIONS = [
        'store' => 'create',
        'clone' => 'create',
        'update' => 'update',
        'destroy' => 'update',
        'addLineItem' => 'update',
        'updateLineItem' => 'update',
        'updateLooseLineItem' => 'update',
        'deleteLineItem' => 'update',
        'submit' => 'submit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $action = $request->route()?->getActionMethod();

        if (in_array($action, self::APPROVER_ACTIONS, true) && ! BudgetAccess::canApprove($user)) {
            return $this->deny('Only the diocese can approve, reject, activate or close a budget.');
        }

        $churchId = BudgetAccess::churchId($user);
        if ($churchId !== null) {
            $needed = BudgetAccess::CHURCH_PERMISSION_PREFIX.'.'.(self::CHURCH_ACTIONS[$action] ?? 'read');
            if (! BudgetAccess::has($user, $needed)) {
                return $this->deny('You do not have permission to do that with your church\'s budgets.');
            }
            $request->merge(['territory_type' => 'church', 'territory_id' => $churchId]);
        }

        $budget = $request->route('budget');
        $lineItem = $request->route('lineItem');
        if (! $budget instanceof Budget && $lineItem instanceof BudgetLineItem) {
            $budget = $lineItem->budget;
        }
        if ($budget instanceof Budget && ! BudgetAccess::canSee($user, $budget)) {
            return $this->deny('You can only work on your own church\'s budgets.');
        }

        // Once submitted, a church's budget is the diocese's to review: the
        // church can change it again only if it comes back rejected.
        if ($churchId !== null && $budget instanceof Budget && (self::CHURCH_ACTIONS[$action] ?? null) === 'update'
            && ! in_array($budget->status, ['draft', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'This budget is with the diocese for review. It can be changed again if it is sent back.',
            ], 400);
        }

        return $next($request);
    }

    private function deny(string $message): Response
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
