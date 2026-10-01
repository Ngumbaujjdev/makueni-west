<?php
// Spending: money in and out (diocese) - the page itself is shared: includes/budget/spending.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'spending');
require __DIR__ . '/../../includes/budget/spending.php';
