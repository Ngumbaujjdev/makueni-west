<?php
// Communication > Messages > Templates (2026-10-08): the diocese's shared templates and ours - library,
// writer and previews (assets/js/pages/settings/sections/templates.js via assets/js/pages/messages/templates.js).
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub"><?= htmlspecialchars($messagesCtx['place']['name'] ?: 'Templates') ?> · <span id="tplCount">&nbsp;</span></div>
    <div class="page-toolbar-controls">
        <a class="btn btn-primary" href="<?= $messagesCtx['baseUrl'] ?>/new"><i class="ri-send-plane-line me-1"></i>Send a message</a>
    </div>
</div>
<div id="tplHost"></div>
