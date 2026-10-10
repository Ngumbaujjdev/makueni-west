<?php
// Staff (diocese) - the page is shared: includes/hr/ (docs/specs/hr-spec.md)
require_once __DIR__ . '/../../includes/hr/context.php';
$hrCtx = hrPageContext('diocese', 'staff');
require __DIR__ . '/../../includes/hr/page.php';
