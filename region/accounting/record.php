<?php
// Accounting (region, record) - one record's page with its journey: includes/accounting/ (docs/specs/accounting-spec.md, Redesign R1)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('region', 'record');
require __DIR__ . '/../../includes/accounting/page.php';
