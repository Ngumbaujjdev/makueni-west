<?php
// New / change budget (church) - the page itself is shared: includes/budget/form.php
require_once __DIR__ . '/../../includes/budget/context.php';
$budgetCtx = budgetPageContext('church', 'prepare');
require __DIR__ . '/../../includes/budget/form.php';
