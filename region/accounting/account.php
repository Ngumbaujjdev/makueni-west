<?php
// Accounting (region, account) - one account's page: includes/accounting/ (docs/specs/accounting-spec.md, Redesign R2)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('region', 'account');
require __DIR__ . '/../../includes/accounting/page.php';
