<?php
// One amount of money in or out (diocese) - the page itself is shared: includes/budget/entry.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'spending');
require __DIR__ . '/../../includes/budget/entry.php';
