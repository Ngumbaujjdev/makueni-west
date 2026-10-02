<?php
// Contributions (region) - the page itself is shared: includes/budget/contributions.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'contributions');
require __DIR__ . '/../../includes/budget/contributions.php';
