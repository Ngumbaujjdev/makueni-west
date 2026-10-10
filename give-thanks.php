<?php
/**
 * Back from Paystack's page (docs/specs/accounting-spec.md, A10a). Public.
 * Follows the gift (the API checks it with Paystack) and thanks the giver.
 */
require_once __DIR__ . '/includes/session-manager.php';
$ref = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $_GET['ref'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" <?= appearanceThemeAttributes() ?>>

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Thank you - Makueni West Diocese</title>
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.min.css" rel="stylesheet" />
</head>

<body class="verify-page">
    <main class="verify-wrap give-wrap">
        <div class="verify-brand">
            <img src="<?= SITE_URL ?>/assets/images/brand-logos/toggle-logo.png" alt="" width="44" height="44">
            <div><strong>Christian Church International</strong><span>Makueni West Diocese - giving</span></div>
        </div>
        <div class="card custom-card verify-card"><div class="card-body" id="thanksBody"><div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 mb-0">Checking your gift...</p></div></div></div>
    </main>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script>
        (function () {
            "use strict";
            const REF = <?= json_encode($ref) ?>;
            const body = document.getElementById("thanksBody");
            const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
            const money = (n) => "KES " + Number(n || 0).toLocaleString("en-GB", { maximumFractionDigits: 2 });
            let tries = 0;
            async function tick() {
                let d = null;
                try {
                    const r = await fetch(`${AppConfig.API_BASE_URL}/give/status/${encodeURIComponent(REF)}`, { headers: { Accept: "application/json" } });
                    d = r.ok ? (await r.json()).data : null;
                } catch (e) {}
                if (d && d.status === "paid") {
                    body.innerHTML = `<div class="verify-result is-good mb-3"><i class="ri-checkbox-circle-fill"></i><div><strong>Thank you - ${esc(d.place)} has received your ${esc((d.purpose || "gift").toLowerCase())} of ${money(d.amount)}.</strong><span>${d.receipt ? `Receipt ${esc(d.receipt)} · ` : ""}Reference ${esc(d.reference)}</span></div></div><p>God bless you.</p><a class="btn btn-outline-primary" href="give.php?c=${encodeURIComponent(d.code || "")}">Give again</a>`;
                    return;
                }
                if (d && (d.status === "failed" || d.status === "abandoned")) {
                    body.innerHTML = `<div class="verify-result is-bad mb-3"><i class="ri-close-circle-line"></i><div><strong>Not paid</strong><span>${esc(d.result || "The payment didn't go through.")}</span></div></div><a class="btn btn-primary" href="give.php?c=${encodeURIComponent(d.code || "")}">Try again</a>`;
                    return;
                }
                if (!d && !REF) {
                    body.innerHTML = "<p class='mb-0'>We couldn't tell which gift this was.</p>";
                    return;
                }
                if (++tries < 20) return setTimeout(tick, 3000);
                body.innerHTML = `<p class="mb-0">We're still waiting for the payment to be confirmed. If you paid, your receipt comes by SMS shortly. Reference ${esc(REF)}.</p>`;
            }
            tick();
        })();
    </script>
</body>

</html>
