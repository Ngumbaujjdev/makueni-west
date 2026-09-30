<?php
// One budget (diocese) - the page itself is shared: includes/budget/budget.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'read');
require __DIR__ . '/../../includes/budget/budget.php';
