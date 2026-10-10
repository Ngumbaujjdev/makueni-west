<?php
// Staff (region, one position, grade or allowance) - the page is shared: includes/hr/ (docs/specs/hr-spec.md)
require_once __DIR__ . '/../../includes/hr/context.php';
$hrCtx = hrPageContext('region', 'item');
require __DIR__ . '/../../includes/hr/page.php';
