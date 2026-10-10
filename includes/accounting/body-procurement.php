<?php
$accButtons = $accCtx['can']['procure'] ? '<button type="button" class="btn btn-outline-primary" id="supplierBtn" data-own-only><i class="ri-store-2-line me-1"></i>Add a supplier</button>' : '';
include __DIR__ . '/toolbar.php';
?>
<div class="row" id="statCardsRow"></div>

<div class="nav section-tabs" id="prTabs" role="tablist" aria-label="Procurement">
    <button class="nav-link section-tab active" data-tab="orders" type="button" role="tab" aria-selected="true">
        <span class="section-tab-icon bg-primary"><i class="ri-shopping-cart-2-line"></i></span>
        <span class="section-tab-text"><strong>Orders</strong><small id="prOrdersFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="bills" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-warning"><i class="ri-file-list-2-line"></i></span>
        <span class="section-tab-text"><strong>Bills to pay</strong><small id="prBillsFigure">&nbsp;</small></span>
    </button>
    <button class="nav-link section-tab" data-tab="suppliers" type="button" role="tab" aria-selected="false">
        <span class="section-tab-icon bg-purple"><i class="ri-store-2-line"></i></span>
        <span class="section-tab-text"><strong>Suppliers</strong><small id="prSuppliersFigure">&nbsp;</small></span>
    </button>
</div>

<div id="prOrdersPane">
    <div class="card custom-card" id="prToOrderCard" hidden>
        <div class="card-header"><div><div class="card-title">Approved - to order</div><span class="card-subtitle-text">Purchases approved and not yet ordered or paid</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="prToOrderTable"><thead><tr><th>Requisition</th><th class="d-none d-md-table-cell">Asked by</th><th class="d-none d-lg-table-cell">Quotations</th><th class="text-end">Approved</th><th class="text-end">Action</th></tr></thead><tbody id="prToOrderRows"></tbody></table></div></div>
    </div>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Purchase orders</div><span class="card-subtitle-text">Ordered, received, billed - open one to receive the goods or enter the bill</span></div></div>
        <div class="card-body pb-0 pt-3" id="prPills"></div>
        <div class="card-body p-0" id="prTableWrap">
            <div id="prFilters" class="list-filterbar-wrap"></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 pp-table acc-table" id="prTable">
                    <thead><tr>
                        <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                        <th>Order</th><th>Date</th><th class="d-none d-md-table-cell">Supplier</th><th class="d-none d-lg-table-cell">Where it stands</th><th class="text-end">Amount</th>
                    </tr></thead>
                    <tbody id="prRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div id="prBillsPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Supplier bills</div><span class="card-subtitle-text">Posted against an order and what was received - paid by a voucher already authorised</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="prBillTable"><thead><tr><th>Bill</th><th class="d-none d-md-table-cell">Date</th><th class="d-none d-lg-table-cell">Order</th><th>Where it stands</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead><tbody id="prBillRows"></tbody></table></div></div>
    </div>
</div>
<div id="prSuppliersPane" hidden>
    <div class="card custom-card">
        <div class="card-header"><div><div class="card-title">Suppliers</div><span class="card-subtitle-text">Who this place buys from, what was ordered and what is owed</span></div></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 acc-table" id="prSupplierTable"><thead><tr><th>Supplier</th><th class="d-none d-md-table-cell">Contact</th><th class="d-none d-lg-table-cell">KRA PIN</th><th class="text-end">Ordered</th><th class="text-end">Owed</th><th class="text-end">Action</th></tr></thead><tbody id="prSupplierRows"></tbody></table></div></div>
    </div>
</div>
