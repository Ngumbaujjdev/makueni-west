<?php
// Messages (diocese, message) - the page itself is shared: includes/messages/ (docs/specs/messages-spec.md)
require_once __DIR__ . '/../../includes/messages/context.php';
$messagesCtx = messagesPageContext('diocese', 'message');
require __DIR__ . '/../../includes/messages/page.php';
