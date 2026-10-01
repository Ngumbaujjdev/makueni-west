<?php
// Spending: money in and out (region) - the page itself is shared: includes/budget/spending.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'spending');
require __DIR__ . '/../../includes/budget/spending.php';
