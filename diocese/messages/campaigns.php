<?php
// Messages (diocese, campaigns) - the page itself is shared: includes/messages/ (docs/specs/messages-spec.md)
require_once __DIR__ . '/../../includes/messages/context.php';
$messagesCtx = messagesPageContext('diocese', 'campaigns');
require __DIR__ . '/../../includes/messages/page.php';
