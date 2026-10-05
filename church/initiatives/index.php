<?php
// Initiatives (church, list) - the page itself is shared with Events: includes/events/ (docs/specs/events-initiatives-spec.md)
require_once __DIR__ . '/../../includes/events/context.php';
$eventsCtx = eventsPageContext('church', 'list', 'initiative');
require __DIR__ . '/../../includes/events/page.php';
