<?php
// Add / edit a member - one short card: name, phone, area, gender, and Sunday school or main church
// (2026-10-09: nothing more is collected). Editing adds "Church life" for dates added later.
// Filled in by assets/js/pages/members/form.js.
?>
<div id="memberForm">
    <div class="row g-4">
        <div class="col-lg-8">
            <form class="card custom-card intake-form" id="memberFormCard" novalidate autocomplete="off">
                <div class="card-header"><div><div class="card-title" id="formTitle">Add a member</div><span class="card-subtitle-text">Just the basics - nothing more is kept</span></div></div>
                <div class="card-body">
                    <div class="intake-errors mb-3" id="formErrors" hidden role="alert"></div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="f_first_name">First name <span class="text-danger">*</span></label><input type="text" class="form-control" id="f_first_name" maxlength="80" placeholder="e.g. Mary"></div>
                        <div class="col-md-6"><label class="form-label" for="f_last_name">Last name</label><input type="text" class="form-control" id="f_last_name" maxlength="80" placeholder="e.g. Mutua"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="f_phone">Phone</label>
                            <input type="tel" class="form-control" id="f_phone" maxlength="30" placeholder="e.g. 0712 345 678">
                            <div id="dupBox" class="mb-dup" hidden></div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="f_area">Area</label><input type="text" class="form-control" id="f_area" maxlength="80" list="areaList" placeholder="Where they live, e.g. Kasikeu"><datalist id="areaList"></datalist></div>
                        <div class="col-md-6" data-field="gender">
                            <label class="form-label mb-2">Gender</label>
                            <div class="mb-choice-row" role="radiogroup" aria-label="Gender">
                                <label class="mb-choice"><input type="radio" name="gender" value="female"><i class="ri-women-line"></i>Female</label>
                                <label class="mb-choice"><input type="radio" name="gender" value="male"><i class="ri-men-line"></i>Male</label>
                            </div>
                        </div>
                        <div class="col-md-6" data-field="congregation">
                            <label class="form-label mb-2">Part of</label>
                            <div class="mb-choice-row" role="radiogroup" aria-label="Sunday school or main church">
                                <label class="mb-choice"><input type="radio" name="congregation" value="main_church"><i class="ri-community-line"></i>Main church</label>
                                <label class="mb-choice"><input type="radio" name="congregation" value="sunday_school"><i class="ri-book-open-line"></i>Sunday school</label>
                            </div>
                        </div>
                    </div>

                    <details class="mb-later mt-4" id="laterBox" hidden>
                        <summary><i class="ri-home-heart-line"></i><span><strong>Church life</strong><small>Joined, saved and baptised - add these whenever you have them</small></span></summary>
                        <div class="row g-3 pt-3">
                            <div class="col-md-6"><label class="form-label" for="f_joined_on">Joined our church on</label><input type="date" class="form-control" id="f_joined_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_how_joined">How they joined</label><select class="form-select" id="f_how_joined"><option value="">Not given</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="f_saved_on">Saved on</label><input type="date" class="form-control" id="f_saved_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_baptised_on">Baptised on</label><input type="date" class="form-control" id="f_baptised_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_previous_church">Previous church</label><input type="text" class="form-control" id="f_previous_church" maxlength="160" placeholder="If they came from another church"></div>
                            <div class="col-md-6"><label class="form-label" for="f_status">In the register as</label><select class="form-select" id="f_status"></select></div>
                        </div>
                    </details>
                </div>
                <div class="intake-foot">
                    <span class="intake-saved me-auto" id="intakeSaved">Only your church's leaders see this</span>
                    <a href="<?= $membersCtx['baseUrl'] ?>/" class="btn btn-light" id="cancelBtn">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="saveBtn"><i class="ri-check-line me-1"></i><span>Save member</span></button>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="intake-aside">
                <div class="card custom-card preview-card">
                    <div class="preview-head">
                        <span class="preview-label"><i class="ri-contacts-line"></i>Their member card</span>
                        <span class="soft-chip soft-success"><i class="ri-lock-2-line"></i>Private</span>
                    </div>
                    <div class="preview-section" id="previewCard"></div>
                </div>
                <div class="card custom-card intake-tips">
                    <strong><span class="ev-tile is-sm text-dark" style="--q: var(--warning-rgb)"><i class="ri-lightbulb-line"></i></span>Good to know</strong>
                    <ul>
                        <li>Only <b>your church's leaders</b> see names. The region and diocese only ever see counts.</li>
                        <li>We keep <b>just these details</b> - no ID, birthday or address - so there's less to protect.</li>
                        <li><b>Baptism and the date they joined</b> can be added later, from Edit.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
