<?php
// Budgets list (church) - the page itself is shared: includes/budget/budgets.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'read');
require __DIR__ . '/../../includes/budget/budgets.php';
