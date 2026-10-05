<?php
// Notifications - every signed-in person's own (docs/specs/events-initiatives-spec.md, foundation).
// Drawn by assets/js/pages/notifications.js from GET /notifications; no permission beyond being signed in.
require_once __DIR__ . '/includes/session-manager.php';
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/permission-check.php';

$role = getCurrentRole() ?? [];
$level = $role['territory_type'] ?? ($role['territory']['territory_type'] ?? 'church');
$pageTitle = 'Notifications';
$pageIcon = 'ri-notification-3-line';
$breadcrumbs = ['Home' => SITE_URL . '/' . (in_array($level, ['church', 'region', 'diocese'], true) ? $level : 'church') . '/dashboard', 'Notifications' => null];
$v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Notifications - Makueni West Diocese</title>
    <meta name="Description" content="Invitations, reports, messages and reminders for you" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/select2/select2.min.css" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />
    <link href="<?= $v('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/includes/start-switcher.php' ?>
    <?php include __DIR__ . '/includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/includes/header.php' ?>
        <?php include __DIR__ . '/includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/includes/page-header.php' ?>
                <div class="page-toolbar">
                    <div class="page-toolbar-sub">Invitations, reports, messages and reminders for you</div>
                    <div class="page-toolbar-controls">
                        <button type="button" class="btn btn-primary" id="pageReadAll"><i class="ri-check-double-line me-1"></i>Mark all read</button>
                    </div>
                </div>
                <div class="row" id="notifStats"></div>
                <div class="card custom-card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Your notifications</div>
                            <span class="card-subtitle-text">Newest first - open one to go to what it's about</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="notifToolbar" class="list-filterbar-wrap"></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="notifTable">
                                <thead><tr><th>When</th><th class="all">Notification</th><th>Kind</th><th>State</th><th class="text-end all"></th></tr></thead>
                                <tbody id="notifBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/includes/footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php foreach (['assets/libs/@popperjs/core/umd/popper.min.js', 'assets/libs/bootstrap/js/bootstrap.bundle.min.js', 'assets/js/defaultmenu.min.js', 'assets/libs/node-waves/waves.min.js', 'assets/js/sticky.js', 'assets/libs/simplebar/simplebar.min.js', 'assets/js/simplebar.js', 'assets/js/custom-switcher.min.js', 'assets/js/custom.js'] as $src): ?>
    <script src="<?= SITE_URL ?>/<?= $src ?>"></script>
    <?php endforeach ?>
    <script src="<?= $v('assets/js/utils/toast.js') ?>"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <?php foreach (['assets/libs/select2/select2.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js', 'assets/libs/apexcharts/apexcharts.min.js'] as $src): ?>
    <script src="<?= SITE_URL ?>/<?= $src ?>"></script>
    <?php endforeach ?>
    <script src="<?= $v('assets/js/pages/demographics/ui-helpers.js') ?>"></script>
    <script src="<?= $v('assets/js/pages/notifications.js') ?>"></script>
</body>

</html>
