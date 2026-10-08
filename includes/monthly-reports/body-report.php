<?php
// One monthly report: while it is our draft, a four-step form with the live figures; otherwise the
// report as one tidy document, with the comments and (from above) Mark as seen.
// Filled in by assets/js/pages/monthly-reports/report.js.
$steps = [
    1 => ['The figures', 'Filled in for you'],
    2 => ['What happened', 'Events, sessions, visits'],
    3 => ['In the pastor\'s words', 'What only you know'],
    4 => ['Files, then send', 'Photos or PDFs, then send it'],
];
?>
<div id="reportPage">
    <div class="card custom-card mr-hero" id="mrHero">
        <div class="card-body">
            <div class="d-flex gap-3" aria-hidden="true">
                <span class="skel skel-tile" style="width:64px;height:64px"></span>
                <div class="flex-fill"><span class="skel skel-title" style="width:40%"></span><span class="skel skel-line mt-2" style="width:30%"></span></div>
            </div>
        </div>
    </div>

    <!-- Writing: the stepper (our own draft) -->
    <div id="writeView" hidden>
        <?php $stepColors = [1 => 'success', 2 => 'purple', 3 => 'pink', 4 => 'warning']; // each step its own colour (2026-10-08) ?>
        <nav class="card custom-card intake-steps is-varied" id="intakeSteps" aria-label="Steps">
            <?php foreach ($steps as $n => [$label, $hint]) { ?>
                <button type="button" class="intake-step-btn<?= $n === 1 ? ' is-on' : '' ?><?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" data-go="<?= $n ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)">
                    <span class="intake-step-dot"><span><?= $n ?></span><i class="ri-check-line"></i></span>
                    <span class="intake-step-text"><strong><?= $label ?></strong><small><?= $hint ?></small></span>
                </button>
            <?php } ?>
            <span class="intake-steps-mobile" id="intakeStepsMobile">Step 1 of 4 · The figures</span>
            <span class="intake-steps-bar"><i id="intakeStepsBar" style="width: 25%"></i></span>
        </nav>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card custom-card intake-form">
                    <?php foreach ($steps as $n => [$label, $hint]) { ?>
                    <section class="intake-step" data-step="<?= $n ?>" <?= $n > 1 ? 'hidden' : '' ?>>
                        <div class="intake-step-head">
                            <span class="intake-step-num is-q<?= $stepColors[$n] === 'warning' ? ' is-dark' : '' ?>" style="--q: var(--<?= $stepColors[$n] ?>-rgb)"><?= $n ?></span>
                            <div><h5><?= $label ?></h5><p><?= $hint ?></p></div>
                        </div>
                        <div class="intake-step-body" id="stepBody<?= $n ?>"></div>
                    </section>
                    <?php } ?>
                    <div class="intake-foot">
                        <span class="intake-saved me-auto" id="intakeSaved">Saves as you type</span>
                        <button type="button" class="btn btn-light" id="backBtn" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                        <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                        <button type="button" class="btn btn-success" id="sendBtn" hidden><i class="ri-send-plane-line me-1"></i>Send the report</button>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="intake-aside">
                    <div class="card custom-card preview-card">
                        <div class="preview-head">
                            <span class="preview-label"><i class="ri-eye-line"></i>What goes up</span>
                            <span id="previewStatus"></span>
                        </div>
                        <div class="preview-section" id="previewBody"></div>
                    </div>
                    <div class="card custom-card intake-tips">
                        <strong><i class="ri-lightbulb-line"></i>Good to know</strong>
                        <ul>
                            <li>The figures come from what is already recorded. Fix a number where it was recorded and it updates here.</li>
                            <li>Everything <b>saves as you type</b>. Come back any time before you send.</li>
                            <li>When you <b>send</b>, the figures are kept as they are, and <span id="tipAbove">the place above</span> is told.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reading: the report as a document -->
    <div class="row g-4" id="readView" hidden>
        <div class="col-xl-8" id="readMain"></div>
        <div class="col-xl-4" id="readSide"></div>
    </div>
</div>
