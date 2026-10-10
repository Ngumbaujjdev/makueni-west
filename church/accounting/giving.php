<?php
// Accounting (church, online giving) - the page itself is shared: includes/accounting/ (docs/specs/accounting-spec.md)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('church', 'giving');
require __DIR__ . '/../../includes/accounting/page.php';
