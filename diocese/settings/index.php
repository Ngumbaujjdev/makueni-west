<?php
// Settings (diocese) - the page itself is shared: includes/settings/hub.php
require_once __DIR__ . '/../../includes/settings/context.php';
$settingsCtx = settingsPageContext('diocese');
require __DIR__ . '/../../includes/settings/hub.php';
