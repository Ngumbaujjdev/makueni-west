<?php
// Pastoral care (docs/specs/people-and-care-spec.md, P3). The wrapper sets
// $careCtx (includes/care/context.php); the body for each page is includes/care/body-{page}.php.
$titles = [
    'index' => 'Pastoral care', 'log' => 'Care log', 'case' => 'Care', 'hospital' => 'In hospital',
    'prayer' => 'Prayer', 'totals' => 'Pastoral care in our churches',
];
$pageTitle = $titles[$careCtx['page']];
$pageIcon = 'ri-heart-pulse-line';
$breadcrumbs = in_array($careCtx['page'], ['index', 'totals'], true)
    ? ['Home' => $careCtx['homeUrl'], $pageTitle => null]
    : ['Home' => $careCtx['homeUrl'], 'Pastoral care' => $careCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Our pastoral care - only our leaders see names and notes" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php carePageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.CARE_CTX = <?= json_encode($careCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $careCtx['place']['id'], 'name' => $careCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$careCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php carePageScripts($careCtx['page']) ?>
</body>

</html>
