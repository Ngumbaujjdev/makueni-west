<?php
// Budget reports (region) - the page itself is shared: includes/budget/reports.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'export');
require __DIR__ . '/../../includes/budget/reports.php';
