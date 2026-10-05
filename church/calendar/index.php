<?php
// Calendar (church) - the page itself is shared: includes/calendar/page.php (docs/specs/calendar-spec.md)
require_once __DIR__ . '/../../includes/calendar/context.php';
$calendarCtx = calendarPageContext('church');
require __DIR__ . '/../../includes/calendar/page.php';
