<?php
// Facilities (docs/specs/people-and-care-spec.md, P5). The wrapper sets
// $facCtx (includes/facilities/context.php); the body for each page is includes/facilities/body-{page}.php.
$titles = [
    'index' => 'Facilities', 'bookings' => 'Bookings', 'equipment' => 'Equipment', 'item' => 'Equipment', 'assets' => 'What we own', 'reports' => 'Asset reports',
    'repairs' => 'Repairs', 'rota' => 'Duty rota',
];
$pageTitle = $titles[$facCtx['page']];
$pageIcon = 'ri-building-2-line';
$breadcrumbs = $facCtx['page'] === 'index'
    ? ['Home' => $facCtx['homeUrl'], $pageTitle => null]
    : ['Home' => $facCtx['homeUrl'], 'Facilities' => $facCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Our rooms, equipment, repairs and duty rota" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php facilitiesPageStyles($facCtx["page"]) ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.FAC_CTX = <?= json_encode($facCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $facCtx['place']['id'], 'name' => $facCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$facCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php facilitiesPageScripts($facCtx['page']) ?>
</body>

</html>
