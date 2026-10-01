<?php
// One amount of money in or out (church) - the page itself is shared: includes/budget/entry.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'spending');
require __DIR__ . '/../../includes/budget/entry.php';
