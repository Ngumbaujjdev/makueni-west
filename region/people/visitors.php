<?php
// Visitors in our churches (region) - totals only, never names (docs/specs/people-and-care-spec.md, P2)
require_once __DIR__ . '/../../includes/visitors/context.php';
$visitorsCtx = visitorsPageContext('region', 'totals');
require __DIR__ . '/../../includes/visitors/page.php';
