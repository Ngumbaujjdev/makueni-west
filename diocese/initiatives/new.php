<?php
// Initiatives (diocese, form) - the page itself is shared with Events: includes/events/ (docs/specs/events-initiatives-spec.md)
require_once __DIR__ . '/../../includes/events/context.php';
$eventsCtx = eventsPageContext('diocese', 'form', 'initiative');
require __DIR__ . '/../../includes/events/page.php';
