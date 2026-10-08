<!-- One piece of equipment (docs/specs/people-and-care-spec.md, P5) - filled in by assets/js/pages/facilities/item.js. -->
<div class="card custom-card" id="itHero">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            <span class="skel" style="width:96px;height:96px;border-radius:1rem"></span>
            <div class="flex-fill"><span class="skel skel-title"></span><span class="skel skel-line mt-2" style="width:40%"></span></div>
        </div>
    </div>
</div>

<div class="nav section-tabs" id="itTabs" role="tablist" aria-label="Equipment" hidden>
    <button class="nav-link section-tab active" data-tab="purchase" type="button" role="tab">
        <span class="section-tab-icon bg-success"><i class="ri-bill-line"></i></span>
        <span class="section-tab-text"><strong>Purchase</strong><small id="tabPurchase">What it cost, and the receipt</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="loans" type="button" role="tab">
        <span class="section-tab-icon bg-primary"><i class="ri-hand-coin-line"></i></span>
        <span class="section-tab-text"><strong>Borrowing</strong><small id="tabLoans">Who has borrowed it</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="repairs" type="button" role="tab">
        <span class="section-tab-icon bg-warning"><i class="ri-tools-line"></i></span>
        <span class="section-tab-text"><strong>Repairs</strong><small id="tabRepairs">What was fixed</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="history" type="button" role="tab">
        <span class="section-tab-icon bg-purple"><i class="ri-history-line"></i></span>
        <span class="section-tab-text"><strong>History</strong><small>Every change, and who made it</small></span>
    </button>
</div>

<div id="itMain"></div>
