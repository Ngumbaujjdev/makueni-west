<?php
/**
 * Shared page body for Ministry Gatherings and Special Events
 * (church/attendance/ministries.php, events.php). The including page does
 * the permission check and sets: $gatheringPage (slug, noun, pluralNoun,
 * plural, categoryLabel, icon), $cardsTitle, $toolbarText, $canWrite,
 * $userTerritoryId/$userTerritoryName, $pageTitle/$pageIcon/$breadcrumbs.
 * Driven by assets/js/pages/demographics/attendance-gatherings.js.
 */
if (!isset($gatheringPage)) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="<?= htmlspecialchars($toolbarText) ?>" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_WRITE_ATTENDANCE = <?= $canWrite ? 'true' : 'false' ?>;
        window.GATHERING_PAGE = <?= json_encode($gatheringPage) ?>;
    </script>
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/start-switcher.php' ?>
    <?php include __DIR__ . '/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/header.php' ?>
        <?php include __DIR__ . '/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/page-header.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub"><?= htmlspecialchars($toolbarText) ?></div>
                    <div class="page-toolbar-controls">
                        <?php if ($canWrite): ?>
                        <button type="button" class="btn btn-primary" id="addEntryBtn"><i class="ri-add-line me-1"></i>Record <?= htmlspecialchars(strtolower($gatheringPage['categoryLabel'])) ?></button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row" id="statCardsRow"></div>

                <div class="card custom-card">
                    <div class="card-header">
                        <div>
                            <div class="card-title"><?= htmlspecialchars($cardsTitle) ?></div>
                            <span class="card-subtitle-text" id="gatheringCardsSubtitle"></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3" id="gatheringCards">
                            <div class="col-xxl-3 col-xl-4 col-md-6"><span class="skel gathering-card-skel"></span></div>
                            <div class="col-xxl-3 col-xl-4 col-md-6"><span class="skel gathering-card-skel"></span></div>
                            <div class="col-xxl-3 col-xl-4 col-md-6"><span class="skel gathering-card-skel"></span></div>
                            <div class="col-xxl-3 col-xl-4 col-md-6"><span class="skel gathering-card-skel"></span></div>
                        </div>
                    </div>
                </div>

                <div class="card custom-card" id="recordsCard">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Records</div>
                            <span class="card-subtitle-text">Every <?= htmlspecialchars(strtolower($gatheringPage['categoryLabel'])) ?> recorded, newest first</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="filterToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="attendanceTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th><?= htmlspecialchars($gatheringPage['noun']) ?></th>
                                        <th>Attendance</th>
                                        <th class="d-none d-lg-table-cell">Notes</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="attendanceTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/footer.php' ?>
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
    <script src="<?= SITE_URL ?>/assets/libs/apexcharts/apexcharts.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/libs/select2/select2.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js<?= assetVersion('assets/js/pages/demographics/api-handler.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js<?= assetVersion('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-form-shared.js<?= assetVersion('assets/js/pages/demographics/attendance-form-shared.js') ?>"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-gatherings.js<?= assetVersion('assets/js/pages/demographics/attendance-gatherings.js') ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceGatherings.init());
    </script>
</body>

</html>
