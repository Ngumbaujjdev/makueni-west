<?php
/**
 * Public giving page (docs/specs/accounting-spec.md, A10a) - give.php?c=SHR027.
 * No sign-in. A member gives to their church by M-Pesa (the prompt on their
 * phone, through the church's own paybill (A10b) or the diocese paybill) or by card / M-Pesa on Paystack's page.
 * It never shows any figure from the books.
 */
require_once __DIR__ . '/includes/session-manager.php';
$code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $_GET['c'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" <?= appearanceThemeAttributes() ?>>

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Give - Makueni West Diocese</title>
    <meta name="Description" content="Give your tithe and offering to your church" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.min.css" rel="stylesheet" />
</head>

<body class="verify-page">
    <main class="verify-wrap give-wrap">
        <div class="verify-brand">
            <img src="<?= SITE_URL ?>/assets/images/brand-logos/toggle-logo.png" alt="" width="44" height="44">
            <div>
                <strong>Christian Church International</strong>
                <span>Makueni West Diocese - giving</span>
            </div>
        </div>

        <div class="card custom-card verify-card">
            <div class="card-body" id="giveBody">
                <div class="placeholder-glow"><span class="placeholder col-7 mb-2"></span><span class="placeholder col-4"></span></div>
            </div>
        </div>

        <p class="verify-foot">Payments are handled by Safaricom M-Pesa or Paystack. We never see your PIN or card number.</p>
    </main>

    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script>
        (function () {
            "use strict";
            const CODE = <?= json_encode($code) ?>;
            const API = AppConfig.API_BASE_URL;
            const body = document.getElementById("giveBody");
            const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
            const money = (n) => "KES " + Number(n || 0).toLocaleString("en-GB", { maximumFractionDigits: 2 });
            const COLORS = { T: "primary", O: "success", TH: "pink", B: "warning", K: "purple" };
            const ICONS = { T: "ri-hand-heart-line", O: "ri-gift-line", TH: "ri-star-smile-line", B: "ri-building-2-line", K: "ri-group-line" };
            let page = null;

            async function call(method, path, data) {
                try {
                    const r = await fetch(API + path, { method, headers: { Accept: "application/json", "Content-Type": "application/json" }, body: data ? JSON.stringify(data) : undefined });
                    const j = await r.json().catch(() => ({}));
                    const err = j.errors ? Object.values(j.errors).flat()[0] : null;
                    return { ok: r.ok && j.success !== false, data: j.data, message: r.ok ? j.message : err || j.message || "Something went wrong - please try again." };
                } catch (e) {
                    return { ok: false, message: "Can't reach us just now - check your connection and try again." };
                }
            }

            function form() {
                const p = page;
                const methods = [p.methods.mpesa && ["mpesa", "M-Pesa", "The prompt comes to your phone", "ri-smartphone-line", "success"], p.methods.paystack && ["paystack", "Card or other", "Card, or M-Pesa on Paystack's page", "ri-bank-card-line", "primary"]].filter(Boolean);
                if (!methods.length) {
                    body.innerHTML = `<h4 class="fw-bold mb-1">${esc(p.place.name)}</h4><p class="mb-0">Online giving isn't open yet for this church.</p>`;
                    return;
                }
                body.innerHTML = `
                    <div class="d-flex align-items-center gap-3 mb-3"><img src="${esc(p.logo)}" alt="" class="give-logo" onerror="this.remove()"><div><h4 class="fw-bold mb-0">${esc(p.place.name)}</h4>${p.note ? `<div class="give-note">${esc(p.note)}</div>` : ""}</div></div>
                    <div class="give-step"><span>1</span>Giving for</div>
                    <div class="acc-tiles mb-3">${p.purposes.map((u, i) => `<label class="acc-tile" style="--q: var(--${COLORS[u.key] || "primary"}-rgb)"><input type="radio" name="purpose" value="${u.key}"${i === 0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${ICONS[u.key] || "ri-heart-line"}"></i></span><span class="acc-tile-text"><strong>${esc(u.label)}</strong></span></label>`).join("")}</div>
                    <div class="give-step"><span>2</span>How much</div>
                    <div class="input-group input-group-lg mb-2"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control fw-semibold" id="gAmount" placeholder="0" aria-label="Amount"></div>
                    <div class="give-chips mb-3">${[200, 500, 1000, 2000, 5000].map((a) => `<button type="button" class="btn btn-sm btn-outline-primary" data-amt="${a}">${a.toLocaleString("en-GB")}</button>`).join("")}</div>
                    <div class="give-step"><span>3</span>Pay with</div>
                    <div class="acc-tiles mb-3">${methods.map(([k, t, s, i, c], x) => `<label class="acc-tile" style="--q: var(--${c}-rgb)"><input type="radio" name="method" value="${k}"${x === 0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${i}"></i></span><span class="acc-tile-text"><strong>${t}</strong><small>${s}</small></span></label>`).join("")}</div>
                    <div class="row g-2 mb-3"><div class="col-sm-6"><input type="text" class="form-control" id="gName" maxlength="150" placeholder="Your name (optional)" autocomplete="name"></div>
                        <div class="col-sm-6" data-for="mpesa"><input type="tel" class="form-control" id="gPhone" placeholder="M-Pesa number, e.g. 0712 345 678" autocomplete="tel"></div>
                        <div class="col-sm-6" data-for="paystack" hidden><input type="email" class="form-control" id="gEmail" placeholder="Email for your receipt (optional)" autocomplete="email"></div></div>
                    <button type="button" class="btn btn-primary btn-lg w-100" id="gGo"><i class="ri-hand-heart-line me-1"></i><span id="gGoText">Give</span></button>
                    <div id="gMsg" class="mt-3" aria-live="polite"></div>
                    ${p.paybill ? `<details class="give-paybill mt-3"><summary>Or pay with M-Pesa yourself</summary><div class="mt-2">${p.paybill.till ? `Lipa na M-Pesa, Buy Goods, till number <strong>${esc(p.paybill.number)}</strong>.` : `Lipa na M-Pesa, Pay Bill, business number <strong>${esc(p.paybill.number)}</strong>, account number:<div class="give-accounts mt-2">${p.paybill.accounts.filter((a) => a.label && !a.label.startsWith("Any")).map((a) => `<span><small>${esc(a.label)}</small><strong>${esc(a.account)}</strong></span>`).join("")}</div>`}</div></details>` : ""}`;
                const amount = body.querySelector("#gAmount");
                const label = () => {
                    const v = Number(String(amount.value).replace(/[^0-9.]/g, "")) || 0;
                    body.querySelector("#gGoText").textContent = v ? `Give ${money(v)}` : "Give";
                };
                const show = () => {
                    const m = body.querySelector('input[name="method"]:checked').value;
                    body.querySelectorAll("[data-for]").forEach((x) => (x.hidden = x.dataset.for !== m));
                };
                amount.addEventListener("input", label);
                body.querySelectorAll("[data-amt]").forEach((b) => b.addEventListener("click", () => ((amount.value = b.dataset.amt), label())));
                body.querySelectorAll('input[name="method"]').forEach((r) => r.addEventListener("change", show));
                show();
                body.querySelector("#gGo").addEventListener("click", give);
            }

            async function give(e) {
                const btn = e.currentTarget;
                const msg = body.querySelector("#gMsg");
                const method = body.querySelector('input[name="method"]:checked').value;
                btn.disabled = true;
                msg.innerHTML = "";
                const r = await call("POST", `/give/${encodeURIComponent(CODE)}`, {
                    purpose: body.querySelector('input[name="purpose"]:checked').value,
                    amount: Number(String(body.querySelector("#gAmount").value).replace(/[^0-9.]/g, "")) || 0,
                    method,
                    name: body.querySelector("#gName").value.trim() || null,
                    phone: body.querySelector("#gPhone").value.trim() || null,
                    email: body.querySelector("#gEmail").value.trim() || null,
                });
                if (!r.ok) {
                    btn.disabled = false;
                    msg.innerHTML = `<div class="verify-result is-bad"><i class="ri-error-warning-line"></i><div><strong>${esc(r.message)}</strong></div></div>`;
                    return;
                }
                if (r.data.payment_url) {
                    window.location.href = r.data.payment_url;
                    return;
                }
                wait(r.data.reference);
            }

            /** M-Pesa: follow the prompt until it is paid, refused or 2 minutes pass. */
            function wait(ref) {
                let tries = 0;
                body.innerHTML = `<div class="text-center py-3"><div class="give-phone"><i class="ri-smartphone-line"></i></div><h5 class="fw-bold mt-3 mb-1">Check your phone</h5><p class="mb-0">Enter your M-Pesa PIN on the prompt to finish.</p><div class="spinner-border spinner-border-sm text-primary mt-3" role="status"></div></div>`;
                const tick = async () => {
                    const r = await call("GET", `/give/status/${encodeURIComponent(ref)}`);
                    if (r.ok && r.data.status === "paid") return done(r.data);
                    if (r.ok && r.data.status === "failed") return failed(r.data.result);
                    if (++tries < 40) return setTimeout(tick, 3000);
                    body.innerHTML = `<h5 class="fw-bold">Still waiting</h5><p>If you entered your PIN, you'll get an M-Pesa message and our SMS receipt shortly. Reference ${esc(ref)}.</p><button class="btn btn-outline-primary" onclick="location.reload()">Give again</button>`;
                };
                setTimeout(tick, 5000);
            }

            function done(d) {
                body.innerHTML = `<div class="verify-result is-good mb-3"><i class="ri-checkbox-circle-fill"></i><div><strong>Thank you - ${esc(d.place)} has received your ${esc((d.purpose || "gift").toLowerCase())} of ${money(d.amount)}.</strong><span>${d.receipt ? `Receipt ${esc(d.receipt)} · ` : ""}Reference ${esc(d.reference)}</span></div></div><p class="mb-0">God bless you. A receipt is on its way to your phone.</p>`;
            }

            function failed(why) {
                body.innerHTML = `<div class="verify-result is-bad mb-3"><i class="ri-close-circle-line"></i><div><strong>Not paid</strong><span>${esc(why || "The prompt was cancelled or timed out.")}</span></div></div><button class="btn btn-primary" onclick="location.reload()">Try again</button>`;
            }

            (async function start() {
                if (!CODE) {
                    body.innerHTML = '<h4 class="fw-bold mb-1">Give to your church</h4><p class="mb-0">Open the giving link your church shared - it ends in your church\'s code, e.g. give.php?c=SHR027.</p>';
                    return;
                }
                const r = await call("GET", `/give/${encodeURIComponent(CODE)}`);
                if (!r.ok) {
                    body.innerHTML = `<h4 class="fw-bold mb-1">We can't find that church</h4><p class="mb-0">${esc(r.message)}</p>`;
                    return;
                }
                page = r.data;
                document.title = `Give to ${page.place.name}`;
                form();
            })();
        })();
    </script>
</body>

</html>
