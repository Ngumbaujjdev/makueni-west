<?php
// Budget Settings (region) - the page itself is shared: includes/budget/settings.php
require_once __DIR__ . '/../../../includes/budget/context.php';
$budgetCtx = budgetPageContext('region', 'settings');
require __DIR__ . '/../../../includes/budget/settings.php';
