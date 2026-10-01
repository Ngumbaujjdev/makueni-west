<?php
// One line of a budget (diocese) - the page itself is shared: includes/budget/line.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'read');
require __DIR__ . '/../../includes/budget/line.php';
