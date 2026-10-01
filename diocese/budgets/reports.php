<?php
// Budget reports (diocese) - the page itself is shared: includes/budget/reports.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'export');
require __DIR__ . '/../../includes/budget/reports.php';
