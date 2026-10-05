<?php
// Monthly reports - one set of pages per level (docs/specs/monthly-reports-spec.md). The wrapper sets
// $reportsCtx (includes/monthly-reports/context.php); the body is includes/monthly-reports/body-{index,report}.php.
$pageTitle = $reportsCtx['page'] === 'index' ? 'Monthly reports' : 'Monthly report';
$pageIcon = 'ri-file-chart-line';
$breadcrumbs = $reportsCtx['page'] === 'index'
    ? ['Home' => $reportsCtx['homeUrl'], 'Monthly reports' => null]
    : ['Home' => $reportsCtx['homeUrl'], 'Monthly reports' => $reportsCtx['baseUrl'] . '/', 'Report' => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Each month's report: the figures filled in, the pastor's words, sent to the place above" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php reportsPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.REPORTS_CTX = <?= json_encode($reportsCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $reportsCtx['place']['id'], 'name' => $reportsCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$reportsCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php reportsPageScripts($reportsCtx['page']) ?>
</body>

</html>
