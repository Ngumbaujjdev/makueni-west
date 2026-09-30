<?php
// One budget (church) - the page itself is shared: includes/budget/budget.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'read');
require __DIR__ . '/../../includes/budget/budget.php';
