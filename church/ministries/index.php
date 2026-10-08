<?php
// Ministries (church) - the page is shared: includes/ministries/ (docs/specs/people-and-care-spec.md, P4)
require_once __DIR__ . '/../../includes/ministries/context.php';
$minCtx = ministriesPageContext('church', 'index');
require __DIR__ . '/../../includes/ministries/page.php';
