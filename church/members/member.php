<?php
// Members (church) - the page is shared: includes/members/ (docs/specs/people-and-care-spec.md, P1)
require_once __DIR__ . '/../../includes/members/context.php';
$membersCtx = membersPageContext('church', 'member');
require __DIR__ . '/../../includes/members/page.php';
