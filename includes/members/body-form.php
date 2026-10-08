<?php
// Add / edit a member: one step at a time on the left, the member card on the right - the same
// stepper as Record demographics and the event form (.intake-*). Filled in by assets/js/pages/members/form.js.
$steps = [
    1 => ['Who', 'Their name, gender and birthday'],
    2 => ['Contact', 'Phone, address and next of kin'],
    3 => ['Church life', 'Joined, saved and baptised'],
    4 => ['Check and save', 'Look it over'],
];
$stepIcons = [1 => 'ri-user-3-line', 2 => 'ri-phone-line', 3 => 'ri-home-heart-line', 4 => 'ri-check-double-line'];
$stepColors = [1 => 'primary', 2 => 'purple', 3 => 'success', 4 => 'warning'];
?>
<div id="memberForm">
    <nav class="card custom-card intake-steps is-varied" id="intakeSteps" aria-label="Steps">
        <?php foreach ($steps as $n => [$label, $hint]) { ?>
            <button type="button" class="intake-step-btn<?= $n === 1 ? ' is-on' : '' ?><?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" data-go="<?= $n ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)">
                <span class="intake-step-dot"><span><?= $n ?></span><i class="ri-check-line"></i></span>
                <span class="intake-step-text"><strong><?= $label ?></strong><small><?= $hint ?></small></span>
            </button>
        <?php } ?>
        <span class="intake-steps-mobile" id="intakeStepsMobile">Step 1 of 4 · <?= $steps[1][0] ?></span>
        <span class="intake-steps-bar"><i id="intakeStepsBar" style="width: 25%"></i></span>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <form class="card custom-card intake-form" id="memberFormCard" novalidate autocomplete="off">
                <?php foreach ($steps as $n => [$label, $hint]) { ?>
                <section class="intake-step" data-step="<?= $n ?>" <?= $n > 1 ? 'hidden' : '' ?>>
                    <div class="intake-step-head">
                        <span class="intake-step-num is-q<?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)"><i class="<?= $stepIcons[$n] ?>"></i></span>
                        <div><h5><?= $label ?></h5><p><?= $hint ?></p></div>
                    </div>
                    <div class="intake-errors" data-errors-for="<?= $n ?>" hidden role="alert"></div>
                    <div class="intake-step-body">
                        <?php if ($n === 1) { ?>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="f_first_name">First name <span class="text-danger">*</span></label><input type="text" class="form-control" id="f_first_name" maxlength="80" placeholder="e.g. Mary"></div>
                            <div class="col-md-6"><label class="form-label" for="f_last_name">Last name <span class="text-danger">*</span></label><input type="text" class="form-control" id="f_last_name" maxlength="80" placeholder="e.g. Mutua"></div>
                            <div class="col-md-6"><label class="form-label" for="f_other_names">Other names</label><input type="text" class="form-control" id="f_other_names" maxlength="80" placeholder="Optional"></div>
                            <div class="col-md-6" data-field="gender">
                                <label class="form-label mb-2">Gender</label>
                                <div class="mb-choice-row" role="radiogroup" aria-label="Gender">
                                    <label class="mb-choice"><input type="radio" name="gender" value="female"><i class="ri-women-line"></i>Female</label>
                                    <label class="mb-choice"><input type="radio" name="gender" value="male"><i class="ri-men-line"></i>Male</label>
                                </div>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="f_date_of_birth">Date of birth</label><input type="date" class="form-control" id="f_date_of_birth"><div class="form-text" id="ageHint">Their age puts them in an age band and on the birthday list.</div></div>
                            <div class="col-md-6"><label class="form-label" for="f_marital_status">Marital status</label><select class="form-select" id="f_marital_status"><option value="">Not given</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="f_occupation">Occupation</label><input type="text" class="form-control" id="f_occupation" maxlength="120" placeholder="e.g. Teacher"></div>
                            <div class="col-md-6"><label class="form-label" for="f_national_id">National ID</label><input type="text" class="form-control" id="f_national_id" maxlength="30" placeholder="Optional - stored encrypted"></div>
                        </div>
                        <?php } elseif ($n === 2) { ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="f_phone">Phone</label>
                                <input type="tel" class="form-control" id="f_phone" maxlength="30" placeholder="e.g. 0712 345 678">
                                <div id="dupBox" class="mb-dup" hidden></div>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="f_email">Email</label><input type="email" class="form-control" id="f_email" maxlength="160" placeholder="Optional"></div>
                            <div class="col-12"><label class="form-label" for="f_address">Where they live</label><input type="text" class="form-control" id="f_address" maxlength="500" placeholder="e.g. Kima village, near the market - stored encrypted"></div>
                            <div class="col-md-6"><label class="form-label" for="f_next_of_kin_name">Next of kin</label><input type="text" class="form-control" id="f_next_of_kin_name" maxlength="120" placeholder="Name"></div>
                            <div class="col-md-6"><label class="form-label" for="f_next_of_kin_phone">Next of kin's phone</label><input type="tel" class="form-control" id="f_next_of_kin_phone" maxlength="30" placeholder="e.g. 0722 000 111"></div>
                        </div>
                        <?php } elseif ($n === 3) { ?>
                        <div class="row g-3">
                            <div class="col-12" data-field="how_joined">
                                <label class="form-label mb-2">How they joined</label>
                                <div class="ec-choices" id="howJoinedChoices" role="radiogroup" aria-label="How they joined"></div>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="f_joined_on">Joined our church on</label><input type="date" class="form-control" id="f_joined_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_previous_church">Previous church</label><input type="text" class="form-control" id="f_previous_church" maxlength="160" placeholder="If they came from another church"></div>
                            <div class="col-md-6"><label class="form-label" for="f_saved_on">Saved on</label><input type="date" class="form-control" id="f_saved_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_baptised_on">Baptised on</label><input type="date" class="form-control" id="f_baptised_on"></div>
                            <div class="col-md-6"><label class="form-label" for="f_status">In the register as</label><select class="form-select" id="f_status"></select></div>
                            <div class="col-12"><label class="form-label" for="f_notes">Notes</label><textarea class="form-control" id="f_notes" rows="3" maxlength="5000" placeholder="Anything the leaders should know - stored encrypted, never shown outside the church"></textarea></div>
                        </div>
                        <?php } else { ?>
                        <div id="reviewBody"></div>
                        <?php } ?>
                    </div>
                </section>
                <?php } ?>

                <div class="intake-foot">
                    <span class="intake-saved me-auto" id="intakeSaved">Not saved yet</span>
                    <a href="<?= $membersCtx['baseUrl'] ?>/" class="btn btn-light" id="cancelBtn">Cancel</a>
                    <button type="button" class="btn btn-light" id="backBtn" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                    <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                    <button type="button" class="btn btn-success" id="saveBtn" hidden><i class="ri-check-line me-1"></i><span>Save member</span></button>
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
                        <li><b>ID, address and notes</b> are stored encrypted.</li>
                        <li>Add a <b>photo</b> on their page once they're saved.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
