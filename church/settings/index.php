<?php
// Settings (church) - the page itself is shared: includes/settings/hub.php
require_once __DIR__ . '/../../includes/settings/context.php';
$settingsCtx = settingsPageContext('church');
require __DIR__ . '/../../includes/settings/hub.php';
