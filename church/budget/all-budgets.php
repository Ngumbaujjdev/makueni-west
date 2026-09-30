<?php
// Church all budgets - the page itself is shared: includes/budget/all-budgets.php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';
require_once __DIR__ . '/../../includes/budget/context.php';

requirePermission('financialmanagement.budgetmanagement.budgetplanning.read');

$budgetCtx = budgetContext('church');
require __DIR__ . '/../../includes/budget/all-budgets.php';
