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
            const IMG = <?= json_encode(SITE_URL . '/assets/images/payments/') ?>;
            const PAID = { mpesa: "M-Pesa", airtel: "Airtel Money", card: "Card", bank: "Pesalink (bank)" };
            const paidWith = (m) => m === "mpesa" || m === "airtel"
                ? `<span class="acc-logo acc-logo-lg"><img src="${IMG}${m === "airtel" ? "airtel-money" : "mpesa"}.svg" alt="${PAID[m]}"></span>`
                : m === "bank"
                  ? `<span class="acc-logo acc-logo-lg is-icon bg-info"><i class="ri-bank-line"></i></span>`
                  : `<span class="acc-logo acc-logo-lg is-card"><img src="${IMG}visa.svg" alt="Visa"><img src="${IMG}mastercard.svg" alt="Mastercard"></span>`;
            let tries = 0;
            async function tick() {
                let d = null;
                try {
                    const r = await fetch(`${AppConfig.API_BASE_URL}/give/status/${encodeURIComponent(REF)}`, { headers: { Accept: "application/json" } });
                    d = r.ok ? (await r.json()).data : null;
                } catch (e) {}
                if (d && d.status === "paid") {
                    const when = d.paid_at ? new Date(d.paid_at).toLocaleString("en-GB", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";
                    const amt = (n) => "KES " + Number(n || 0).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const lines = (d.lines || []).map((l) => `<tr><td>${esc(l.for)}${l.fund && l.fund !== "General fund" ? `<div class="give-receipt-sub">${esc(l.fund)}</div>` : ""}</td><td class="text-end">${amt(l.amount)}</td></tr>`).join("");
                    body.innerHTML = `<div class="verify-result is-good mb-3 give-noprint"><i class="ri-checkbox-circle-fill"></i><div><strong>Thank you${d.giver ? ` ${esc(d.giver)}` : ""} - ${esc(d.place)} has received your ${esc((d.purpose || "gift").toLowerCase())} of ${amt(d.amount)}.</strong><span>God bless you.</span></div></div>
                        <div class="give-receipt" id="giveReceipt">
                            <div class="give-receipt-head"><img src="<?= SITE_URL ?>/assets/images/brand-logos/toggle-logo.png" alt="" width="40" height="40"><div><strong>${esc(d.place)}</strong><span>Christian Church International · Makueni West Diocese</span></div><div class="give-receipt-no"><span>Official receipt</span><strong>${esc(d.receipt || d.reference)}</strong></div></div>
                            <div class="give-receipt-amount"><span>Amount received</span><strong>${amt(d.amount)}</strong></div>
                            <div class="give-receipt-facts">
                                ${d.giver ? `<div><span>Received from</span><strong>${esc(d.giver)}</strong></div>` : ""}
                                <div><span>For</span><strong>${esc(d.purpose || "Gift")}</strong></div>
                                <div><span>Paid by</span><strong class="d-flex align-items-center gap-2">${paidWith(d.paid_with)}${PAID[d.paid_with] || "Card"}</strong></div>
                                ${d.mpesa_code ? `<div><span>M-Pesa code</span><strong>${esc(d.mpesa_code)}</strong></div>` : ""}
                                <div><span>Date</span><strong>${esc(when)}</strong></div>
                                <div><span>Our reference</span><strong>${esc(d.reference)}</strong></div>
                            </div>
                            <table class="table give-receipt-lines mb-0"><thead><tr><th>Received for</th><th class="text-end">KES</th></tr></thead><tbody>${lines}</tbody><tfoot><tr><th>Total</th><th class="text-end">${amt(d.amount)}</th></tr></tfoot></table>
                            <p class="give-receipt-note">Computer-generated receipt - no signature needed.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3 give-noprint">
                            ${d.receipt_url ? `<a class="btn btn-primary" href="${esc(d.receipt_url)}" target="_blank" rel="noopener"><i class="ri-file-pdf-line me-1"></i>Download receipt (PDF)</a>` : ""}
                            <button type="button" class="btn btn-outline-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
                            <a class="btn btn-outline-primary" href="give.php?c=${encodeURIComponent(d.code || "")}"><i class="ri-hand-heart-line me-1"></i>Give again</a>
                        </div>
                        ${d.phone ? `<p class="small mt-3 mb-0 give-noprint">We've also sent the receipt by SMS to ${esc(d.phone)}.</p>` : ""}`;
                    return;
                }
                if (d && (d.status === "failed" || d.status === "abandoned")) {
                    body.innerHTML = `<div class="verify-result is-bad mb-3"><i class="ri-close-circle-line"></i><div><strong>Not paid</strong><span>${esc(d.result || "The payment didn't go through.")}</span></div></div><p class="small">If you were charged, contact the church treasurer with the reference <strong>${esc(d.reference)}</strong>.</p><a class="btn btn-primary" href="give.php?c=${encodeURIComponent(d.code || "")}">Try again</a>`;
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
