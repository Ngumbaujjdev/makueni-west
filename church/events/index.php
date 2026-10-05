<?php
// Events (church, list) - the page itself is shared: includes/events/ (docs/specs/events-initiatives-spec.md)
require_once __DIR__ . '/../../includes/events/context.php';
$eventsCtx = eventsPageContext('church', 'list');
require __DIR__ . '/../../includes/events/page.php';
