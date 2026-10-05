<?php
// Expenses (region) - the Income & Expenses page, opened on expenses: includes/budget/spending.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'spending');
$spendingDir = 'out';
require __DIR__ . '/../../includes/budget/spending.php';
