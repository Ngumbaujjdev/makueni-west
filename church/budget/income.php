<?php
// Income (church) - the Income & Expenses page, opened on income: includes/budget/spending.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'income');
$spendingDir = 'in';
require __DIR__ . '/../../includes/budget/spending.php';
