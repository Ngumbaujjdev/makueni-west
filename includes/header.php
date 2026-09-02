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

// Avatar color based on user ID (for variety)
$userId = $currentUser['id'] ?? 1;
$avatarColors = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
$avatarColor = $avatarColors[$userId % count($avatarColors)];

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

        <!-- Start::header-content-right -->
        <div class="header-content-right">
            <!-- Start::header-element -->
            <div class="header-element header-search">
                <!-- Start::header-link -->
                <a href="javascript:void(0);" class="header-link" data-bs-toggle="modal" data-bs-target="#searchModal">
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

            <!-- Start::header-element - Notifications -->
            <div class="header-element notifications-dropdown">
                <!-- Start::header-link|dropdown-toggle -->
                <a href="javascript:void(0);" class="header-link dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="messageDropdown" aria-expanded="false">
                    <i class="bx bx-bell header-link-icon"></i>
                    <span class="badge bg-secondary rounded-pill header-icon-badge pulse pulse-secondary" id="notification-icon-badge">0</span>
                </a>
                <!-- End::header-link|dropdown-toggle -->
                <!-- Start::main-header-dropdown -->
                <div class="main-header-dropdown dropdown-menu dropdown-menu-end" data-popper-placement="none">
                    <div class="p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <p class="mb-0 fs-17 fw-semibold">Notifications</p>
                            <span class="badge bg-secondary-transparent" id="notifiation-data">0 Unread</span>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <div class="p-5 empty-item1">
                        <div class="text-center">
                            <span class="avatar avatar-xl avatar-rounded bg-secondary-transparent">
                                <i class="ri-notification-off-line fs-2"></i>
                            </span>
                            <h6 class="fw-semibold mt-3">No New Notifications</h6>
                        </div>
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
                            <!-- Dynamic Avatar with Initials -->
                            <span class="avatar avatar-sm rounded-circle bg-<?= $avatarColor ?>-transparent text-<?= $avatarColor ?>">
                                <?= $initials ?>
                            </span>
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
                    <li class="dropdown-header bg-light">
                        <div class="d-flex align-items-center p-2">
                            <span class="avatar avatar-md rounded-circle bg-<?= $avatarColor ?>-transparent text-<?= $avatarColor ?>">
                                <?= $initials ?>
                            </span>
                            <div class="ms-3">
                                <p class="mb-0 fw-semibold"><?= htmlspecialchars($userFullName) ?></p>
                                <small class="text-muted"><?= htmlspecialchars($userEmail) ?></small>
                                <br>
                                <small class="badge bg-<?= $avatarColor ?>-transparent"><?= htmlspecialchars($roleName) ?></small>
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

<script>
    // Shared base URL for secondary-nav.js (a static file, so it can't use
    // PHP interpolation directly the way sidebar.php's inline script does).
    window.mwdBaseUrl = '<?= $baseUrl ?>';
</script>
<script src="<?= $baseUrl ?>/assets/js/utils/secondary-nav.js"></script>

<style>
/* Spacing tightening pass - reduces the template's generous default
   padding to read as a denser, cleaner layout. Scoped to shared/reused
   chrome classes only, applied here since header.php is included on
   every page. */
.card.custom-card {
    margin-block-end: 1rem;
}

.card.custom-card .card-header {
    padding: 0.85rem 1rem;
}

.card.custom-card .card-body {
    padding: 1rem;
}

.page-header-breadcrumb {
    margin-block: 1rem !important;
}

#secondary-nav-bar:not(:empty) {
    background-color: var(--custom-white, #fff);
    border-block-end: 1px solid var(--default-border, #e9edf4);
    padding-inline: 1rem;
}

#secondary-nav-bar .nav-tabs {
    border-block-end: 0;
    gap: 0.25rem;
}

/* Active tab reads as a solid background pill, not an underline - matches
   the same "whole background, not a border" treatment as the sidebar's
   active-state pill. */
#secondary-nav-bar .nav-link {
    color: var(--diocese-black, #0D0D0D);
    font-weight: 500;
    padding: 0.5rem 1rem;
    margin-block: 0.5rem;
    border: 0;
    border-radius: 0.375rem;
}

#secondary-nav-bar .nav-link.active {
    color: #fff;
    font-weight: 600;
    background-color: #2CA4BF;
}
</style>