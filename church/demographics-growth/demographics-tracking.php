<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.overview.read');

$canWrite = hasPermission('churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.create')
    || hasPermission('churchdemographicsgrowth.demographicstracking.sundayschoolenrollment.update');

if (!$canWrite) {
    header('Location: ' . SITE_URL . '/errors/403');
    exit;
}

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Demographics Tracking';
$pageIcon = 'ri-edit-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => SITE_URL . '/church/demographics-growth',
    'Demographics Tracking' => null,
];

require __DIR__ . '/../../includes/ui-helpers-templates.php';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Demographics Tracking - Makueni West Diocese</title>
    <meta name="Description" content="Record this month's church demographics" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const EDIT_DEMOGRAPHIC_ID = <?= json_encode(isset($_GET['id']) ? (int) $_GET['id'] : null) ?>;
    </script>
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../includes/header.php' ?>
        <?php include __DIR__ . '/../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../includes/page-header.php' ?>

<?php
// Five short steps, same shape as v1-events-backend's Create Event flow:
// step bar -> one step at a time on the left, a live preview on the right
// -> review with Edit links -> a finish screen. Filled in by
// assets/js/pages/demographics/demographics-tracking.js.
$steps = [
    1 => ['Period', 'Which month you are reporting', 'ri-calendar-2-line'],
    2 => ['Membership', 'Whole-church headcount', 'ri-team-line'],
    3 => ['Groups', 'Fellowships and Sunday school', 'ri-group-line'],
    4 => ['Changes & Holy Communion', 'What happened this period', 'ri-hand-heart-line'],
    5 => ['Review', 'Check and submit', 'ri-checkbox-circle-line'],
];
?>
                <div id="intakeApp">

                    <!-- Unfinished draft from last time -->
                    <div class="intake-banner" id="intakeResume" hidden>
                        <span class="intake-banner-icon bg-primary text-white"><i class="ri-history-line"></i></span>
                        <div class="intake-banner-text">
                            <strong id="intakeResumeTitle">Continue your draft?</strong>
                            <small id="intakeResumeText">You started one earlier.</small>
                        </div>
                        <div class="intake-banner-actions">
                            <button type="button" class="btn btn-sm btn-light" id="intakeResumeNo">Not now</button>
                            <button type="button" class="btn btn-sm btn-primary" id="intakeResumeYes">Continue</button>
                        </div>
                    </div>

                    <!-- Read-only / changes requested notice -->
                    <div class="intake-banner" id="intakeNotice" hidden>
                        <span class="intake-banner-icon" id="intakeNoticeIcon"><i class="ri-lock-line"></i></span>
                        <div class="intake-banner-text">
                            <strong id="intakeNoticeTitle"></strong>
                            <small id="intakeNoticeText"></small>
                        </div>
                        <div class="intake-banner-actions" id="intakeNoticeActions"></div>
                    </div>

                    <!-- Step bar -->
                    <nav class="card custom-card intake-steps" id="intakeSteps" aria-label="Steps">
                        <?php foreach ($steps as $n => [$label, $hint]) : ?>
                            <button type="button" class="intake-step-btn<?= $n === 1 ? ' is-on' : '' ?>" data-go="<?= $n ?>" <?= $n > 1 ? 'disabled' : '' ?>>
                                <span class="intake-step-dot"><span><?= $n ?></span><i class="ri-check-line"></i></span>
                                <span class="intake-step-text"><strong><?= $label ?></strong><small><?= $hint ?></small></span>
                            </button>
                        <?php endforeach ?>
                        <span class="intake-steps-mobile" id="intakeStepsMobile">Step 1 of <?= count($steps) ?> · Period</span>
                        <span class="intake-steps-bar"><i id="intakeStepsBar" style="width: <?= round(100 / count($steps)) ?>%"></i></span>
                    </nav>

                    <div class="row g-4" id="intakeMain">
                        <div class="col-lg-8">
                            <form id="demographicsForm" class="card custom-card intake-form" novalidate autocomplete="off">
                                <?php foreach ($steps as $n => [$label, $hint, $icon]) : ?>
                                    <section class="intake-step" data-step="<?= $n ?>" <?= $n > 1 ? 'hidden' : '' ?>>
                                        <div class="intake-step-head">
                                            <span class="intake-step-num"><?= $n ?></span>
                                            <div>
                                                <h5><?= $label ?></h5>
                                                <p><?= $hint ?></p>
                                            </div>
                                        </div>
                                        <div class="intake-errors" data-errors-for="<?= $n ?>" hidden role="alert"></div>
                                        <div class="intake-step-body">

                                            <?php if ($n === 1) : ?>
                                                <div class="row g-3 align-items-end mb-3">
                                                    <div class="col-sm-6 col-md-5">
                                                        <label class="form-label" for="fiscalYear">Fiscal year</label>
                                                        <select class="form-select" id="fiscalYear" aria-label="Fiscal year">
                                                            <option value="">Loading…</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-6 col-md-7">
                                                        <span class="soft-chip soft-primary intake-cadence" id="cadenceChip"><i class="ri-calendar-2-line"></i>Loading…</span>
                                                    </div>
                                                </div>
                                                <span class="form-label d-block" id="periodLabel">Month</span>
                                                <div class="period-grid" id="periodGrid" role="radiogroup" aria-labelledby="periodLabel">
                                                    <?php for ($i = 0; $i < 6; $i++) : ?><span class="skel period-chip-skel"></span><?php endfor ?>
                                                </div>
                                                <div class="intake-note intake-period-note" id="periodNote" hidden></div>
                                                <div class="period-legend">
                                                    <span><i class="ri-checkbox-circle-fill text-success"></i>Approved</span>
                                                    <span><i class="ri-send-plane-fill text-primary"></i>Submitted</span>
                                                    <span><i class="ri-draft-fill text-warning"></i>Draft</span>
                                                    <span><i class="ri-checkbox-blank-circle-line"></i>Not started</span>
                                                </div>
                                            <?php elseif ($n === 2) : ?>
                                                <div class="intake-prefill" id="prefillBar" hidden>
                                                    <span class="intake-prefill-icon"><i class="ri-history-line"></i></span>
                                                    <span class="intake-prefill-text" id="prefillText"></span>
                                                    <button type="button" class="btn btn-sm btn-primary" id="prefillBtn"><i class="ri-magic-line me-1"></i>Start from these numbers</button>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-12"><?= renderNumberTile('total_members', ['label' => 'Total members', 'required' => true, 'icon' => 'ri-team-line', 'color' => 'primary', 'hint' => 'Everyone in the congregation']) ?></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('male_count', ['label' => 'Male', 'icon' => 'ri-men-line', 'color' => 'info']) ?></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('female_count', ['label' => 'Female', 'icon' => 'ri-women-line', 'color' => 'pink']) ?></div>
                                                    <div class="col-12"><div class="intake-check" id="genderCheck"></div></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('youth_count', ['label' => 'Youth (13-35)', 'icon' => 'ri-user-star-line', 'color' => 'success']) ?></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('seniors_count', ['label' => 'Seniors (60+)', 'icon' => 'ri-user-heart-line', 'color' => 'secondary']) ?></div>
                                                </div>
                                            <?php elseif ($n === 3) : ?>
                                                <div class="intake-group-head">
                                                    <h6>Fellowship groups</h6>
                                                    <span class="soft-chip soft-pink" id="fellowshipSubtotal">Total · <b>0</b></span>
                                                </div>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-sm-6"><?= renderNumberTile('womens_fellowship_count', ['label' => "Women's fellowship", 'icon' => 'ri-women-line', 'color' => 'pink']) ?></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('mens_fellowship_count', ['label' => "Men's fellowship", 'icon' => 'ri-men-line', 'color' => 'info']) ?></div>
                                                </div>
                                                <div class="intake-group-head">
                                                    <h6>Sunday school</h6>
                                                    <span class="soft-chip soft-purple" id="sundaySchoolSubtotal">Children · <b>0</b></span>
                                                </div>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('sunday_school_male_count', ['label' => 'Boys', 'icon' => 'ri-book-read-line', 'color' => 'purple']) ?></div>
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('sunday_school_female_count', ['label' => 'Girls', 'icon' => 'ri-book-read-line', 'color' => 'purple']) ?></div>
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('sunday_school_teachers_count', ['label' => 'Teachers', 'icon' => 'ri-user-voice-line', 'color' => 'primary']) ?></div>
                                                </div>
                                                <div class="intake-group-head">
                                                    <h6>Pastors</h6>
                                                    <span class="intake-group-note">From staff records, not entered here</span>
                                                </div>
                                                <div class="d-flex flex-wrap gap-2" id="clergyChips"><span class="skel" style="width: 160px; height: 1.6rem;"></span></div>
                                            <?php elseif ($n === 4) : ?>
                                                <div class="intake-group-head">
                                                    <h6>Membership changes</h6>
                                                    <span class="intake-group-note">Leave empty if nothing to report</span>
                                                </div>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-sm-6"><?= renderNumberTile('new_members_count', ['label' => 'New members', 'icon' => 'ri-user-add-line', 'color' => 'success']) ?></div>
                                                    <div class="col-sm-6"><?= renderNumberTile('transferred_out_count', ['label' => 'Transferred out', 'icon' => 'ri-user-unfollow-line', 'color' => 'danger']) ?></div>
                                                </div>
                                                <div class="intake-group-head">
                                                    <h6>Baptisms, Holy Communion &amp; conversions</h6>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('baptisms_count', ['label' => 'Baptisms', 'icon' => 'ri-drop-line', 'color' => 'primary']) ?></div>
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('communion_participants_count', ['label' => 'Holy Communion', 'icon' => 'ri-cup-line', 'color' => 'secondary']) ?></div>
                                                    <div class="col-sm-6 col-xl-4"><?= renderNumberTile('conversions_count', ['label' => 'Conversions', 'icon' => 'ri-heart-line', 'color' => 'purple']) ?></div>
                                                </div>
                                            <?php else : ?>
                                                <div id="reviewWarnings"></div>
                                                <div class="review-groups" id="reviewGroups"></div>
                                                <div class="intake-note">
                                                    <i class="ri-flag-line"></i>
                                                    <span>Submitting sends it to the diocese for <b>approval</b>. You can't change it after that unless they ask for changes.</span>
                                                </div>
                                            <?php endif ?>

                                        </div>
                                    </section>
                                <?php endforeach ?>

                                <div class="intake-foot">
                                    <span class="intake-saved" id="intakeSaved">Not saved yet</span>
                                    <button type="button" class="btn btn-outline-primary" id="saveDraftBtn" title="Save draft"><i class="ri-save-line"></i><span class="intake-btn-text ms-1">Save draft</span></button>
                                    <button type="button" class="btn btn-light" id="backBtn" title="Back" hidden><i class="ri-arrow-left-line"></i><span class="intake-btn-text ms-1">Back</span></button>
                                    <button type="button" class="btn btn-outline-success" id="toReviewBtn" title="Back to review" hidden><i class="ri-checkbox-circle-line"></i><span class="intake-btn-text ms-1">Back to review</span></button>
                                    <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
                                    <button type="button" class="btn btn-success" id="submitBtn" hidden><i class="ri-send-plane-line me-1"></i>Submit for review</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4">
                            <div class="intake-aside">
                                <div class="card custom-card preview-card">
                                    <div class="preview-head">
                                        <span class="preview-label"><i class="ri-eye-line"></i>Live preview</span>
                                        <span id="previewStatus"></span>
                                    </div>
                                    <div class="preview-period" id="previewPeriod">Pick a period</div>
                                    <div class="preview-progress">
                                        <span id="previewFilled">0 of 15 filled</span>
                                        <span class="preview-progress-bar"><i id="previewFilledBar" style="width: 0%"></i></span>
                                    </div>
                                    <div class="preview-section" id="previewComposition"></div>
                                    <div class="preview-section">
                                        <div class="preview-section-title">Men and women</div>
                                        <div id="previewGender"></div>
                                    </div>
                                    <div class="preview-section">
                                        <div class="preview-section-title">This period</div>
                                        <div class="preview-changes" id="previewChanges"></div>
                                    </div>
                                </div>

                                <div class="card custom-card intake-tips">
                                    <strong><i class="ri-lightbulb-line"></i>Good to know</strong>
                                    <ul>
                                        <li><b>Save draft</b> any time after picking the period and entering total members, then finish later from any device.</li>
                                        <li>Male + Female should add up to Total members. Fellowships and Sunday school are groups inside that total.</li>
                                        <li>Nothing is final until the diocese approves it.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Finish -->
                    <div class="card custom-card intake-done" id="intakeDone" hidden>
                        <div class="intake-done-top">
                            <span class="intake-done-icon bg-success text-white"><i class="ri-check-line"></i></span>
                            <h3 id="intakeDoneTitle">Submitted for review</h3>
                            <p id="intakeDoneText">The diocese will review it. You'll see it under Growth Overview → History.</p>
                        </div>
                        <span class="intake-done-label">What next?</span>
                        <div class="intake-next">
                            <a class="intake-next-card" id="doneView" href="#">
                                <span class="intake-next-icon bg-primary text-white"><i class="ri-file-chart-2-line"></i></span>
                                <span><strong>View this submission</strong><small>The report as the diocese sees it.</small></span>
                                <i class="ri-arrow-right-line"></i>
                            </a>
                            <a class="intake-next-card" href="index.php">
                                <span class="intake-next-icon bg-purple text-white"><i class="ri-line-chart-line"></i></span>
                                <span><strong>Growth overview</strong><small>Trends, groups and reporting status.</small></span>
                                <i class="ri-arrow-right-line"></i>
                            </a>
                            <a class="intake-next-card" href="../attendance/index.php">
                                <span class="intake-next-icon bg-success text-white"><i class="ri-calendar-check-line"></i></span>
                                <span><strong>Record attendance</strong><small>Services, ministries and events.</small></span>
                                <i class="ri-arrow-right-line"></i>
                            </a>
                            <a class="intake-next-card" href="demographics-tracking.php">
                                <span class="intake-next-icon bg-secondary text-dark"><i class="ri-add-line"></i></span>
                                <span><strong>Start another period</strong><small>Record a different month.</small></span>
                                <i class="ri-arrow-right-line"></i>
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../includes/footer.php' ?>
    </div>

    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js<?= assetVersion('assets/js/utils/toast.js') ?>"></script>

    <!-- jQuery + Select2 (fiscal year picker) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/demographics-tracking.js<?= assetVersion('assets/js/pages/demographics/demographics-tracking.js') ?>"></script>
    <!-- "From your register: N" beside the matching boxes (Members, docs/specs/people-and-care-spec.md) - a hint, never filled in by itself -->
    <script src="<?= SITE_URL ?>/assets/js/pages/members/register-hint.js<?= assetVersion('assets/js/pages/members/register-hint.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.DemographicsTracking.init());
    </script>
</body>

</html>
