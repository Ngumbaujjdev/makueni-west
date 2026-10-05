<?php
// One sent message: who it went to and how it went, replies, Retry / Cancel. Filled in by assets/js/pages/messages/message.js.
?>
<div id="messagePage">
    <div class="card custom-card" id="msgHero"><div class="card-body"><span class="skel skel-title" style="width:40%"></span><span class="skel skel-line mt-2" style="width:60%"></span></div></div>
    <div class="row" id="statCardsRow"></div>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card custom-card">
                <div class="card-header"><div class="card-title">Who it went to</div></div>
                <div class="card-body pb-0"><div id="recipientFilters"></div></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table text-nowrap w-100" id="recipientTable">
                            <thead><tr><th>Name</th><th>Place</th><th>SMS</th><th>Email</th><th>In the app</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4" id="msgSide"></div>
    </div>
</div>
