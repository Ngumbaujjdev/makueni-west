<?php
// New / edit event: one step at a time on the left, a live preview on the right - the same
// stepper as the Budget form (.intake-*). Filled in by assets/js/pages/events/form.js.
$isInit = $eventsCtx['kind'] === 'initiative';
$one = $isInit ? 'initiative' : 'event';
$steps = [
    1 => ['What and when', $isInit ? 'The initiative and how often it meets' : 'The event and its dates'],
    2 => ['Where and who', 'Venue, people, who it is open to'],
    3 => ['Registration and money', 'Numbers, fees and the plan'],
    4 => ['Check and publish', 'Look it over, then save'],
];
$stepIcons = [1 => 'ri-calendar-event-line', 2 => 'ri-map-pin-user-line', 3 => 'ri-hand-coin-line', 4 => 'ri-send-plane-line'];
// Each step its own colour (2026-10-08) - its tile and its dot when it's the one open.
$stepColors = [1 => 'primary', 2 => 'purple', 3 => 'warning', 4 => 'success'];
?>
<div id="eventForm">
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

    <div class="row g-4">
        <div class="col-lg-8">
            <form class="card custom-card intake-form" id="eventFormCard" novalidate autocomplete="off">
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
                            <div class="col-12">
                                <label class="form-label" for="f_title">Name of the <?= $one ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="f_title" name="title" maxlength="160" placeholder="<?= $isInit ? 'e.g. Discipleship Class 2026' : 'e.g. Regional Youth Convention 2026' ?>">
                            </div>
                            <!-- Kind as cards (v1-events' event wizard); the select underneath keeps the value. -->
                            <div class="col-12" data-field="type">
                                <label class="form-label mb-2">Kind of <?= $one ?> <span class="text-danger">*</span></label>
                                <div class="ec-choices" id="typeChoices" role="radiogroup" aria-label="Kind of <?= $one ?>"></div>
                                <select class="d-none" id="f_type" name="type" tabindex="-1" aria-hidden="true"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f_audience">Who it is for</label>
                                <select class="form-select" id="f_audience" name="audience"></select>
                            </div>
                            <!-- When: Starts -> Ends cards with how long it runs -->
                            <div class="col-12">
                                <label class="form-label mb-2"><?= $isInit ? 'When it runs' : 'When' ?> <span class="text-danger">*</span></label>
                                <div class="ee-when">
                                    <div class="ee-when-card is-start">
                                        <div class="ee-when-title"><i class="ri-play-circle-line"></i><?= $isInit ? 'First day' : 'Starts' ?></div>
                                        <div class="ee-when-fields">
                                            <input type="date" class="form-control" id="f_start_date" name="starts_at" aria-label="<?= $isInit ? 'First day' : 'Start date' ?>">
                                            <input type="time" class="form-control" id="f_start_time" value="09:00" aria-label="Start time">
                                        </div>
                                    </div>
                                    <span class="ee-when-arrow" aria-hidden="true"><i class="ri-arrow-right-line"></i></span>
                                    <div class="ee-when-card is-end">
                                        <div class="ee-when-title"><i class="ri-stop-circle-line"></i><?= $isInit ? 'Last day' : 'Ends' ?></div>
                                        <div class="ee-when-fields">
                                            <input type="date" class="form-control" id="f_end_date" name="ends_at" aria-label="<?= $isInit ? 'Last day' : 'End date' ?>">
                                            <input type="time" class="form-control" id="f_end_time" value="16:00" aria-label="End time">
                                        </div>
                                    </div>
                                </div>
                                <div class="ee-when-foot"><span class="ec-duration" id="whenDuration" hidden></span></div>
                            </div>
                            <?php if ($isInit) { ?>
                            <div class="col-md-6">
                                <label class="form-label" for="f_frequency">How often it meets <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_frequency" name="frequency"></select>
                            </div>
                            <div class="col-md-6" id="meetingDayWrap">
                                <label class="form-label" for="f_meeting_day">On</label>
                                <select class="form-select" id="f_meeting_day" name="meeting_day">
                                    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $d) { ?>
                                    <option value="<?= $i ?>"><?= $d ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="ev-switch-row">
                                    <div>
                                        <strong>A certificate for those who finish</strong>
                                        <span>Shown on the initiative. You record how many finished from each place at the end.</span>
                                    </div>
                                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="f_certificate" name="certificate" aria-label="Certificate"></div>
                                </div>
                            </div>
                            <?php } ?>
                            <div class="col-12">
                                <label class="form-label" for="f_description">What it is about</label>
                                <textarea class="form-control" id="f_description" name="description" rows="4" maxlength="5000" placeholder="A few lines the invited places will read"></textarea>
                            </div>
                        </div>
                        <?php } elseif ($n === 2) { ?>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="f_venue">Venue</label>
                                <input type="text" class="form-control" id="f_venue" name="venue" maxlength="160" placeholder="e.g. CCI Wote church grounds">
                            </div>
                            <?php if (! $isInit && $eventsCtx['level'] === 'church' && (hasGlobalAccess() || hasPermission('church.facilities.facilities.book') || hasPermission('church.facilities.facilities.manage'))): ?>
                            <!-- Facilities (P5): book one of our rooms for the event's times - it moves with the event. -->
                            <div class="col-md-8" data-field="room_id">
                                <label class="form-label" for="f_room">Book a room <span class="fw-normal">- optional, for the event's times</span></label>
                                <select class="form-select" id="f_room" name="room_id"><option value="">No room</option></select>
                            </div>
                            <?php endif ?>
                            <div class="col-md-4">
                                <label class="form-label" for="f_capacity"><?= $isInit ? 'Places for (people)' : 'Room for (people)' ?></label>
                                <input type="number" class="form-control" id="f_capacity" name="capacity" min="1" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f_coordinator"><?= $isInit ? 'Facilitator' : 'Coordinator' ?></label>
                                <input type="text" class="form-control" id="f_coordinator" name="coordinator" maxlength="120" placeholder="<?= $isInit ? 'Who leads the sessions' : 'Who to call about it' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f_speakers">Speakers</label>
                                <input type="text" class="form-control" id="f_speakers" name="speakers" maxlength="255" placeholder="e.g. Bishop, Rev. Mutua">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="f_agenda"><?= $isInit ? 'What it covers' : 'Programme' ?></label>
                                <textarea class="form-control" id="f_agenda" name="agenda" rows="3" maxlength="5000" placeholder="<?= $isInit ? 'One topic per line' : 'One item per line' ?>"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-2">Who it is open to <span class="text-danger">*</span></label>
                                <div class="ev-choices" id="f_open_to" role="radiogroup" aria-label="Who it is open to"></div>
                            </div>
                            <div class="col-12" id="inviteesWrap" hidden>
                                <label class="form-label" for="f_invitees">Which places <span class="text-danger">*</span></label>
                                <select class="form-select" id="f_invitees" name="invitees" multiple></select>
                                <div class="form-text">Inviting a region invites every church in it.</div>
                            </div>
                        </div>
                        <?php } elseif ($n === 3) { ?>
                        <div id="regBox">
                            <!-- A toggle card (v1-events' ec-toggle) -->
                            <label class="ec-toggle is-q" for="f_registration" style="--q: var(--success-rgb)">
                                <input type="checkbox" role="switch" id="f_registration" name="registration">
                                <span class="ec-toggle-icon"><i class="ri-clipboard-line"></i></span>
                                <span class="ec-toggle-text">
                                    <strong><?= $isInit ? 'Places join and say how many are taking part' : 'Places register how many are coming' ?></strong>
                                    <small>Each place gives its numbers - youth, adults, children, leaders - not names.</small>
                                </span>
                                <span class="ec-toggle-switch" aria-hidden="true"><i></i></span>
                            </label>
                            <div class="row g-3 mt-1" id="regFields" hidden>
                                <div class="col-md-6">
                                    <label class="form-label" for="f_register_by"><?= $isInit ? 'Join by (optional - else until the last day)' : 'Register by' ?></label>
                                    <input type="date" class="form-control" id="f_register_by" name="register_by">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="f_fee">Fee per person (KES)</label>
                                    <input type="number" class="form-control" id="f_fee" name="fee_per_person" min="0" step="1" placeholder="0 = free">
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-primary d-flex gap-2 mb-3 d-none" id="regOff"><i class="ri-information-line fs-16"></i><span>Only your own place takes part, so there is nothing to register. Open it to other places in step 2 to take registrations.</span></div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label" for="f_planned_income">Income we expect (KES)</label>
                                <input type="number" class="form-control" id="f_planned_income" name="planned_income" min="0" step="1" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f_planned_spend">Expenses we plan (KES)</label>
                                <input type="number" class="form-control" id="f_planned_spend" name="planned_spend" min="0" step="1" placeholder="Optional">
                            </div>
                        </div>
                        <?php } else { ?>
                        <div id="reviewBody"></div>
                        <?php } ?>
                    </div>
                </section>
                <?php } ?>

                <div class="intake-foot">
                    <span class="intake-saved me-auto" id="intakeSaved">Not saved yet</span>
                    <a href="<?= $eventsCtx['baseUrl'] ?>/" class="btn btn-light" id="cancelBtn">Cancel</a>
                    <button type="button" class="btn btn-light" id="backBtn" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                    <button type="button" class="btn btn-outline-primary" id="saveDraftBtn"><i class="ri-draft-line"></i><span class="intake-btn-text ms-1">Save as draft</span></button>
                    <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                    <button type="button" class="btn btn-success" id="publishBtn" hidden><i class="ri-send-plane-line me-1"></i>Save and publish</button>
                </div>
            </form>
        </div>

        <!-- Live preview: how the invited places will see it -->
        <div class="col-lg-4">
            <div class="intake-aside">
                <div class="card custom-card preview-card">
                    <div class="preview-head">
                        <span class="preview-label"><i class="ri-eye-line"></i>How places will see it</span>
                        <span id="previewStatus"></span>
                    </div>
                    <div class="preview-section" id="previewCard"></div>
                </div>
                <div class="card custom-card intake-tips">
                    <strong><span class="ev-tile is-sm text-dark" style="--q: var(--warning-rgb)"><i class="ri-lightbulb-line"></i></span>Good to know</strong>
                    <ul>
                        <li><b>Save as draft</b> keeps it to yourself. Nobody else sees it until you <b>publish</b>.</li>
                        <li>When you publish, the leaders of the places it is open to get a notification in their bell.</li>
                        <?php if ($isInit) { ?>
                        <li>Sessions are made from <b>how often it meets</b>. You can change, add or remove them later, and record attendance at each.</li>
                        <?php } else { ?>
                        <li>Places give <b>numbers, not names</b>. You see who is coming, grouped by region.</li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
