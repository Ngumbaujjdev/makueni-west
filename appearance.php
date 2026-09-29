<?php
require_once __DIR__ . '/includes/session-manager.php';
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/permission-check.php';
// Account page - every signed-in user manages their own appearance, at
// every territory tier, so no requirePermission() (same as profile.php).

$user = getAuthUser();
$currentRole = getCurrentRole();

$pageTitle = 'Appearance';
$pageIcon = 'ri-palette-line';
$breadcrumbs = [
    'Home' => SITE_URL,
    'Account' => null,
    'Appearance' => null,
];

$densityOptions = [
    'compact' => 'Compact',
    'comfortable' => 'Comfortable',
    'spacious' => 'Spacious',
];
$textSizeOptions = [
    'small' => 'Small',
    'medium' => 'Medium',
    'large' => 'Large',
];
$toggles = [
    'reduce_motion' => ['Reduce motion', 'Turns off chart animations and slide transitions'],
    'high_contrast' => ['High contrast text', 'Darkens secondary text throughout'],
    'focus_outlines' => ['Always show focus outlines', 'Helpful for keyboard-only navigation'],
    'underline_links' => ['Underline all links', 'Links are underlined, not told apart by colour alone'],
    'big_targets' => ['Larger click targets', 'Increases button and input heights'],
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Appearance - Makueni West Diocese</title>
    <meta name="Description" content="Theme, accent colour, density and accessibility settings" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css<?= assetVersion('assets/css/styles.min.css') ?>" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/includes/start-switcher.php' ?>
    <?php include __DIR__ . '/includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/includes/header.php' ?>
        <?php include __DIR__ . '/includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/includes/page-header.php' ?>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <p class="mb-0 text-muted">Changes apply instantly and are saved to your account, on every device you sign in on.</p>
                    <div class="d-flex align-items-center gap-3">
                        <span class="fs-13 fw-semibold text-muted" id="appearanceStatus" aria-live="polite"></span>
                        <button type="button" class="btn btn-light" id="appearanceResetBtn">
                            <i class="ri-refresh-line me-1"></i>Reset to defaults
                        </button>
                    </div>
                </div>

                <div class="row">
                    <!-- Theme -->
                    <div class="col-xl-6">
                        <div class="card custom-card appearance-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Theme</div>
                                    <span class="card-subtitle-text">Light, dark, or follow your device</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-3" role="radiogroup" aria-label="Theme">
                                    <?php foreach (['light' => ['Light', 'Always light', 'ri-sun-line'], 'dark' => ['Dark', 'Always dark', 'ri-moon-line'], 'system' => ['System', 'Your device: <span id="systemModeLabel">light</span>', 'ri-computer-line']] as $value => [$label, $hint, $icon]): ?>
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="app-theme" id="theme-<?= $value ?>" value="<?= $value ?>" autocomplete="off">
                                        <label class="theme-tile" for="theme-<?= $value ?>">
                                            <span class="theme-tile-preview theme-tile-preview-<?= $value ?>">
                                                <?php if ($value === 'system'): ?>
                                                    <span class="theme-tile-half"></span>
                                                <?php endif; ?>
                                                <i class="<?= $icon ?>"></i>
                                            </span>
                                            <span class="theme-tile-label"><?= $label ?></span>
                                            <span class="theme-tile-hint"><?= $hint ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="alert alert-primary bg-primary-transparent d-flex align-items-center gap-2 mt-3 mb-0 d-none" id="systemThemeNote">
                                    <i class="ri-information-line fs-16"></i>
                                    <span>System follows your device setting - it is currently <strong id="systemModeNote">light</strong>. Choose Light or Dark to override it.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Accent colour -->
                    <div class="col-xl-6">
                        <div class="card custom-card appearance-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Accent colour</div>
                                    <span class="card-subtitle-text">Buttons, highlights and active states</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3" role="radiogroup" aria-label="Accent colour" id="accentSwatches">
                                    <!-- Rendered by appearance.js from MwdAppearance.ACCENTS -->
                                </div>
                                <div class="accent-preview">
                                    <div class="accent-preview-head">Preview</div>
                                    <div class="accent-preview-body">
                                        <button type="button" class="btn btn-primary" tabindex="-1">Primary action</button>
                                        <button type="button" class="btn btn-light" tabindex="-1">Secondary</button>
                                        <span class="badge bg-primary">Status</span>
                                        <a href="javascript:void(0);" class="fw-semibold" tabindex="-1">A link</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Density & type -->
                    <div class="col-xl-6">
                        <div class="card custom-card appearance-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Density &amp; type</div>
                                    <span class="card-subtitle-text">How roomy pages feel, and how large text reads</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <label class="form-label d-block">Interface density</label>
                                <div class="btn-group mb-1" role="group" aria-label="Interface density">
                                    <?php foreach ($densityOptions as $value => $label): ?>
                                        <input type="radio" class="btn-check" name="app-density" id="density-<?= $value ?>" value="<?= $value ?>" autocomplete="off">
                                        <label class="btn" for="density-<?= $value ?>"><?= $label ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="fs-12 text-muted mb-4" id="densityHint"></p>

                                <label class="form-label d-block">Base text size</label>
                                <div class="btn-group mb-4" role="group" aria-label="Base text size">
                                    <?php foreach ($textSizeOptions as $value => $label): ?>
                                        <input type="radio" class="btn-check" name="app-text-size" id="text-size-<?= $value ?>" value="<?= $value ?>" autocomplete="off">
                                        <label class="btn" for="text-size-<?= $value ?>"><?= $label ?></label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="border rounded-3 p-3">
                                    <div class="fw-semibold text-dark">Sample row</div>
                                    <div class="text-muted">This is how list rows and table text will read at your settings.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Accessibility & motion -->
                    <div class="col-xl-6">
                        <div class="card custom-card appearance-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Accessibility &amp; motion</div>
                                    <span class="card-subtitle-text">Make the app easier to see and use</span>
                                </div>
                            </div>
                            <div class="card-body py-1">
                                <?php foreach ($toggles as $key => [$label, $hint]): ?>
                                <div class="appearance-toggle-row">
                                    <label class="flex-fill mb-0" for="<?= $key ?>">
                                        <span class="d-block fw-semibold text-dark"><?= $label ?></span>
                                        <span class="d-block fs-12 text-muted"><?= $hint ?></span>
                                    </label>
                                    <div class="form-check form-switch form-switch-lg mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="<?= $key ?>">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/includes/footer.php' ?>
    </div>

    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/appearance/appearance.js<?= assetVersion('assets/js/pages/appearance/appearance.js') ?>"></script>
</body>

</html>
