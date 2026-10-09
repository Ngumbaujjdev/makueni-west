<?php
$accButtons = '<button type="button" class="btn btn-outline-primary" id="delegateBtn"><i class="ri-user-shared-line me-1"></i>Away? Hand over</button>'
    . ($accCtx['can']['rules'] ? '<a class="btn btn-outline-primary" href="' . $accCtx['baseUrl'] . '/approval-rules.php"><i class="ri-settings-3-line me-1"></i>Approval rules</a>' : '');
include __DIR__ . '/toolbar.php';
?>
<div class="nav section-tabs" id="apTabs" role="tablist" aria-label="Approvals">
    <button class="nav-link section-tab active" data-tab="waiting" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-warning"><i class="ri-time-line"></i></span>
        <span class="section-tab-text"><strong>Waiting for me</strong><small id="apWaitingFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="mine" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-primary"><i class="ri-hand-coin-line"></i></span>
        <span class="section-tab-text"><strong>I asked for</strong><small id="apMineFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="decided" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-success"><i class="ri-checkbox-circle-line"></i></span>
        <span class="section-tab-text"><strong>I decided</strong><small>&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="delegations" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-user-shared-line"></i></span>
        <span class="section-tab-text"><strong>Handed over</strong><small id="apDelegFigure">&nbsp;</small></span>
    </button>
</div>
<div class="card custom-card">
    <div class="card-body p-0" id="apList"></div>
</div>
