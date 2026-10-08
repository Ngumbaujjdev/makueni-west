<?php
// Ministries in our churches (diocese) - totals only, never a name (docs/specs/people-and-care-spec.md, P4)
require_once __DIR__ . '/../../includes/ministries/context.php';
$minCtx = ministriesPageContext('diocese', 'totals');
require __DIR__ . '/../../includes/ministries/page.php';
