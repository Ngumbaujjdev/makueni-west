<?php
// Facilities - index (church). The page is shared: includes/facilities/ (docs/specs/people-and-care-spec.md, P5)
require_once __DIR__ . '/../../includes/facilities/context.php';
$facCtx = facilitiesPageContext('index');
require __DIR__ . '/../../includes/facilities/page.php';
