<?php
// Visitors in our churches (diocese) - totals only, never names (docs/specs/people-and-care-spec.md, P2)
require_once __DIR__ . '/../../includes/visitors/context.php';
$visitorsCtx = visitorsPageContext('diocese', 'totals');
require __DIR__ . '/../../includes/visitors/page.php';
