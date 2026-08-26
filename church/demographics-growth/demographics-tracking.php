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
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Demographics Tracking - Makueni West Diocese</title>
    <meta name="Description" content="Record this month's church demographics" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= asset_url('/assets/js/main.js') ?>"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= asset_url('/assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= asset_url('/assets/css/icons.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <style>
        .stepper-group .form-control { max-width: 100px; }
        .sticky-action-bar {
            position: sticky;
            bottom: 0;
            background: var(--custom-white, #fff);
            border-top: 1px solid var(--default-border, #e9ecef);
            padding: 1rem 1.5rem;
            margin: 0 -1.5rem -1.5rem -1.5rem;
            z-index: 10;
        }
    </style>

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const EDIT_DEMOGRAPHIC_ID = <?= json_encode(isset($_GET['id']) ? (int) $_GET['id'] : null) ?>;
    </script>
</head>

<body>
    <script src="<?= asset_url('/assets/js/config/app.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>
    <?php include __DIR__ . '/../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../includes/header.php' ?>
        <?php include __DIR__ . '/../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../includes/page-header.php' ?>

                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header d-sm-flex d-block">
                                <ul class="nav nav-tabs nav-tabs-header mb-0 d-sm-flex d-block" role="tablist">
                                    <li class="nav-item m-1">
                                        <a class="nav-link active" data-bs-toggle="tab" role="tab"
                                            href="#step1-membership" aria-selected="true" id="step1Tab">
                                            <i class="ri-team-line me-2"></i>Membership Counts
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab"
                                            href="#step2-activities" aria-selected="false" id="step2Tab">
                                            <i class="ri-hand-heart-line me-2"></i>Changes & Spiritual Activities
                                        </a>
                                    </li>
                                    <li class="nav-item m-1">
                                        <a class="nav-link" data-bs-toggle="tab" role="tab"
                                            href="#step3-review" aria-selected="false" id="step3Tab">
                                            <i class="ri-checkbox-circle-line me-2"></i>Review & Submit
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">

                                <?= renderCompletenessBar('formCompleteness') ?>

                                <form id="demographicsForm">
                                    <div class="tab-content">

                                        <!-- Step 1: Membership Counts -->
                                        <div class="tab-pane show active" id="step1-membership" role="tabpanel">
                                            <div class="alert alert-info mb-4">
                                                <i class="ri-information-line me-2"></i>
                                                Select the reporting period, then enter this month's membership counts. Use the +/- steppers or type directly.
                                            </div>

                                            <div class="row gy-3 mb-4">
                                                <div class="col-md-6">
                                                    <label for="fiscalYear" class="form-label">Fiscal Year <span class="text-danger">*</span></label>
                                                    <select class="form-select" id="fiscalYear" required>
                                                        <option value="">Loading fiscal years...</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="fiscalMonth" class="form-label">Month <span class="text-danger">*</span></label>
                                                    <select class="form-select" id="fiscalMonth" required disabled>
                                                        <option value="">Select fiscal year first</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="card border shadow-none mb-3">
                                                <div class="card-body">
                                                    <h6 class="fw-semibold mb-3"><i class="ri-team-line me-2 text-primary"></i>Overall Membership</h6>
                                                    <div class="row gy-3">
                                                        <div class="col-md-4"><?= renderStepper('total_members', ['label' => 'Total Members', 'required' => true, 'hint' => 'Everyone currently on this church\'s membership roll']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('male_count', ['label' => 'Male', 'hint' => 'Gender breakdown of the total above']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('female_count', ['label' => 'Female', 'hint' => 'Gender breakdown of the total above']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('youth_count', ['label' => "Youth (13-35)", 'hint' => 'Members aged 13 to 35']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('seniors_count', ['label' => 'Seniors', 'hint' => 'Members aged 60 and above']) ?></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="card border shadow-none mb-0">
                                                <div class="card-body">
                                                    <h6 class="fw-semibold mb-3"><i class="ri-group-2-line me-2 text-primary"></i>Fellowship & Sunday School</h6>
                                                    <div class="row gy-3">
                                                        <div class="col-md-4"><?= renderStepper('womens_fellowship_count', ['label' => "Women's Fellowship", 'hint' => 'Active members attending this fellowship group']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('mens_fellowship_count', ['label' => "Men's Fellowship", 'hint' => 'Active members attending this fellowship group']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('sunday_school_male_count', ['label' => 'Sunday School (Male)', 'hint' => 'Children enrolled in Sunday school, by gender']) ?></div>
                                                        <div class="col-md-4"><?= renderStepper('sunday_school_female_count', ['label' => 'Sunday School (Female)', 'hint' => 'Children enrolled in Sunday school, by gender']) ?></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div id="step1Warnings" class="mt-3"></div>

                                            <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                                                <button type="button" class="btn btn-primary" id="nextToStep2">
                                                    Next: Changes & Activities <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Step 2: This Month's Changes & Spiritual Activities -->
                                        <div class="tab-pane" id="step2-activities" role="tabpanel">
                                            <div class="alert alert-info mb-4">
                                                <i class="ri-information-line me-2"></i>
                                                Optional - leave at 0 if nothing to report this month.
                                            </div>

                                            <h6 class="fw-semibold mb-3">This Month's Changes</h6>
                                            <div class="row gy-3 mb-4">
                                                <div class="col-md-6"><?= renderStepper('new_members_count', ['label' => 'New Members', 'hint' => 'People who joined this church this month']) ?></div>
                                                <div class="col-md-6"><?= renderStepper('transferred_out_count', ['label' => 'Transferred Out', 'hint' => 'Members who left for another church this month']) ?></div>
                                            </div>

                                            <h6 class="fw-semibold mb-3">Spiritual Activities</h6>
                                            <div class="row gy-3">
                                                <div class="col-md-4"><?= renderStepper('baptisms_count', ['label' => 'Baptisms', 'hint' => 'Baptisms performed this month']) ?></div>
                                                <div class="col-md-4"><?= renderStepper('communion_participants_count', ['label' => 'Communion Participants', 'hint' => 'People who took communion this month']) ?></div>
                                                <div class="col-md-4"><?= renderStepper('conversions_count', ['label' => 'New Conversions', 'hint' => 'New professions of faith this month']) ?></div>
                                            </div>

                                            <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary" id="backToStep1">
                                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                                </button>
                                                <button type="button" class="btn btn-primary" id="nextToStep3">
                                                    Next: Review & Submit <i class="ri-arrow-right-line ms-1"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Step 3: Review & Submit -->
                                        <div class="tab-pane" id="step3-review" role="tabpanel">
                                            <div class="alert alert-success mb-4">
                                                <i class="ri-checkbox-circle-line me-2"></i>
                                                Review everything below before submitting.
                                            </div>

                                            <div id="reviewWarnings"></div>

                                            <div class="card border mb-3">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 fw-semibold"><i class="ri-team-line me-2"></i>Membership Counts</h6>
                                                </div>
                                                <div class="card-body" id="reviewMembership"></div>
                                            </div>

                                            <div class="card border mb-3">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 fw-semibold"><i class="ri-hand-heart-line me-2"></i>Changes & Spiritual Activities</h6>
                                                </div>
                                                <div class="card-body" id="reviewActivities"></div>
                                            </div>

                                            <div class="mt-4 pt-3 border-top d-flex justify-content-start">
                                                <button type="button" class="btn btn-secondary" id="backToStep2">
                                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                                </button>
                                            </div>
                                        </div>

                                    </div>
                                </form>

                                <!-- Sticky action bar: visible on every step -->
                                <div class="sticky-action-bar d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span class="fs-12 text-body fw-semibold" id="draftStatusLabel">Not saved yet</span>
                                    <div>
                                        <button type="button" class="btn btn-outline-primary me-2" id="saveDraftBtn">
                                            <i class="ri-save-line me-1"></i>Save Draft
                                        </button>
                                        <button type="button" class="btn btn-primary" id="submitBtn">
                                            <i class="ri-send-plane-line me-1"></i>Submit
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent submissions -->
                <div class="card custom-card mt-3">
                    <div class="card-header">
                        <div class="card-title"><i class="ri-history-line me-2 text-primary"></i>Recent Submissions</div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="fw-semibold text-dark">Period</th>
                                        <th class="fw-semibold text-dark">Total Members</th>
                                        <th class="fw-semibold text-dark">Status</th>
                                        <th class="fw-semibold text-dark text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="recentSubmissionsBody">
                                    <!-- Rows injected by demographics-tracking.js -->
                                </tbody>
                            </table>
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
    <script src="<?= asset_url('/assets/js/defaultmenu.min.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= asset_url('/assets/js/sticky.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= asset_url('/assets/js/simplebar.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/custom-switcher.min.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/custom.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/toast.js') ?>"></script>

    <script src="<?= asset_url('/assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/pages/demographics/demographics-tracking.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.DemographicsTracking.init());
    </script>
</body>

</html>
