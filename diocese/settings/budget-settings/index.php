<?php
// Budget Settings (diocese) - the page itself is shared: includes/budget/settings.php
require_once __DIR__ . '/../../../includes/budget/context.php';
$budgetCtx = budgetPageContext('diocese', 'settings');
require __DIR__ . '/../../../includes/budget/settings.php';
