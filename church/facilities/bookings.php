<?php
// Facilities - bookings (church). The page is shared: includes/facilities/ (docs/specs/people-and-care-spec.md, P5)
require_once __DIR__ . '/../../includes/facilities/context.php';
$facCtx = facilitiesPageContext('bookings');
require __DIR__ . '/../../includes/facilities/page.php';
