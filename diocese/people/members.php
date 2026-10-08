<?php
// Members in our churches (diocese) - totals only, never names (docs/specs/people-and-care-spec.md, P1)
require_once __DIR__ . '/../../includes/members/context.php';
$membersCtx = membersPageContext('diocese', 'totals');
require __DIR__ . '/../../includes/members/page.php';
