<?php
// Budgets list (diocese) - the page itself is shared: includes/budget/budgets.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'read');
require __DIR__ . '/../../includes/budget/budgets.php';
