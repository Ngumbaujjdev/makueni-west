<?php
// Budget reports (church) - the page itself is shared: includes/budget/reports.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'export');
require __DIR__ . '/../../includes/budget/reports.php';
