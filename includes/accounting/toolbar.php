<?php
// The strip under every Accounting page title: whose books (with the picker for a region or the
// diocese) and the page's write buttons, shown only for our own books (AccountingUI.ownOnly).
// $accButtons: the HTML of the buttons for this page.
?>
<div class="page-toolbar">
    <div class="page-toolbar-sub d-flex flex-wrap align-items-center gap-2" id="accPlaceLine"><span class="fw-semibold"><?= htmlspecialchars($accCtx['place']['name']) ?></span></div>
    <div class="page-toolbar-controls">
        <div class="acc-place-pick" id="accPlacePick" hidden></div>
        <?= $accButtons ?? '' ?>
    </div>
</div>
<?php
// When this role can't do the page's main writing, say which permission it needs and who holds it here - instead of a missing button.
$accNeeds = $accCtx['needs'] ?? null;
$accBelow = isset($_GET['territory_id']) && (int) $_GET['territory_id'] !== (int) $accCtx['place']['id'];
if ($accNeeds && ! $accBelow): ?>
<div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 py-2" id="accNeeds">
    <i class="ri-lock-line fs-5"></i>
    <div class="flex-fill">To do this here you need the <strong><?= htmlspecialchars($accNeeds['label']) ?></strong> permission. <span id="accNeedsWho"></span></div>
    <?php if ($accNeeds['admin']): ?><a class="btn btn-sm btn-outline-primary" href="<?= SITE_URL ?>/diocese/settings/admin/role-management.php"><i class="ri-shield-user-line me-1"></i>Roles &amp; permissions</a><?php endif; ?>
</div>
<script>
    document.addEventListener("DOMContentLoaded", async () => {
        const who = document.getElementById("accNeedsWho");
        if (!who || !window.AccountingAPI || !AccountingAPI.holders) return;
        const r = await AccountingAPI.holders(<?= json_encode($accNeeds['permission']) ?>);
        if (!r.ok) return;
        const d = r.data;
        who.textContent = d.holders.length
            ? `At ${d.place.name} it is held by ${d.holders.map((h) => `${h.name} (${h.role})`).join(", ")}.`
            : `Nobody at ${d.place.name} has it yet${d.roles.length ? ` - it comes with the ${d.roles.join(", ")} role` : ""}. The diocese admin gives it in Roles & permissions.`;
    });
</script>
<?php endif; ?>
