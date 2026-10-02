<?php
// Settings hub - one page per level (docs/specs/settings-spec.md). The wrapper sets $settingsCtx (includes/settings/context.php).
// The rail and each section are drawn by assets/js/pages/settings/*.js from GET /settings/*.
$pageTitle = 'Settings';
$pageIcon = 'ri-settings-4-line';
$breadcrumbs = [
    'Home' => $settingsCtx['homeUrl'],
    'Settings' => null,
];
$settingsShell = ['level' => $settingsCtx['level'], 'active' => $settingsCtx['active'], 'hubUrl' => $settingsCtx['hubUrl']];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Settings - Makueni West Diocese</title>
    <meta name="Description" content="Your place's profile, service times and setup, in one place" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php settingsPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>window.SETTINGS_CTX = <?= json_encode($settingsCtx) ?>;</script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../page-header.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub" id="placeLine">Settings for <?= htmlspecialchars($settingsCtx['place']['name'] ?: 'your place') ?></div>
                </div>

                <?php include __DIR__ . '/shell-start.php' ?>

                <header class="settings-section-head" id="settingsHead" aria-live="polite">
                    <span class="settings-section-icon bg-primary text-white"><i class="ri-dashboard-3-line"></i></span>
                    <div>
                        <h2 class="settings-section-title" id="settingsTitle">Overview</h2>
                        <p class="settings-section-sentence mb-0" id="settingsSentence">&nbsp;</p>
                    </div>
                </header>

                <div id="settingsBody"></div>

                <div class="settings-save-bar" id="settingsSaveBar" hidden>
                    <span class="settings-save-count" id="settingsSaveCount"><i class="ri-edit-circle-line me-1"></i>Unsaved changes</span>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-light border" id="settingsDiscardBtn">Discard</button>
                        <button type="button" class="btn btn-primary" id="settingsSaveBtn"><i class="ri-check-line me-1"></i>Save changes</button>
                    </div>
                </div>

                <?php include __DIR__ . '/shell-end.php' ?>
            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php settingsPageScripts() ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.SettingsHub.init());</script>
</body>

</html>
