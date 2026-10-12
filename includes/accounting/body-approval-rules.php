<?php
// Approval rules (docs/specs/accounting-spec.md, A4b): who signs what here - laid out like the OrchestrateIQ reference the user chose.
$accButtons = '<button type="button" class="btn btn-primary" id="addRuleBtn" hidden><i class="ri-add-line me-1"></i>Add a rule</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="ap-topbar" role="tablist" aria-label="Approval rules">
    <div class="ap-tabs">
        <button type="button" class="ap-tab is-on" data-ap-tab="overview" role="tab" aria-selected="true">Overview</button>
        <button type="button" class="ap-tab" data-ap-tab="rules" role="tab" aria-selected="false">Rules</button>
        <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php">Live map</a>
        <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php?tab=mine">Waiting</a>
        <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php?tab=delegations">Handed over</a>
    </div>
    <div class="ap-topbar-end">
        <span class="ap-live"><span class="ap-live-dot"></span>Live</span>
        <select class="form-select form-select-sm ap-subject" id="apSubject" aria-label="Which documents">
            <option value="payment_voucher">Payment vouchers</option>
            <option value="requisition">Requisitions</option>
            <option value="payroll_run">Payroll</option>
        </select>
    </div>
</div>

<div id="apOverview">
    <div class="card custom-card ap-hero" id="apHero"></div>
    <div class="row g-3 ap-bottom">
        <div class="col-xl-4 d-flex"><div class="card custom-card ap-card flex-fill" id="apNext"></div></div>
        <div class="col-xl-4 d-flex"><div class="card custom-card ap-card flex-fill" id="apDetected"></div></div>
        <div class="col-xl-4 d-flex"><div class="card custom-card ap-card flex-fill" id="apTry"></div></div>
    </div>
</div>

<div id="apRules" hidden></div>
