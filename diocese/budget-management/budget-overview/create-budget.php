<?php
// Diocese create budget - the page itself is shared: includes/budget/create-budget.php
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';
require_once __DIR__ . '/../../../includes/budget/context.php';

requirePermission('diocese.budgetmanagement.budgetoverview.read');

$budgetCtx = budgetContext('diocese');
require __DIR__ . '/../../../includes/budget/create-budget.php';
