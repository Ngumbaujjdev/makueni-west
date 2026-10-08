<?php
// Communication > Messages > Message log (2026-10-08): every email and SMS that went out, one row each, with
// the real phone / mail-app preview (assets/js/pages/settings/sections/messages.js via assets/js/pages/messages/log.js).
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub"><?= htmlspecialchars($messagesCtx['place']['name'] ?: 'Message log') ?> · every email and SMS that went out</div>
    <div class="page-toolbar-controls">
        <a class="btn btn-outline-primary" href="<?= $messagesCtx['baseUrl'] ?>/campaigns.php"><i class="ri-broadcast-line me-1"></i>Campaigns</a>
    </div>
</div>
<div id="logHost"></div>
