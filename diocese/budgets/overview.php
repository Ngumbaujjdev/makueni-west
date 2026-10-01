<?php
// Budget Overview (diocese) - the page itself is shared: includes/budget/overview.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'overview');
require __DIR__ . '/../../includes/budget/overview.php';
