<?php
// Accounting (region, reports) - the books as diocese PDFs: includes/accounting/ (docs/specs/accounting-spec.md, Redesign R4)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('region', 'reports');
require __DIR__ . '/../../includes/accounting/page.php';
