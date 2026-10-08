<?php
// Messages - one set of pages per level (docs/specs/messages-spec.md). The wrapper sets
// $messagesCtx (includes/messages/context.php); the body is includes/messages/body-{index,new,message}.php.
$pageTitle = ['index' => 'Inbox', 'new' => 'Send a message', 'message' => 'Message', 'campaigns' => 'Campaigns', 'templates' => 'Templates', 'log' => 'Message log'][$messagesCtx['page']];
$pageIcon = 'ri-chat-3-line';
$breadcrumbs = $messagesCtx['page'] === 'index'
    ? ['Home' => $messagesCtx['homeUrl'], 'Messages' => null]
    : ['Home' => $messagesCtx['homeUrl'], 'Messages' => $messagesCtx['baseUrl'] . '/', $pageTitle => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="Messages to your leaders and the places below, the Inbox and replies" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php messagesPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.MESSAGES_CTX = <?= json_encode($messagesCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $messagesCtx['place']['id'], 'name' => $messagesCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$messagesCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php messagesPageScripts($messagesCtx['page']) ?>
</body>

</html>
