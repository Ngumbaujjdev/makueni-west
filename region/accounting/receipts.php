<?php
// Accounting (region, receipts) - the page itself is shared: includes/accounting/ (docs/specs/accounting-spec.md)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('region', 'receipts');
require __DIR__ . '/../../includes/accounting/page.php';
