<?php
/**
 * Public "Verify a report" page - where the QR code on every report PDF
 * lands (docs/specs/reports-spec.md). No sign-in needed. It says whether a
 * report is genuine and who made it and when; it never shows any figures.
 * Someone holding the file can also check it hasn't been changed: the file
 * is fingerprinted (SHA-256) in the browser and compared with the one
 * recorded when the report was generated. The file never leaves the device.
 */
require_once __DIR__ . '/includes/session-manager.php';
$code = strtoupper(trim(preg_replace('/[^A-Za-z0-9-]/', '', $_GET['code'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" <?= appearanceThemeAttributes() ?>>

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify a report - Makueni West Diocese</title>
    <meta name="Description" content="Check that a Makueni West Diocese report is genuine" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.min.css" rel="stylesheet" />
</head>

<body class="verify-page">
    <main class="verify-wrap">
        <div class="verify-brand">
            <img src="<?= SITE_URL ?>/assets/images/brand-logos/toggle-logo.png" alt="" width="44" height="44">
            <div>
                <strong>Makueni West Diocese</strong>
                <span>Report verification</span>
            </div>
        </div>

        <div class="card custom-card verify-card">
            <div class="card-body">
                <h4 class="fw-bold mb-1">Verify a report</h4>
                <p class="mb-3">Enter the verification code printed at the bottom of the report, or scan its QR code.</p>
                <form id="verifyForm" class="verify-form" autocomplete="off">
                    <input type="text" class="form-control" id="verifyCode" placeholder="MWD-DEM-XXXX-XXXX" value="<?= htmlspecialchars($code) ?>" aria-label="Verification code" spellcheck="false">
                    <button type="submit" class="btn btn-primary" id="verifyBtn"><i class="ri-shield-check-line me-1"></i>Verify</button>
                </form>
                <div id="verifyResult" class="mt-3" aria-live="polite"></div>
            </div>
        </div>

        <p class="verify-foot">Only whether the report is genuine and who made it is shown here - never the figures in it.</p>
    </main>

    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script>
        (function () {
            "use strict";
            const form = document.getElementById("verifyForm");
            const input = document.getElementById("verifyCode");
            const result = document.getElementById("verifyResult");
            const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
            let record = null;

            async function verify(code) {
                code = code.trim().toUpperCase();
                if (!code) return;
                const btn = document.getElementById("verifyBtn");
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking';
                history.replaceState(null, "", `${location.pathname}?code=${encodeURIComponent(code)}`);
                try {
                    const res = await fetch(`${AppConfig.API_BASE_URL}/reports/verify/${encodeURIComponent(code)}`, { headers: { Accept: "application/json" } });
                    if (res.status === 429) throw new Error("Too many checks in a short time. Wait a minute and try again.");
                    const body = await res.json();
                    record = body.data;
                    render(record);
                } catch (e) {
                    result.innerHTML = `<div class="verify-result is-bad"><i class="ri-error-warning-line"></i><div><strong>Couldn't check right now</strong><span>${esc(e.message || "Try again in a moment.")}</span></div></div>`;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ri-shield-check-line me-1"></i>Verify';
                }
            }

            function render(d) {
                if (!d || !d.genuine) {
                    result.innerHTML = `
                        <div class="verify-result is-bad">
                            <i class="ri-close-circle-line"></i>
                            <div><strong>Not recognised</strong><span>No report with code <b>${esc(d?.code)}</b> was issued by the system. Check the code, or ask the church office for a fresh copy.</span></div>
                        </div>`;
                    return;
                }
                const when = d.generated_at ? new Date(d.generated_at).toLocaleString("en-GB", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";
                result.innerHTML = `
                    <div class="verify-result is-good">
                        <i class="ri-checkbox-circle-line"></i>
                        <div><strong>Genuine report</strong><span>Issued by the Makueni West Diocese Management System.</span></div>
                    </div>
                    <dl class="verify-facts">
                        <dt>Report</dt><dd>${esc(d.title)}</dd>
                        <dt>For</dt><dd>${esc(d.scope_label)}</dd>
                        <dt>Period</dt><dd>${esc(d.period_label)}</dd>
                        <dt>Format</dt><dd>${d.format === "xlsx" ? "Excel" : "PDF"}</dd>
                        <dt>Generated</dt><dd>${esc(when)}${d.generated_by ? ` by ${esc(d.generated_by)}` : ""}</dd>
                        <dt>Code</dt><dd><span class="soft-chip soft-primary rp-code">${esc(d.code)}</span></dd>
                    </dl>
                    <label class="verify-drop" id="verifyDrop">
                        <input type="file" id="verifyFile" accept=".pdf,.xlsx" hidden>
                        <i class="ri-file-search-line"></i>
                        <span><strong>Have the file? Check it hasn't been changed</strong><small>Drop it here or tap to choose. It stays on your device.</small></span>
                    </label>
                    <div id="verifyFileResult" class="mt-2"></div>`;
                wireFileCheck();
            }

            function wireFileCheck() {
                const drop = document.getElementById("verifyDrop");
                const fileInput = document.getElementById("verifyFile");
                ["dragenter", "dragover"].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add("is-over"); }));
                ["dragleave", "drop"].forEach((t) => drop.addEventListener(t, () => drop.classList.remove("is-over")));
                drop.addEventListener("drop", (e) => { e.preventDefault(); if (e.dataTransfer.files[0]) checkFile(e.dataTransfer.files[0]); });
                fileInput.addEventListener("change", () => fileInput.files[0] && checkFile(fileInput.files[0]));
            }

            async function checkFile(file) {
                const out = document.getElementById("verifyFileResult");
                if (!window.crypto?.subtle) {
                    out.innerHTML = '<div class="verify-result is-bad"><i class="ri-error-warning-line"></i><div><strong>This browser can\'t check files</strong><span>Open this page over https, or in a newer browser.</span></div></div>';
                    return;
                }
                const hash = await crypto.subtle.digest("SHA-256", await file.arrayBuffer());
                const hex = [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, "0")).join("");
                out.innerHTML = hex === record.file_hash
                    ? `<div class="verify-result is-good"><i class="ri-checkbox-circle-line"></i><div><strong>This file is the original</strong><span>${esc(file.name)} matches the report exactly.</span></div></div>`
                    : `<div class="verify-result is-bad"><i class="ri-alert-line"></i><div><strong>This file has been changed</strong><span>${esc(file.name)} doesn't match what was issued - even one edited number changes its fingerprint.</span></div></div>`;
            }

            form.addEventListener("submit", (e) => { e.preventDefault(); verify(input.value); });
            if (input.value) verify(input.value);
        })();
    </script>
</body>

</html>
