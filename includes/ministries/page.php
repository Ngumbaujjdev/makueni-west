<?php
// Ministries (docs/specs/people-and-care-spec.md, P4). The wrapper sets
// $minCtx (includes/ministries/context.php); the body for each page is includes/ministries/body-{page}.php.
$titles = [
    'index' => 'Ministries', 'ministry' => 'Ministry', 'insights' => 'Ministry insights',
    'totals' => 'Ministries in our churches',
];
$pageTitle = $titles[$minCtx['page']];
$pageIcon = 'ri-team-line';
$breadcrumbs = in_array($minCtx['page'], ['index', 'totals'], true)
    ? ['Home' => $minCtx['homeUrl'], $pageTitle => null]
    : ['Home' => $minCtx['homeUrl'], 'Ministries' => $minCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Our ministries - only our leaders see who serves in them" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php ministriesPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.MIN_CTX = <?= json_encode($minCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $minCtx['place']['id'], 'name' => $minCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$minCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php ministriesPageScripts($minCtx['page']) ?>
</body>

</html>
