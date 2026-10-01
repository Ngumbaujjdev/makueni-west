<?php
// One line of a budget (church) - the page itself is shared: includes/budget/line.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'read');
require __DIR__ . '/../../includes/budget/line.php';
