<?php
// Events - one set of pages per level (docs/specs/events-initiatives-spec.md). The wrapper sets $eventsCtx
// (includes/events/context.php); the body for each page is includes/events/body-{list,form,event}.php.
$isInitiative = $eventsCtx['kind'] === 'initiative';
$noun = $isInitiative ? ['one' => 'initiative', 'One' => 'Initiative', 'Many' => 'Initiatives'] : ['one' => 'event', 'One' => 'Event', 'Many' => 'Events'];
$titles = ['list' => $noun['Many'], 'form' => (isset($_GET['id']) ? 'Edit ' : 'New ') . $noun['one'], 'event' => $noun['One']];
$pageTitle = $titles[$eventsCtx['page']];
$pageIcon = $isInitiative ? 'ri-seedling-line' : 'ri-calendar-check-line';
$breadcrumbs = $eventsCtx['page'] === 'list'
    ? ['Home' => $eventsCtx['homeUrl'], $noun['Many'] => null]
    : ['Home' => $eventsCtx['homeUrl'], $noun['Many'] => $eventsCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="<?= $isInitiative ? 'Initiatives of this place, their sessions, and the places taking part' : 'Events of this place, invitations from above, and who is coming' ?>" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php eventsPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.EVENTS_CTX = <?= json_encode($eventsCtx) ?>;
        window.BUDGET_CTX = window.EVENTS_CTX.budget;
        const USER_TERRITORY = <?= json_encode(['id' => $eventsCtx['place']['id'], 'name' => $eventsCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$eventsCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php eventsPageScripts($eventsCtx['page']) ?>
</body>

</html>
