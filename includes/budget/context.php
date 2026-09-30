<?php
/**
 * The budget pages (includes/budget/*.php) are shared by the diocese
 * (diocese/budget-management/budget-overview/*) and the church
 * (church/budget/*). A wrapper page checks its permission, then calls
 * budgetContext() and includes the shared body, which reads links and
 * what to offer from $budgetCtx - also handed to the scripts as
 * window.BUDGET_CTX.
 *
 * What the API allows is enforced server-side (EnsureBudgetAccess); these
 * flags only decide which buttons to show.
 */
function budgetContext(string $scope): array
{
    $role = getCurrentRole() ?? [];
    $isChurch = $scope === 'church';
    $path = $isChurch ? '/church/budget' : '/diocese/budget-management/budget-overview';

    return [
        'scope' => $scope,
        'baseUrl' => SITE_URL . $path,
        'homeUrl' => SITE_URL . ($isChurch ? '/church/dashboard' : '/diocese/dashboard'),
        'settingsUrl' => SITE_URL . ($isChurch
            ? '/church/settings/budget-settings/budget-lines.php'
            : '/diocese/settings/budget-settings/budget-line.php'),
        'territory' => [
            'type' => $role['territory_type'] ?? ($isChurch ? 'church' : 'diocese'),
            'id' => (int) ($role['territory_id'] ?? 0),
            'name' => $role['territory']['name'] ?? $role['territory_name'] ?? '',
        ],
        'canCreate' => $isChurch ? hasPermission('financialmanagement.budgetmanagement.budgetplanning.create') || hasGlobalAccess() : true,
        'canEdit' => $isChurch ? hasPermission('financialmanagement.budgetmanagement.budgetplanning.update') || hasGlobalAccess() : true,
        'canSubmit' => $isChurch ? hasPermission('financialmanagement.budgetmanagement.budgetplanning.submit') || hasGlobalAccess() : true,
        'canApprove' => hasGlobalAccess() || hasPermission('diocesebudgetmanagement.budgetplanning.budgetapprovalworkflow.approve'),
    ];
}
