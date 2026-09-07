<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('churchdemographicsgrowth.spiritualactivities.baptismrecords.read');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Spiritual Activities';
$pageIcon = 'ri-heart-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Demographics & Growth' => SITE_URL . '/church/demographics-growth',
    'Spiritual Activities' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Spiritual Activities - Makueni West Diocese</title>
    <meta name="Description" content="Baptisms, communion, new converts, and departures - year trend" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
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

                <!-- Fiscal year filter -->
                <div class="d-flex justify-content-end mb-3">
                    <select class="form-select form-select-sm" style="max-width: 160px;" id="reportFiscalYear">
                        <option value="">Loading years...</option>
                    </select>
                </div>

                <!-- Tab bar -->
                <ul class="nav nav-tabs nav-tabs-pill mb-3" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-baptisms_count-btn" data-bs-toggle="tab" data-bs-target="#tab-baptisms_count" type="button" role="tab">
                            <i class="ri-drop-line me-1"></i>Baptisms
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-communion_participants_count-btn" data-bs-toggle="tab" data-bs-target="#tab-communion_participants_count" type="button" role="tab">
                            <i class="ri-cup-line me-1"></i>Communion
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-conversions_count-btn" data-bs-toggle="tab" data-bs-target="#tab-conversions_count" type="button" role="tab">
                            <i class="ri-user-add-line me-1"></i>New Converts
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-transferred_out_count-btn" data-bs-toggle="tab" data-bs-target="#tab-transferred_out_count" type="button" role="tab">
                            <i class="ri-user-unfollow-line me-1"></i>Departures
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <?php
                    $tabs = [
                        'baptisms_count' => 'Baptisms',
                        'communion_participants_count' => 'Communion',
                        'conversions_count' => 'New Converts',
                        'transferred_out_count' => 'Departures',
                    ];
                    $first = true;
                    foreach ($tabs as $metric => $label):
                    ?>
                    <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="tab-<?= $metric ?>" role="tabpanel">
                        <div class="row g-3 mb-3" id="<?= $metric ?>CardsRow"></div>
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title"><i class="ri-line-chart-line me-2 text-primary"></i><?= $label ?> Trend</div>
                            </div>
                            <div class="card-body">
                                <div id="<?= $metric ?>Chart"></div>
                            </div>
                        </div>
                    </div>
                    <?php $first = false; endforeach; ?>
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
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/spiritual-activities.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.SpiritualActivities.init());
    </script>
</body>

</html>
