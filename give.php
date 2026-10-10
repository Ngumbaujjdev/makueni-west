<?php
/**
 * Public giving page (docs/specs/accounting-spec.md, A10a) - give.php?c=SHR027.
 * No sign-in; the giver's name and phone are required (and an email for card).
 * A member gives to their church by M-Pesa (the prompt on their
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
                    if (r.status === 429) return { ok: false, data: null, errors: null, message: "Too many tries just now - please wait a minute and try again." };
                    return { ok: r.ok && j.success !== false, data: j.data, errors: j.errors || null, message: r.ok ? j.message : err || j.message || "Something went wrong - please try again." };
                } catch (e) {
                    return { ok: false, message: "Can't reach us just now - check your connection and try again." };
                }
            }

            const IMG = <?= json_encode(SITE_URL . '/assets/images/payments/') ?>;
            const CCI_LOGO = <?= json_encode(SITE_URL . '/assets/images/brand-logos/toggle-logo.png') ?>;
            const logo = (files, size = "lg") => (files[0] || "").startsWith("ri-")
                ? `<span class="acc-logo ${size === "lg" ? "give-mark" : `acc-logo-${size}`} is-icon bg-info"><i class="${files[0]}"></i></span>`
                : `<span class="acc-logo ${size === "lg" ? "give-mark" : `acc-logo-${size}`}${files.length > 1 ? " is-card" : ""}">${files.map((f) => `<img src="${IMG}${f}" alt="">`).join("")}</span>`;
            /** Each tile: how it is paid (method), what Paystack opens on (pay), and which hints show (hint). */
            const TILES = {
                "ps-mpesa": { method: "paystack", pay: "mpesa", hint: "mobile", name: "M-Pesa" },
                "ps-airtel": { method: "paystack", pay: "airtel", hint: "mobile", name: "Airtel Money" },
                "ps-card": { method: "paystack", pay: "card", hint: "paystack", name: "card" },
                "ps-pesalink": { method: "paystack", pay: "pesalink", hint: "paystack", name: "Pesalink" },
                mpesa: { method: "mpesa", pay: null, hint: "mpesa", name: "M-Pesa" },
                paybill: { method: "paybill", pay: null, hint: "paybill", name: "Pay Bill" },
            };
            const REMEMBER = "mwd-giver";
            const remembered = () => {
                try {
                    return JSON.parse(localStorage.getItem(REMEMBER) || "null");
                } catch (e) {
                    return null;
                }
            };

            function form() {
                const p = page;
                // Through Paystack when it is on (its secure page opens on the pick); the paybill's own prompt only when it isn't.
                const ps = p.methods.paystack;
                const methods = [
                    ps && { key: "ps-mpesa", title: "M-Pesa", sub: "Pay on Paystack's secure page", logos: ["mpesa.svg"] },
                    ps && { key: "ps-airtel", title: "Airtel Money", sub: "Pay on Paystack's secure page", logos: ["airtel-money.svg"] },
                    ps && { key: "ps-card", title: "Card", sub: "Visa or Mastercard", logos: ["visa.svg", "mastercard.svg"] },
                    ps && { key: "ps-pesalink", title: "Pesalink", sub: "From your bank, on Paystack", logos: ["ri-bank-line"] },
                    !ps && p.methods.mpesa && { key: "mpesa", title: "M-Pesa", sub: "The prompt comes to your phone", logos: ["mpesa.svg"] },
                    p.paybill && { key: "paybill", title: p.paybill.till ? "Buy Goods" : "Pay Bill", sub: "Yourself, from your M-Pesa menu", logos: ["mpesa.svg"] },
                ].filter(Boolean);
                if (!methods.length) {
                    body.innerHTML = `<h4 class="fw-bold mb-1">${esc(p.place.name)}</h4><p class="mb-0">Online giving isn't open yet for this church.</p>`;
                    return;
                }
                const me = remembered();
                const field = (id, label, type, attrs, hint) => `<div class="col-sm-6" data-field="${id}"><label class="form-label fw-semibold mb-1" for="${id}">${label} <span class="text-danger" data-req>*</span></label><input type="${type}" class="form-control" id="${id}" ${attrs}><div class="invalid-feedback"></div>${hint ? `<div class="form-text">${hint}</div>` : ""}</div>`;
                body.innerHTML = `
                    <div class="d-flex align-items-center gap-3 mb-3"><img src="${esc(p.logo)}" alt="" class="give-logo" onerror="this.onerror=null;this.src='${CCI_LOGO}'"><div><h4 class="fw-bold mb-0">${esc(p.place.name)}</h4>${p.note ? `<div class="give-note">${esc(p.note)}</div>` : ""}</div></div>
                    <div class="give-step"><span>1</span>Giving for</div>
                    <div class="acc-tiles mb-3">${p.purposes.map((u, i) => `<label class="acc-tile" style="--q: var(--${COLORS[u.key] || "primary"}-rgb)"><input type="radio" name="purpose" value="${u.key}"${i === 0 ? " checked" : ""}><span class="acc-tile-icon"><i class="${ICONS[u.key] || "ri-heart-line"}"></i></span><span class="acc-tile-text"><strong>${esc(u.label)}</strong></span></label>`).join("")}</div>
                    <div id="gHowMuch">
                    <div class="give-step"><span>2</span>How much</div>
                    <div class="input-group input-group-lg mb-2"><span class="input-group-text">KES</span><input type="text" inputmode="decimal" class="form-control fw-semibold" id="gAmount" placeholder="0" aria-label="Amount"><div class="invalid-feedback"></div></div>
                    <div class="give-chips mb-3">${[200, 500, 1000, 2000, 5000].map((a) => `<button type="button" class="btn btn-sm btn-outline-primary" data-amt="${a}">${a.toLocaleString("en-GB")}</button>`).join("")}</div>
                    </div>
                    <div class="give-step"><span>3</span>Pay with</div>
                    <div class="acc-tiles give-methods mb-3">${methods.map((m, x) => `<label class="acc-tile" style="--q: var(--primary-rgb)"><input type="radio" name="method" value="${m.key}"${x === 0 ? " checked" : ""}>${logo(m.logos)}<span class="acc-tile-text"><strong>${m.title}</strong><small>${m.sub}</small></span></label>`).join("")}</div>
                    <div id="gPaybillPanel" hidden></div>
                    <div id="gPayNow">
                    <div class="give-step"><span>4</span>Your details</div>
                    ${me ? `<div class="mb-2" id="gWelcome">Welcome back, <strong>${esc(String(me.name || "").split(" ")[0])}</strong> - <a href="#" id="gForget">not you?</a></div>` : ""}
                    <div class="row g-2 mb-2">
                        ${field("gName", "Full name", "text", 'maxlength="150" autocomplete="name" placeholder="e.g. Ruth Mwende"')}
                        ${field("gPhone", "Phone number", "tel", 'autocomplete="tel" placeholder="e.g. 0712 345 678"', '<span data-for="mpesa">The M-Pesa prompt comes to this number.</span><span data-for="mobile" hidden>For your SMS receipt.</span><span data-for="paystack" hidden>For your SMS receipt.</span>')}
                        ${field("gEmail", "Email", "email", 'autocomplete="email" placeholder="e.g. ruth@example.com"', '<span data-for="paystack" hidden>Paystack sends your receipt here.</span><span data-for="mpesa">Optional - for an email receipt.</span><span data-for="mobile" hidden>Optional - your receipt comes by SMS.</span>')}
                    </div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="gRemember"${me ? " checked" : ""}><label class="form-check-label" for="gRemember">Remember my details on this device</label></div>
                    <div class="alert alert-light border mb-3 py-2" id="gSummary" hidden></div>
                    <button type="button" class="btn btn-primary btn-lg w-100" id="gGo"><i class="ri-hand-heart-line me-1"></i><span id="gGoText">Give</span></button>
                    <div id="gMsg" class="mt-3" aria-live="polite"></div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 mt-3 small"><i class="ri-lock-2-line"></i><span>Secured by Paystack and Safaricom M-Pesa</span>${logo(["mpesa.svg"], "sm")}${ps ? logo(["airtel-money.svg"], "sm") : ""}${logo(["visa.svg"], "sm")}${logo(["mastercard.svg"], "sm")}</div>
                    `;
                const $ = (id) => body.querySelector(`#${id}`);
                if (me) {
                    $("gName").value = me.name || "";
                    $("gPhone").value = me.phone || "";
                    $("gEmail").value = me.email || "";
                }
                $("gForget")?.addEventListener("click", (e) => {
                    e.preventDefault();
                    try {
                        localStorage.removeItem(REMEMBER);
                    } catch (x) {}
                    ["gName", "gPhone", "gEmail"].forEach((id) => ($(id).value = ""));
                    $("gRemember").checked = false;
                    $("gWelcome").remove();
                });
                const method = () => body.querySelector('input[name="method"]:checked').value;
                const tile = () => TILES[method()] || TILES.mpesa;
                const amountOf = () => Number(String($("gAmount").value).replace(/[^0-9.]/g, "")) || 0;
                const summary = () => {
                    const v = amountOf();
                    const purpose = p.purposes.find((u) => u.key === body.querySelector('input[name="purpose"]:checked').value);
                    const t = tile();
                    const onPaystack = t.method === "paystack";
                    $("gGoText").textContent = onPaystack ? (v ? `Continue to Paystack - ${money(v)}` : "Continue to Paystack") : v ? `Give ${money(v)}` : "Give";
                    $("gSummary").hidden = !v;
                    $("gSummary").innerHTML = v ? `<strong>${esc(purpose?.label || "Gift")} · ${money(v)}</strong> to ${esc(p.place.name)} by ${esc(t.name)}${onPaystack ? " on Paystack" : ""}${t.hint !== "paystack" && v > 150000 ? '<div class="text-danger small mt-1">That is above most mobile money limits - card may be easier.</div>' : ""}` : "";
                };
                const show = () => {
                    const m = method();
                    // Pay Bill yourself: the numbers and "I've paid" instead of the details and Give.
                    $("gPayNow").hidden = m === "paybill";
                    $("gPaybillPanel").hidden = m !== "paybill";
                    $("gHowMuch").hidden = m === "paybill"; // the amount is typed on the phone
                    if (m === "paybill") paybillPanel();
                    renumber();
                    if (m === "paybill") return;
                    const h = tile().hint;
                    body.querySelectorAll("[data-for]").forEach((x) => (x.hidden = x.dataset.for !== h));
                    // Email is required for card and Pesalink (Paystack's receipt), optional for mobile money (our SMS).
                    body.querySelector('[data-field="gEmail"] [data-req]').hidden = h !== "paystack";
                    summary();
                };
                $("gAmount").addEventListener("input", summary);
                body.querySelectorAll("[data-amt]").forEach((b) => b.addEventListener("click", () => (($("gAmount").value = b.dataset.amt), $("gAmount").classList.remove("is-invalid"), summary())));
                body.querySelectorAll('input[name="method"]').forEach((r) => r.addEventListener("change", show));
                body.querySelectorAll('input[name="purpose"]').forEach((r) => r.addEventListener("change", () => (method() === "paybill" ? paybillPanel() : summary())));
                body.querySelectorAll("input.form-control").forEach((i) => i.addEventListener("input", () => i.classList.remove("is-invalid")));
                show();
                $("gGo").addEventListener("click", give);
            }

            /** Number the steps that show: 1, 2, 3... */
            const renumber = () => [...body.querySelectorAll(".give-step")].filter((x) => !x.closest("[hidden]")).forEach((x, i) => (x.querySelector("span").textContent = i + 1));

            /** Pay Bill yourself: the business number, this purpose's account number, the steps, and "I've paid". */
            function paybillPanel() {
                const p = page;
                const panel = body.querySelector("#gPaybillPanel");
                const purpose = body.querySelector('input[name="purpose"]:checked').value;
                const label = p.purposes.find((u) => u.key === purpose)?.label || "Gift";
                const account = (p.paybill.accounts.find((a) => a.label === label) || {}).account || "";
                const box = (title, value) => `<div class="border rounded-3 p-3 d-flex align-items-center justify-content-between gap-2"><div><small class="d-block">${title}</small><span class="fs-3 fw-bold">${esc(value)}</span></div><button type="button" class="btn btn-sm btn-outline-primary" data-copy="${esc(value)}"><i class="ri-file-copy-line me-1"></i>Copy</button></div>`;
                const me = remembered() || {};
                panel.innerHTML = `
                    <div class="give-step"><span>4</span>Pay from your phone</div>
                    <div class="row g-2 mb-3"><div class="${p.paybill.till ? "col-12" : "col-sm-6"}">${box(p.paybill.till ? "Till number" : "Business number", p.paybill.number)}</div>${p.paybill.till ? "" : `<div class="col-sm-6">${box(`Account number - ${label}`, account)}</div>`}</div>
                    <ol class="mb-3 ps-3"><li>Open <strong>M-Pesa</strong>, then <strong>Lipa na M-Pesa</strong>.</li><li>Choose <strong>${p.paybill.till ? "Buy Goods and Services" : "Pay Bill"}</strong> and enter <strong>${esc(p.paybill.number)}</strong>.</li>${p.paybill.till ? "" : `<li>Account number: <strong>${esc(account)}</strong> (for ${esc(label.toLowerCase())}).</li>`}<li>Enter the amount and your PIN.</li></ol>
                    ${p.paybill.claim ? `<div class="give-step"><span>5</span>I've paid - confirm it</div>
                    <p class="mb-2 small">Enter the M-Pesa code from your confirmation message (10 letters and numbers, e.g. SJK1ABC234). We check it with Safaricom.</p>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6"><label class="form-label fw-semibold mb-1" for="cCode">M-Pesa code <span class="text-danger">*</span></label><input type="text" class="form-control text-uppercase" id="cCode" maxlength="12" autocomplete="off" placeholder="e.g. SJK1ABC234"><div class="invalid-feedback"></div></div>
                        <div class="col-sm-6"><label class="form-label fw-semibold mb-1" for="cName">Full name <span class="text-danger">*</span></label><input type="text" class="form-control" id="cName" maxlength="150" autocomplete="name" value="${esc(me.name || "")}"><div class="invalid-feedback"></div></div>
                        <div class="col-sm-6"><label class="form-label fw-semibold mb-1" for="cPhone">M-Pesa number you paid from <span class="text-danger">*</span></label><input type="tel" class="form-control" id="cPhone" autocomplete="tel" placeholder="e.g. 0712 345 678" value="${esc(me.phone || "")}"><div class="invalid-feedback"></div></div>
                    </div>
                    <button type="button" class="btn btn-success w-100" id="cGo"><i class="ri-shield-check-line me-1"></i>Confirm my payment</button>
                    <div id="cMsg" class="mt-3" aria-live="polite"></div>` : `<p class="mb-0">Your receipt comes by SMS once the payment reaches us.</p>`}`;
                panel.querySelectorAll("[data-copy]").forEach((b) => b.addEventListener("click", async () => {
                    try {
                        await navigator.clipboard.writeText(b.dataset.copy);
                        b.innerHTML = '<i class="ri-check-line me-1"></i>Copied';
                    } catch (e) {}
                }));
                panel.querySelector("#cGo")?.addEventListener("click", claim);
                renumber();
            }

            /** "I've paid": check the code, then follow Safaricom's answer. */
            async function claim(e) {
                const btn = e.currentTarget;
                const msg = body.querySelector("#cMsg");
                const d = { code: body.querySelector("#cCode").value.replace(/[^A-Za-z0-9]/g, "").toUpperCase(), purpose: body.querySelector('input[name="purpose"]:checked').value, name: body.querySelector("#cName").value.trim(), phone: body.querySelector("#cPhone").value.trim() };
                body.querySelectorAll("#gPaybillPanel .is-invalid").forEach((x) => x.classList.remove("is-invalid"));
                const errors = {};
                if (!/^[A-Z0-9]{10}$/.test(d.code)) errors.cCode = "That doesn't look like an M-Pesa code - 10 letters and numbers, e.g. SJK1ABC234.";
                if (d.name.length < 2) errors.cName = "Please enter your full name.";
                if (!/^(\+?254|0)?[17]\d{8}$/.test(d.phone.replace(/[\s\-()]/g, ""))) errors.cPhone = "Please enter the M-Pesa number you paid from.";
                if (Object.keys(errors).length) return Object.entries(errors).forEach(([id, m]) => bad(id, m));
                btn.disabled = true;
                msg.innerHTML = '<div class="d-flex align-items-center gap-2"><div class="spinner-border spinner-border-sm text-success" role="status"></div><span>Checking with Safaricom...</span></div>';
                const r = await call("POST", `/give/${encodeURIComponent(CODE)}/claim`, d);
                if (!r.ok) {
                    btn.disabled = false;
                    const map = { code: "cCode", name: "cName", phone: "cPhone" };
                    const shown = Object.entries(r.errors || {}).filter(([k, v]) => map[k] && bad(map[k], [].concat(v)[0]));
                    msg.innerHTML = shown.length ? "" : `<div class="verify-result is-bad"><i class="ri-error-warning-line"></i><div><strong>${esc(r.message)}</strong></div></div>`;
                    return;
                }
                let tries = 0;
                const answer = (c) => {
                    if (c.status === "confirmed") return (msg.innerHTML = `<div class="verify-result is-good"><i class="ri-checkbox-circle-fill"></i><div><strong>Received - thank you. ${c.amount ? money(c.amount) : ""} ${esc((c.purpose || "").toLowerCase())} for ${esc(c.place || "")}.</strong><span>${c.receipt ? `Receipt ${esc(c.receipt)} · ` : ""}M-Pesa ${esc(c.code)}</span></div></div>`);
                    if (c.status === "failed") return ((btn.disabled = false), (msg.innerHTML = `<div class="verify-result is-bad"><i class="ri-close-circle-line"></i><div><strong>We couldn't confirm ${esc(c.code)}</strong><span>${esc(c.result || "Check the code, or the church treasurer will.")}</span></div></div>`));
                    if (c.status === "waiting" || ++tries >= 20) return (msg.innerHTML = `<div class="verify-result is-good"><i class="ri-time-line"></i><div><strong>Thank you - we've noted ${esc(c.code)}.</strong><span>${esc(c.result || "The church treasurer will confirm it.")}</span></div></div>`);
                    setTimeout(async () => {
                        const s = await call("GET", `/give/claim/${c.id}?code=${encodeURIComponent(c.code)}`);
                        answer(s.ok ? s.data : c);
                    }, 3000);
                };
                answer(r.data);
            }

            /** Mark a field wrong with its message. */
            function bad(id, message) {
                const input = body.querySelector(`#${id}`);
                if (!input) return false;
                input.classList.add("is-invalid");
                input.parentElement.querySelector(".invalid-feedback").textContent = message;
                return true;
            }

            /** What the giver must give us before anything starts - the server checks the same. */
            function check(d) {
                const errors = {};
                if (!d.amount || d.amount < 10) errors.gAmount = "Please enter at least KES 10.";
                if (!d.name || d.name.length < 2) errors.gName = "Please enter your full name.";
                const digits = (d.phone || "").replace(/[\s\-()]/g, "");
                const mobile = d.method === "mpesa" || d.pay === "mpesa" || d.pay === "airtel";
                if (mobile && !/^(\+?254|0)?[17]\d{8}$/.test(digits)) errors.gPhone = d.pay === "airtel" ? "Please enter your Airtel Money number, e.g. 0733 345 678." : "Please enter your M-Pesa number, e.g. 0712 345 678.";
                else if (d.method === "paystack" && !/^\+?\d{9,15}$/.test(digits)) errors.gPhone = "Please enter a valid phone number, e.g. 0712 345 678.";
                if (d.method === "paystack" && !mobile && !d.email) errors.gEmail = "Please enter your email address - Paystack sends your receipt there.";
                else if (d.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) errors.gEmail = "Please enter a valid email address.";
                return errors;
            }

            async function give(e) {
                const btn = e.currentTarget;
                const msg = body.querySelector("#gMsg");
                const d = {
                    purpose: body.querySelector('input[name="purpose"]:checked').value,
                    amount: Number(String(body.querySelector("#gAmount").value).replace(/[^0-9.]/g, "")) || 0,
                    method: (TILES[body.querySelector('input[name="method"]:checked').value] || TILES.mpesa).method,
                    pay: (TILES[body.querySelector('input[name="method"]:checked').value] || TILES.mpesa).pay,
                    name: body.querySelector("#gName").value.trim(),
                    phone: body.querySelector("#gPhone").value.trim(),
                    email: body.querySelector("#gEmail").value.trim() || null,
                };
                msg.innerHTML = "";
                body.querySelectorAll(".is-invalid").forEach((x) => x.classList.remove("is-invalid"));
                const errors = check(d);
                if (Object.keys(errors).length) {
                    Object.entries(errors).forEach(([id, m]) => bad(id, m));
                    body.querySelector(".is-invalid")?.focus();
                    return;
                }
                try {
                    body.querySelector("#gRemember").checked ? localStorage.setItem(REMEMBER, JSON.stringify({ name: d.name, phone: d.phone, email: d.email })) : localStorage.removeItem(REMEMBER);
                } catch (x) {}
                btn.disabled = true;
                body.querySelector("#gGoText").textContent = d.method === "mpesa" ? "Sending the prompt..." : "Opening Paystack's secure page...";
                const r = await call("POST", `/give/${encodeURIComponent(CODE)}`, d);
                if (!r.ok) {
                    btn.disabled = false;
                    body.querySelector("#gGoText").textContent = `Give ${money(d.amount)}`;
                    const map = { name: "gName", phone: "gPhone", email: "gEmail", amount: "gAmount" };
                    const shown = Object.entries(r.errors || {}).filter(([k, v]) => map[k] && bad(map[k], [].concat(v)[0]));
                    if (!shown.length) msg.innerHTML = `<div class="verify-result is-bad"><i class="ri-error-warning-line"></i><div><strong>${esc(r.message)}</strong></div></div>`;
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

            /** Paid: the receipt lives on the thanks page (PDF, Print), the same as after Paystack. */
            function done(d) {
                window.location.href = `give-thanks.php?ref=${encodeURIComponent(d.reference)}`;
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
