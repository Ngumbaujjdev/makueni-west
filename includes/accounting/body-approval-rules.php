<?php
// Approval rules (docs/specs/accounting-spec.md, A4b) - laid out like the OrchestrateIQ reference the user chose:
// three capsules (place, the pill nav, Live + window), the hero with approvers over time, then three cards.
$accButtons = '';
include __DIR__ . '/toolbar.php';
?>
<div class="ap-canvas">
    <?php $apPage = 'rules';
    $apActive = ($_GET['view'] ?? '') === 'rules' ? 'rules' : 'overview';
    include __DIR__ . '/approvals-frame.php'; ?>

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
