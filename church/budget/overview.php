<?php
// Budget Overview (church) - the page itself is shared: includes/budget/overview.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'overview');
require __DIR__ . '/../../includes/budget/overview.php';
