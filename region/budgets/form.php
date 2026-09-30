<?php
// New / change budget (region) - the page itself is shared: includes/budget/form.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'prepare');
require __DIR__ . '/../../includes/budget/form.php';
