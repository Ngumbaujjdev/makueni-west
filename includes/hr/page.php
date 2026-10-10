<?php
// Staff - the same pages for every level (docs/specs/hr-spec.md). The wrapper sets $hrCtx
// (includes/hr/context.php); the body is includes/hr/body-{page}.php.
$pageTitle = $hrCtx['page'] === 'positions' ? 'Positions & pay' : 'Staff';
$pageIcon = 'ri-team-line';
$breadcrumbs = $hrCtx['page'] === 'positions'
    ? ['Home' => $hrCtx['homeUrl'], 'Staff' => $hrCtx['baseUrl'] . '/', $pageTitle => null]
    : ['Home' => $hrCtx['homeUrl'], 'Staff' => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="The people we employ, and the positions and pay each level sets up" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php hrPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body class="acc-body">
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.HR_CTX = <?= json_encode($hrCtx) ?>;
        // The Accounting kit reads its place from here.
        window.ACC_CTX = { siteUrl: HR_CTX.siteUrl, baseUrl: HR_CTX.baseUrl, place: HR_CTX.place, level: HR_CTX.level, can: { below: false } };
        const USER_TERRITORY = <?= json_encode(['id' => $hrCtx['place']['id'], 'name' => $hrCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid acc-page hr-page-<?= $hrCtx['page'] ?>">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$hrCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php hrPageScripts($hrCtx['page']) ?>
</body>

</html>
