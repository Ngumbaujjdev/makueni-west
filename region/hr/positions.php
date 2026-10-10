<?php
// Staff (region, positions & pay) - the page is shared: includes/hr/ (docs/specs/hr-spec.md)
require_once __DIR__ . '/../../includes/hr/context.php';
$hrCtx = hrPageContext('region', 'positions');
require __DIR__ . '/../../includes/hr/page.php';
