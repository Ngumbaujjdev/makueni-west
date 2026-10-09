<?php
// Accounting (region, close) - the page itself is shared: includes/accounting/ (docs/specs/accounting-spec.md)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('region', 'close');
require __DIR__ . '/../../includes/accounting/page.php';
