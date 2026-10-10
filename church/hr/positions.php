<?php
// Staff (church, positions & pay) - the page is shared: includes/hr/ (docs/specs/hr-spec.md)
require_once __DIR__ . '/../../includes/hr/context.php';
$hrCtx = hrPageContext('church', 'positions');
require __DIR__ . '/../../includes/hr/page.php';
