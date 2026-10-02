<?php
// The budgets of the places below (region) - the page itself is shared: includes/budget/below.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'below');
require __DIR__ . '/../../includes/budget/below.php';
