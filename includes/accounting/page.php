<?php
// Accounting - one set of pages for every level (docs/specs/accounting-spec.md). The wrapper sets
// $accCtx (includes/accounting/context.php); the body is includes/accounting/body-{page}.php.
$titles = [
    'index' => 'Accounting', 'accounts' => 'Cash & bank', 'cashbook' => 'Cashbook', 'receipts' => 'Receipts',
    'payments' => 'Payment vouchers', 'journals' => 'Journals', 'documents' => 'All documents', 'chart' => 'Chart of accounts',
    'reconciliation' => 'Reconciliation', 'reconcile' => 'Reconcile', 'close' => 'Month-end close', 'collections' => 'Collections',
];
$pageTitle = $titles[$accCtx['page']];
$pageIcon = 'ri-bank-line';
$breadcrumbs = match ($accCtx['page']) {
    'index' => ['Home' => $accCtx['homeUrl'], 'Accounting' => null],
    'reconcile' => ['Home' => $accCtx['homeUrl'], 'Accounting' => $accCtx['baseUrl'] . '/', 'Reconciliation' => $accCtx['baseUrl'] . '/reconciliation.php', $pageTitle => null],
    default => ['Home' => $accCtx['homeUrl'], 'Accounting' => $accCtx['baseUrl'] . '/', $pageTitle => null],
};
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title><?= htmlspecialchars($pageTitle) ?> - Makueni West Diocese</title>
    <meta name="Description" content="The real money: receipts, payments, cash and bank, the cashbook" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php accountingPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        window.ACC_CTX = <?= json_encode($accCtx) ?>;
        const USER_TERRITORY = <?= json_encode(['id' => $accCtx['place']['id'], 'name' => $accCtx['place']['name']]) ?>;
    </script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">
                <?php include __DIR__ . '/../page-header.php' ?>
                <?php include __DIR__ . "/body-{$accCtx['page']}.php" ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php accountingPageScripts($accCtx['page']) ?>
</body>

</html>
