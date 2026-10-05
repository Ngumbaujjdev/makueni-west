<?php
// Messages (region, message) - the page itself is shared: includes/messages/ (docs/specs/messages-spec.md)
require_once __DIR__ . '/../../includes/messages/context.php';
$messagesCtx = messagesPageContext('region', 'message');
require __DIR__ . '/../../includes/messages/page.php';
