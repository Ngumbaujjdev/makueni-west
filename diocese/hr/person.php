<?php
// Staff (diocese, one person) - the page is shared: includes/hr/ (docs/specs/hr-spec.md)
require_once __DIR__ . '/../../includes/hr/context.php';
$hrCtx = hrPageContext('diocese', 'person');
require __DIR__ . '/../../includes/hr/page.php';
