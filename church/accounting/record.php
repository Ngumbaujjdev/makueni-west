<?php
// Accounting (church, record) - one record's page with its journey: includes/accounting/ (docs/specs/accounting-spec.md, Redesign R1)
require_once __DIR__ . '/../../includes/accounting/context.php';
$accCtx = accountingPageContext('church', 'record');
require __DIR__ . '/../../includes/accounting/page.php';
