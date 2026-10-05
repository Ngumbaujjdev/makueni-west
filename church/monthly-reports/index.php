<?php
// Monthly reports (church, index) - the page itself is shared: includes/monthly-reports/ (docs/specs/monthly-reports-spec.md)
require_once __DIR__ . '/../../includes/monthly-reports/context.php';
$reportsCtx = reportsPageContext('church', 'index');
require __DIR__ . '/../../includes/monthly-reports/page.php';
