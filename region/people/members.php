<?php
// Members in our churches (region) - totals only, never names (docs/specs/people-and-care-spec.md, P1)
require_once __DIR__ . '/../../includes/members/context.php';
$membersCtx = membersPageContext('region', 'totals');
require __DIR__ . '/../../includes/members/page.php';
