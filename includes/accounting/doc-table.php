<?php // The documents list shared by Receipts, Journals and All documents (assets/js/pages/accounting/doc-list.js). ?>
<div class="card custom-card">
    <div class="card-header justify-content-between flex-wrap gap-2">
        <div><div class="card-title"><?= htmlspecialchars($accListTitle) ?></div><span class="card-subtitle-text"><?= htmlspecialchars($accListSub) ?></span></div>
    </div>
    <div class="card-body pb-0 pt-3" id="docPills"></div>
    <div class="card-body p-0" id="docTableWrap">
        <div id="docFilters" class="list-filterbar-wrap"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 pp-table acc-table" id="docTable">
                <thead><tr>
                    <th class="pp-check"><input type="checkbox" class="form-check-input pp-pick-page" aria-label="Pick everything on this page"></th>
                    <th>Document</th><th>Date</th><th class="d-none d-md-table-cell">From / to</th><th class="d-none d-lg-table-cell">Account</th><th class="text-end">Amount</th>
                </tr></thead>
                <tbody id="docRows"></tbody>
            </table>
        </div>
    </div>
</div>
