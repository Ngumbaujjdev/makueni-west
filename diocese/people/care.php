<?php
// Pastoral care in our churches (diocese) - totals only, never a name or a note (docs/specs/people-and-care-spec.md, P3)
require_once __DIR__ . '/../../includes/care/context.php';
$careCtx = carePageContext('diocese', 'totals');
require __DIR__ . '/../../includes/care/page.php';
