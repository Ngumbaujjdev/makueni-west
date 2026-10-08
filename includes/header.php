<?php
/**
 * Navigation Bar
 * 
 * Dynamic navbar that shows user information from session
 */

// Get user data from session
$currentUser = $_SESSION['user'] ?? [];
$currentRole = $_SESSION['current_role'] ?? [];
$territoryType = $_SESSION['current_territory_type'] ?? 'church';

// Extract user details
$userFirstName = $currentUser['firstname'] ?? 'User';
$userLastName = $currentUser['lastname'] ?? '';
$userFullName = trim($userFirstName . ' ' . $userLastName);
$userEmail = $currentUser['email'] ?? '';
$userPosition = $currentUser['position'] ?? '';
$roleName = $currentRole['role_name'] ?? 'Member';
$territoryName = $currentRole['territory_name'] ?? '';

// Generate initials for avatar
$initials = strtoupper(substr($userFirstName, 0, 1) . substr($userLastName, 0, 1));
if (strlen($initials) < 2) {
    $initials = strtoupper(substr($userFirstName, 0, 2));
}

// Their own photo (My Profile), when they have added one - else the initials.
$userPhoto = is_string($currentUser['photo_url'] ?? null) ? $currentUser['photo_url'] : '';
$avatarInner = $userPhoto !== ''
    ? '<img src="' . htmlspecialchars($userPhoto) . '" alt="">'
    : htmlspecialchars($initials);

// Avatar color based on user ID (for variety)
$userId = $currentUser['id'] ?? 1;
$avatarColors = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
$avatarColor = $avatarColors[$userId % count($avatarColors)];
// secondary/warning are both the diocese gold - white text on gold is too
// low-contrast, so those two get dark text on the (now solid) avatar/badge.
$avatarTextClass = in_array($avatarColor, ['secondary', 'warning'], true) ? 'text-dark' : 'text-white';

$baseUrl = '/makueni-west';
?>

