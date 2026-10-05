<?php
// Initiatives (region, list) - the page itself is shared with Events: includes/events/ (docs/specs/events-initiatives-spec.md)
require_once __DIR__ . '/../../includes/events/context.php';
$eventsCtx = eventsPageContext('region', 'list', 'initiative');
require __DIR__ . '/../../includes/events/page.php';
