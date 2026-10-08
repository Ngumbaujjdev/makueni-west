<?php
// Pastoral care in our churches (region) - totals only, never a name or a note (docs/specs/people-and-care-spec.md, P3)
require_once __DIR__ . '/../../includes/care/context.php';
$careCtx = carePageContext('region', 'totals');
require __DIR__ . '/../../includes/care/page.php';
