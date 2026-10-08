<?php
// Prayer (church) - the page is shared: includes/care/ (docs/specs/people-and-care-spec.md, P3)
require_once __DIR__ . '/../../includes/care/context.php';
$careCtx = carePageContext('church', 'prayer');
require __DIR__ . '/../../includes/care/page.php';
