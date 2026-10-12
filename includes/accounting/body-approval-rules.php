<?php
// Approval rules (docs/specs/accounting-spec.md, A4b) - laid out like the OrchestrateIQ reference the user chose:
// three capsules (place, the pill nav, Live + window), the hero with approvers over time, then three cards.
$accButtons = '';
include __DIR__ . '/toolbar.php';
?>
<div class="ap-canvas">
    <div class="ap-frame">
        <div class="ap-capsule ap-brand" id="apBrand"><span class="ap-brand-icon"><i class="ri-shield-check-line"></i></span><span id="apBrandName">Approvals</span></div>
        <nav class="ap-capsule ap-nav" role="tablist" aria-label="Approval rules">
            <button type="button" class="ap-tab is-on" data-ap-tab="overview" role="tab" aria-selected="true">Overview</button>
            <button type="button" class="ap-tab" data-ap-tab="rules" role="tab" aria-selected="false">Rules</button>
            <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php">Live map</a>
            <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php?tab=mine">Waiting</a>
            <a class="ap-tab" href="<?= htmlspecialchars($accCtx['baseUrl']) ?>/approvals.php?tab=delegations">Handed over</a>
        </nav>
        <div class="ap-capsule ap-status">
            <span class="ap-live"><span class="ap-live-dot"></span>Live</span>
            <label class="ap-window"><i class="ri-calendar-line"></i><select id="apDays" aria-label="How far back">
                <option value="1">24 hours</option>
                <option value="7">7 days</option>
                <option value="14" selected>14 days</option>
                <option value="30">30 days</option>
            </select><i class="ri-arrow-down-s-line"></i></label>
        </div>
    </div>

    <div id="apOverview">
        <section class="ap-panel ap-hero" id="apHero"></section>
        <div class="ap-trio">
            <section class="ap-panel" id="apNext"></section>
            <section class="ap-panel" id="apDetected"></section>
            <section class="ap-panel" id="apTry"></section>
        </div>
    </div>

    <div id="apRules" hidden></div>
</div>
