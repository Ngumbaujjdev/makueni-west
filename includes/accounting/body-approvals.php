<?php
$accButtons = '<button type="button" class="btn btn-outline-primary" id="delegateBtn"><i class="ri-user-shared-line me-1"></i>Away? Hand over</button>'
    . ($accCtx['can']['rules'] ? '<a class="btn btn-outline-primary" href="' . $accCtx['baseUrl'] . '/approval-rules.php"><i class="ri-settings-3-line me-1"></i>Approval rules</a>' : '');
include __DIR__ . '/toolbar.php';
?>
<div class="card custom-card acc-lanes-card" id="apBoardCard">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title">Who holds what</div><span class="card-subtitle-text">Everything waiting for approval here, on the person it waits on - from the day it was asked to today</span></div>
        <div class="d-flex flex-wrap gap-2" id="apBoardChips"></div>
    </div>
    <div class="card-body" id="apBoard"><div class="placeholder-glow"><span class="placeholder col-12 mb-2" style="height:3rem"></span><span class="placeholder col-12" style="height:3rem"></span></div></div>
</div>

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

<div class="row">
    <div class="col-xl-8">
        <div class="card custom-card acc-rec-card">
            <div class="card-header justify-content-between"><div><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-warning"><i class="ri-flashlight-line"></i></span><span id="apListTitle">Next steps</span></div><span class="card-subtitle-text" id="apListSub">What waits for you, oldest first</span></div></div>
            <div class="card-body" id="apList"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card custom-card acc-rec-card">
            <div class="card-header"><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-primary"><i class="ri-calendar-check-line"></i></span>This month</div></div>
            <div class="card-body" id="apStats"></div>
        </div>
        <div class="card custom-card acc-rec-card">
            <div class="card-header"><div class="card-title d-flex align-items-center gap-2"><span class="acc-rec-icon bg-purple"><i class="ri-history-line"></i></span>Recent activity</div></div>
            <div class="card-body" id="apActivity"></div>
        </div>
    </div>
</div>
