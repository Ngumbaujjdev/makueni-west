<?php
// Visitors - visitors and their follow-up (docs/specs/people-and-care-spec.md, P2). The wrapper sets
// $visitorsCtx (includes/visitors/context.php); the body for each page is includes/visitors/body-{page}.php.
$titles = [
    'list' => 'Visitors', 'new' => 'Record visitors', 'visitor' => 'Visitor',
    'insights' => 'Visitor insights', 'totals' => 'Visitors in our churches',
];
$pageTitle = $titles[$visitorsCtx['page']];
$pageIcon = 'ri-user-heart-line';
$breadcrumbs = in_array($visitorsCtx['page'], ['list', 'totals'], true)
    ? ['Home' => $visitorsCtx['homeUrl'], $pageTitle => null]
    : ['Home' => $visitorsCtx['homeUrl'], 'Visitors' => $visitorsCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Our visitors and their follow-up - only our leaders see names" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php visitorsPageStyles($visitorsCtx['page']) ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.VISITORS_CTX = <?= json_encode($visitorsCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $visitorsCtx['place']['id'], 'name' => $visitorsCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$visitorsCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php visitorsPageScripts($visitorsCtx['page']) ?>
</body>

</html>
