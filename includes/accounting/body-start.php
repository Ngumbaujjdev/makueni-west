<?php
// Start here - setting up a place's books for the first time, one step at a time
// (the same stepper as the Event and Budget forms, .intake-*). Filled in by
// assets/js/pages/accounting/start.js; each step saves through the endpoints the
// Accounting pages already use.
$steps = [
    1 => ['Where your money is kept', 'Cash, bank, M-Pesa'],
    2 => ['What each held at the start', 'Your opening balances'],
    3 => ['Who does what', 'The people for each job'],
    4 => ['Extras', 'Funds and your own lines - optional'],
    5 => ['Finish', 'Look it over'],
];
$stepIcons = [1 => 'ri-bank-line', 2 => 'ri-scales-3-line', 3 => 'ri-team-line', 4 => 'ri-magic-line', 5 => 'ri-flag-line'];
$stepColors = [1 => 'primary', 2 => 'success', 3 => 'purple', 4 => 'warning', 5 => 'pink'];
include __DIR__ . '/toolbar.php';
?>
<div id="startApp">
    <nav class="card custom-card intake-steps is-varied" id="intakeSteps" aria-label="Steps">
        <?php foreach ($steps as $n => [$label, $hint]) { ?>
            <button type="button" class="intake-step-btn<?= $n === 1 ? ' is-on' : '' ?><?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" data-go="<?= $n ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)">
                <span class="intake-step-dot"><span><?= $n ?></span><i class="ri-check-line"></i></span>
                <span class="intake-step-text"><strong><?= $label ?></strong><small><?= $hint ?></small></span>
            </button>
        <?php } ?>
        <span class="intake-steps-mobile" id="intakeStepsMobile">Step 1 of <?= count($steps) ?> · <?= $steps[1][0] ?></span>
        <span class="intake-steps-bar"><i id="intakeStepsBar" style="width: <?= round(100 / count($steps)) ?>%"></i></span>
    </nav>

    <div class="row g-4" id="startForm">
        <div class="col-lg-8">
            <div class="card custom-card intake-form">
                <?php foreach ($steps as $n => [$label, $hint]) { ?>
                <section class="intake-step" data-step="<?= $n ?>" <?= $n > 1 ? 'hidden' : '' ?>>
                    <div class="intake-step-head">
                        <span class="intake-step-num is-q<?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)"><i class="<?= $stepIcons[$n] ?>"></i></span>
                        <div><h5><?= $label ?></h5><p id="startHint<?= $n ?>"><?= $hint ?></p></div>
                    </div>
                    <div class="intake-step-body" id="startStep<?= $n ?>">
                        <span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width: 60%"></span>
                    </div>
                </section>
                <?php } ?>
                <div class="intake-foot">
                    <span class="intake-saved me-auto" id="startSaved">Each step saves as you go</span>
                    <a href="<?= $accCtx['baseUrl'] ?>/" class="btn btn-light">Later</a>
                    <button type="button" class="btn btn-light" id="backBtn" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                    <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                    <button type="button" class="btn btn-success" id="finishBtn" hidden><i class="ri-flag-line me-1"></i>Start recording</button>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card custom-card acc-start-sofar">
                <div class="card-header"><div class="card-title d-flex align-items-center gap-2"><span class="avatar avatar-xs avatar-rounded bg-primary text-white"><i class="ri-book-open-line"></i></span>Your books so far</div></div>
                <div class="card-body" id="startSoFar"></div>
            </div>
        </div>
    </div>

    <div class="card custom-card acc-start-done" id="startDone" hidden></div>
</div>
