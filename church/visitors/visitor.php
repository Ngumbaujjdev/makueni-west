<?php
// A visitor (church) - the page is shared: includes/visitors/ (docs/specs/people-and-care-spec.md, P2)
require_once __DIR__ . '/../../includes/visitors/context.php';
$visitorsCtx = visitorsPageContext('church', 'visitor');
require __DIR__ . '/../../includes/visitors/page.php';
