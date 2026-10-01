<?php
// One line of a budget (region) - the page itself is shared: includes/budget/line.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'read');
require __DIR__ . '/../../includes/budget/line.php';