<!-- app-header -->
<header class="app-header">
    <!-- Start::main-header-container -->
    <div class="main-header-container container-fluid">
        <!-- Start::header-content-left -->
        <div class="header-content-left">
            <!-- Start::header-element -->
            <div class="header-element">
                <div class="horizontal-logo">
                    <a href="<?= $baseUrl ?>/diocese/dashboard/" class="header-logo">
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/desktop-logo.png" alt="logo" class="desktop-logo" />
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/toggle-logo.png" alt="logo" class="toggle-logo" />
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/desktop-dark.png" alt="logo" class="desktop-dark" />
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/toggle-dark.png" alt="logo" class="toggle-dark" />
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/desktop-white.png" alt="logo" class="desktop-white" />
                        <img src="<?= $baseUrl ?>/assets/images/brand-logos/toggle-white.png" alt="logo" class="toggle-white" />
                    </a>
                </div>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element -->
            <div class="header-element">
                <!-- Start::header-link -->
                <a aria-label="Hide Sidebar" class="sidemenu-toggle header-link animated-arrow hor-toggle horizontal-navtoggle" data-bs-toggle="sidebar" href="javascript:void(0);"><span></span></a>
                <!-- End::header-link -->
            </div>
            <!-- End::header-element -->
        </div>
        <!-- End::header-content-left -->

        <!-- Start::header-search-bar - a real search bar on tablet/desktop
             (same pattern as v1-events-backend's navbar command-palette bar);
             on phones it's hidden and the search icon below opens the
             palette instead. -->
        <div class="header-search-bar-wrap d-none d-md-flex">
            <button type="button" class="header-search-bar" data-bs-toggle="modal" data-bs-target="#searchModal" aria-label="Search pages">
                <i class="ri-search-line"></i>
                <span class="header-search-bar-text">Search pages, reports, settings...</span>
                <kbd class="header-search-bar-key" data-gs-key>&#8984;K</kbd>
            </button>
        </div>
        <!-- End::header-search-bar -->

        <!-- Start::header-content-right -->
        <div class="header-content-right">
            <!-- Start::header-element - Locale indicator (decorative - this app has no
                 i18n/language switching, matching how the YNEX template's own reference
                 demo's flag+"EN" indicator isn't real language switching either) -->
            <div class="header-element d-none d-md-flex align-items-center">
                <img src="<?= $baseUrl ?>/assets/images/flags/kenya.png" alt="Kenya" title="Kenya" class="header-flag" />
                <span class="fs-12 fw-semibold text-dark ms-1">EN</span>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element -->
            <!-- Phones only - tablet/desktop use the header search bar above. -->
            <div class="header-element header-search d-flex d-md-none">
                <!-- Start::header-link -->
                <a href="javascript:void(0);" class="header-link" data-bs-toggle="modal" data-bs-target="#searchModal" aria-label="Search pages">
                    <i class="bx bx-search-alt-2 header-link-icon"></i>
                </a>
                <!-- End::header-link -->
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element - Theme Toggle (Hidden but code preserved) -->
            <div class="header-element header-theme-mode" style="display: none;">
                <!-- Start::header-link|layout-setting -->
                <a href="javascript:void(0);" class="header-link layout-setting">
                    <span class="light-layout">
                        <i class="bx bx-moon header-link-icon"></i>
                    </span>
                    <span class="dark-layout">
                        <i class="bx bx-sun header-link-icon"></i>
                    </span>
                </a>
                <!-- End::header-link|layout-setting -->
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element - Reports monitor (assets/js/utils/report-center.js) -->
            <div class="header-element report-tray">
                <a href="javascript:void(0);" class="header-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="reportTrayToggle" aria-expanded="false" title="Reports">
                    <i class="ri-file-download-line header-link-icon"></i>
                    <span class="badge bg-primary rounded-pill header-icon-badge" id="reportTrayBadge" hidden>0</span>
                </a>
                <div class="main-header-dropdown dropdown-menu dropdown-menu-end report-tray-menu">
                    <div class="report-tray-head">
                        <p class="mb-0 fs-16 fw-semibold">Reports</p>
                        <?php if ((getCurrentRole()['territory']['territory_type'] ?? null) === 'church') : ?>
                            <a href="<?= SITE_URL ?><?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/church/attendance') ? '/church/attendance/reports' : '/church/demographics-growth/reports' ?>" class="report-tray-all">All reports <i class="ri-arrow-right-line"></i></a>
                        <?php endif ?>
                    </div>
                    <div class="report-tray-list" id="reportTrayList">
                        <div class="report-tray-empty">Reports you generate show up here.</div>
                    </div>
                </div>
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element - Notifications -->
            <div class="header-element notifications-dropdown">
                <!-- Start::header-link|dropdown-toggle -->
                <a href="javascript:void(0);" class="header-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="messageDropdown" aria-expanded="false">
                    <i class="bx bx-bell header-link-icon"></i>
                    <span class="badge bg-secondary rounded-pill header-icon-badge pulse pulse-secondary" id="notification-icon-badge">0</span>
                </a>
                <!-- End::header-link|dropdown-toggle -->
                <!-- Start::main-header-dropdown - filled by assets/js/utils/notifications.js (GET /notifications) -->
                <div class="main-header-dropdown dropdown-menu dropdown-menu-end notif-dropdown" data-popper-placement="none">
                    <div class="p-3">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <p class="mb-0 fs-17 fw-semibold">Notifications</p>
                            <span class="badge bg-secondary text-dark" id="notifiation-data">0 Unread</span>
                        </div>
                    </div>
                    <div class="dropdown-divider mb-0"></div>
                    <ul class="list-unstyled mb-0 notif-list" id="notifList" aria-live="polite"></ul>
                    <div class="p-5 empty-item1" id="notifEmpty">
                        <div class="text-center">
                            <span class="avatar avatar-xl avatar-rounded bg-secondary text-dark">
                                <i class="ri-notification-off-line fs-2"></i>
                            </span>
                            <h6 class="fw-semibold mt-3">No New Notifications</h6>
                        </div>
                    </div>
                    <div class="notif-foot d-flex align-items-center justify-content-between gap-2 p-2">
                        <button type="button" class="btn btn-sm btn-light border" id="notifReadAll"><i class="ri-check-double-line me-1"></i>Mark all read</button>
                        <a class="btn btn-sm btn-primary" href="<?= $baseUrl ?>/notifications"><i class="ri-notification-3-line me-1"></i>See all</a>
                    </div>
                </div>
                <!-- End::main-header-dropdown -->
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element - Fullscreen -->
            <div class="header-element header-fullscreen">
                <!-- Start::header-link -->
                <a onclick="openFullscreen();" href="javascript:void(0);" class="header-link">
                    <i class="bx bx-fullscreen full-screen-open header-link-icon"></i>
                    <i class="bx bx-exit-fullscreen full-screen-close header-link-icon d-none"></i>
                </a>
                <!-- End::header-link -->
            </div>
            <!-- End::header-element -->

            <!-- Start::header-element - User Profile -->
            <div class="header-element">
                <!-- Start::header-link|dropdown-toggle -->
                <a href="javascript:void(0);" class="header-link dropdown-toggle" id="mainHeaderProfile" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <div class="d-flex align-items-center">
                        <div class="me-sm-2 me-0">
                            <!-- Dynamic Avatar with Initials (solid, not a pale -transparent tint) -->
                            <span class="avatar avatar-sm rounded-circle bg-<?= $avatarColor ?> <?= $avatarTextClass ?> fw-semibold" data-user-avatar data-initials="<?= htmlspecialchars($initials) ?>"><?= $avatarInner ?></span>
                        </div>
                        <div class="d-sm-block d-none">
                            <p class="fw-semibold mb-0 lh-1"><?= htmlspecialchars($userFullName) ?></p>
                            <span class="op-7 fw-normal d-block fs-11"><?= htmlspecialchars($roleName) ?></span>
                        </div>
                    </div>
                </a>
                <!-- End::header-link|dropdown-toggle -->
                <ul class="main-header-dropdown dropdown-menu pt-0 overflow-hidden header-profile-dropdown dropdown-menu-end" aria-labelledby="mainHeaderProfile">
                    <!-- User Info Header -->
                    <li class="dropdown-header">
                        <div class="d-flex align-items-center p-2">
                            <span class="avatar avatar-md rounded-circle bg-<?= $avatarColor ?> <?= $avatarTextClass ?> fw-semibold" data-user-avatar data-initials="<?= htmlspecialchars($initials) ?>"><?= $avatarInner ?></span>
                            <div class="ms-3" style="min-width: 0;">
                                <p class="mb-0 fw-semibold text-dark"><?= htmlspecialchars($userFullName) ?></p>
                                <small class="d-block text-body text-truncate"><?= htmlspecialchars($userEmail) ?></small>
                                <span class="badge bg-<?= $avatarColor ?> <?= $avatarTextClass ?> mt-1"><?= htmlspecialchars($roleName) ?></span>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <!-- Profile -->
                    <li>
                        <a class="dropdown-item d-flex" href="<?= $baseUrl ?>/profile">
                            <i class="ti ti-user-circle fs-18 me-2 op-7"></i>My Profile
                        </a>
                    </li>

                    <!-- Appearance (standalone page) -->
                    <li>
                        <a class="dropdown-item d-flex" href="<?= $baseUrl ?>/appearance">
                            <i class="ri-palette-line fs-18 me-2 op-7"></i>Appearance
                        </a>
                    </li>

                    <!-- Help & Support -->
                    <li>
                        <a class="dropdown-item d-flex border-block-end" href="<?= $baseUrl ?>/support">
                            <i class="ti ti-headset fs-18 me-2 op-7"></i>Help & Support
                        </a>
                    </li>
                    
                    <!-- Logout -->
                    <li>
                        <a class="dropdown-item d-flex text-danger" href="javascript:void(0);" onclick="handleLogout()">
                            <i class="ti ti-logout fs-18 me-2 op-7"></i>Log Out
                        </a>
                    </li>
                </ul>
            </div>
            <!-- End::header-element -->
        </div>
        <!-- End::header-content-right -->
    </div>
    <!-- End::main-header-container -->
</header>
<!-- /app-header -->

<!-- Secondary nav (sub-tab bar) - populated client-side by secondary-nav.js
     from the same cached module tree the sidebar reads (mwd_current_modules).
     No new API calls; shows the active module's submodules as tabs. -->
<div id="secondary-nav-bar"></div>

<?php include __DIR__ . '/global-search.php' ?>
<?php include __DIR__ . '/report-modal.php' ?>

<script>
    // Shared base URL for secondary-nav.js/global-search.js (both static
    // files, so they can't use PHP interpolation directly the way
    // sidebar.php's inline script does).
    window.mwdBaseUrl = '<?= $baseUrl ?>';
</script>
<script src="<?= $baseUrl ?>/assets/js/utils/appearance-sync.js<?= assetVersion('assets/js/utils/appearance-sync.js') ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/utils/secondary-nav.js<?= assetVersion('assets/js/utils/secondary-nav.js') ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/utils/global-search.js<?= assetVersion('assets/js/utils/global-search.js') ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/utils/report-center.js<?= assetVersion('assets/js/utils/report-center.js') ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/utils/system-notice.js<?= assetVersion('assets/js/utils/system-notice.js') ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/utils/notifications.js<?= assetVersion('assets/js/utils/notifications.js') ?>"></script>

