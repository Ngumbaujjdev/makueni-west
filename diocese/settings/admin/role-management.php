<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// 2. Check specific permission for Dashboard Overview
requirePermission('diocese.dashboard.dashboardoverview.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>
        Role Management - YNEX
    </title>
    <meta name="Description" content="Bootstrap Responsive Admin Web Dashboard Template" />
    <meta name="Author" content="Spruko Technologies Private Limited" />
    <meta name="keywords"
        content="blazor bootstrap, c# blazor, admin panel, blazor c#, template dashboard, admin, bootstrap admin template, blazor, blazorbootstrap, bootstrap 5 templates, dashboard, dashboard template bootstrap, admin dashboard bootstrap." />

    <!-- Favicon -->
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Choices JS -->
    <script src="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/scripts/choices.min.js">
    </script>

    <!-- Main Theme Js -->
    <script src="<?= asset_url('/assets/js/main.js') ?>"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= asset_url('/assets/css/styles.min.css') ?>" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= asset_url('/assets/css/icons.css') ?>" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Color Picker Css -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/flatpickr/flatpickr.min.css" />
    <link rel="stylesheet"
        href="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/themes/nano.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet"
        href="<?= SITE_URL ?>/assets/libs/choices.js/public/assets/styles/choices.min.css" />

</head>

<body>
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= asset_url('/assets/js/config/app.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>
    <!-- start switcher  -->
    <?php include '../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../includes/loader.php" ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php include '../../../includes/header.php' ?>
        <!-- /app-header -->
        <!-- Start::app-sidebar -->
        <?php include '../../../includes/sidebar.php' ?>
        <!-- End::app-sidebar -->

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">
                        <i class="ri-shield-star-line me-2"></i>Role Management
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/diocese">Home</a></li>
                                <li class="breadcrumb-item"><a href="#">Settings</a></li>
                                <li class="breadcrumb-item"><a href="#">System Administration</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Roles</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <!-- Page Header Close -->

                <!-- Start::row-1 -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-body p-4">

                                <!-- Roles Table Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-shield-star-line me-2 text-primary"></i>Role
                                            Management
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Define roles and assign permissions
                                        </p>
                                    </div>
                                    <button class="btn btn-primary" id="createRoleBtn">
                                        <i class="ri-add-line me-1"></i>Create Role
                                    </button>
                                </div>

                                <!-- Filters -->
                                <div class="card border mb-3">
                                    <div class="card-body p-3">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold mb-1">Search
                                                    Roles</label>
                                                <input type="text" class="form-control" id="roleSearchInput"
                                                    placeholder="Search by role name...">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label fw-semibold mb-1">Territory
                                                    Level</label>
                                                <select class="form-select" id="roleTerritoryFilter">
                                                    <option value="">All Levels</option>
                                                    <option value="diocese">Diocese</option>
                                                    <option value="region">Region</option>
                                                    <option value="subregion">Sub-Region</option>
                                                    <option value="church">Church</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-semibold mb-1">Status</label>
                                                <select class="form-select" id="roleStatusFilter">
                                                    <option value="">All Status</option>
                                                    <option value="1">Active</option>
                                                    <option value="0">Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <button class="btn btn-primary w-100" id="applyRoleFilters">
                                                    <i class="ri-filter-3-line me-1"></i>Apply Filters
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Roles Table -->
                                <div class="card border">
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" id="rolesTable">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="fw-semibold">#</th>
                                                        <th class="fw-semibold">Role Name</th>
                                                        <th class="fw-semibold">Territory Level</th>
                                                        <th class="fw-semibold">Users</th>
                                                        <th class="fw-semibold">Permissions</th>
                                                        <th class="fw-semibold">Modules</th>
                                                        <th class="fw-semibold">Status</th>
                                                        <th class="fw-semibold text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="rolesTableBody">
                                                    <!-- Populated by JS -->
                                                    <tr>
                                                        <td colspan="8" class="text-center py-5">
                                                            <div class="spinner-border text-primary"
                                                                role="status">
                                                                <span
                                                                    class="visually-hidden">Loading...</span>
                                                            </div>
                                                            <p class="text-muted mt-2 mb-0">Loading roles...
                                                            </p>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pagination -->
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="text-muted fs-13" id="rolesPaginationInfo">
                                        Showing roles
                                    </div>
                                    <nav>
                                        <ul class="pagination mb-0" id="rolesPagination">
                                            <!-- Populated by JS -->
                                        </ul>
                                    </nav>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                <!--End::row-1 -->

            </div>
        </div>
        <!-- End::app-content -->

        <!-- Footer Start -->
        <?php include '../../../includes/footer.php' ?>
        <!-- Footer End -->

    </div>

    <!-- ============================================ -->
    <!-- MODAL: CREATE ROLE -->
    <!-- ============================================ -->
    <div class="modal fade" id="createRoleModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-semibold text-white">
                        <i class="ri-shield-star-line me-2"></i>Create New Role
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="createRoleForm">
                    <div class="modal-body p-4">

                        <!-- Info Alert -->
                        <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                            <div class="d-flex align-items-start">
                                <i class="ri-information-line fs-20 me-3 text-info"></i>
                                <div class="flex-fill">
                                    <h6 class="alert-heading mb-2 fw-semibold">About Roles</h6>
                                    <small class="text-dark">
                                        Roles define what users can do in the system. After creating a role,
                                        you can assign specific permissions to control access to different features.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 1: BASIC INFORMATION -->
                        <div class="card border mb-4">
                            <div class="card-header bg-light border-bottom">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-file-text-line me-2 text-primary"></i>Basic Information
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <!-- Role Name -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Role Name <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-shield-star-line text-primary"></i>
                                            </span>
                                            <input type="text" class="form-control" id="createRoleName"
                                                   placeholder="e.g., Parish Priest, Treasurer" required>
                                        </div>
                                    </div>

                                    <!-- Territory Level -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Territory Level <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-map-pin-line text-success"></i>
                                            </span>
                                            <select class="form-select" id="createRoleTerritoryLevel" required>
                                                <option value="">Select Territory Level</option>
                                                <option value="diocese">Diocese</option>
                                                <option value="region">Region</option>
                                                <option value="subregion">Sub-Region</option>
                                                <option value="church">Church</option>
                                            </select>
                                        </div>
                                        <small class="text-muted fs-12 mt-1 d-block">
                                            <i class="ri-information-line me-1"></i>Defines which territorial level this role applies to
                                        </small>
                                    </div>

                                    <!-- Description -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Description
                                        </label>
                                        <textarea class="form-control" id="createRoleDescription" rows="3"
                                                  placeholder="Brief description of this role's responsibilities..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 2: STATUS -->
                        <div class="card border">
                            <div class="card-header bg-light border-bottom">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-settings-3-line me-2 text-warning"></i>Role Status
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                                    <div>
                                        <h6 class="mb-1 fw-semibold" id="createRoleStatusLabel">Active</h6>
                                        <small class="text-muted">Role will be available for assignment immediately</small>
                                    </div>
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="createRoleStatus" checked>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary btn-lg px-4">
                            <i class="ri-add-line me-1"></i>Create Role
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- MODAL: EDIT ROLE -->
    <!-- ============================================ -->
    <div class="modal fade" id="editRoleModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-semibold text-white">
                        <i class="ri-edit-line me-2"></i>Edit Role
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editRoleForm">
                    <input type="hidden" id="editRoleId">
                    <div class="modal-body p-4">

                        <!-- Info Alert -->
                        <div class="alert alert-warning bg-warning-transparent border-0 mb-4 p-3">
                            <div class="d-flex align-items-start">
                                <i class="ri-error-warning-line fs-20 me-3 text-warning"></i>
                                <div class="flex-fill">
                                    <h6 class="alert-heading mb-2 fw-semibold">Edit Guidelines</h6>
                                    <ul class="mb-0 ps-3 fs-13 text-dark">
                                        <li class="mb-1">Changes will affect all users assigned to this role</li>
                                        <li class="mb-1">Territory level cannot be changed if users are assigned</li>
                                        <li class="mb-0">Permission changes are managed separately</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 1: BASIC INFORMATION -->
                        <div class="card border mb-4">
                            <div class="card-header bg-light border-bottom">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-file-text-line me-2 text-success"></i>Basic Information
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <!-- Role Name -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Role Name <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-shield-star-line text-success"></i>
                                            </span>
                                            <input type="text" class="form-control" id="editRoleName" required>
                                        </div>
                                    </div>

                                    <!-- Territory Level -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Territory Level <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">
                                                <i class="ri-map-pin-line text-success"></i>
                                            </span>
                                            <select class="form-select" id="editRoleTerritoryLevel" required>
                                                <option value="">Select Territory Level</option>
                                                <option value="diocese">Diocese</option>
                                                <option value="region">Region</option>
                                                <option value="subregion">Sub-Region</option>
                                                <option value="church">Church</option>
                                            </select>
                                        </div>
                                        <small class="text-muted fs-12 mt-1 d-block" id="editTerritoryLevelWarning">
                                            <!-- Populated by JS if users are assigned -->
                                        </small>
                                    </div>

                                    <!-- Description -->
                                    <div class="col-12">
                                        <label class="form-label fw-semibold mb-2">
                                            Description
                                        </label>
                                        <textarea class="form-control" id="editRoleDescription" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 2: ROLE STATISTICS -->
                        <div class="card border mb-4">
                            <div class="card-header bg-light border-bottom">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-bar-chart-line me-2 text-info"></i>Role Statistics
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Assigned Users</small>
                                        <strong class="fs-18" id="editRoleUsersCount">0</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Permissions</small>
                                        <strong class="fs-18" id="editRolePermissionsCount">0</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Modules</small>
                                        <strong class="fs-18" id="editRoleModulesCount">0</strong>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <small class="text-muted d-block">Created</small>
                                        <span id="editRoleCreatedAt">-</span>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block">Last Modified</small>
                                        <span id="editRoleUpdatedAt">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION 3: STATUS -->
                        <div class="card border">
                            <div class="card-header bg-light border-bottom">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-settings-3-line me-2 text-warning"></i>Role Status
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                                    <div>
                                        <h6 class="mb-1 fw-semibold" id="editRoleStatusLabel">Active</h6>
                                        <small class="text-muted">Toggle to activate or deactivate this role</small>
                                    </div>
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="editRoleStatus" checked>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success btn-lg px-4">
                            <i class="ri-save-line me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow">
            <i class="ri-arrow-up-s-fill fs-20"></i>
        </span>
    </div>
    <div id="responsive-overlay"></div>
    <!-- Scroll To Top -->

    <!-- Popper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="<?= asset_url('/assets/js/defaultmenu.min.js') ?>"></script>

    <!-- Node Waves JS-->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= asset_url('/assets/js/sticky.js') ?>"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= asset_url('/assets/js/simplebar.js') ?>"></script>

    <!-- Color Picker JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= asset_url('/assets/js/custom-switcher.min.js') ?>"></script>

    <!-- Custom JS -->
    <script src="<?= asset_url('/assets/js/custom.js') ?>"></script>

    <!-- Toast JS -->
    <script src="<?= asset_url('/assets/js/Toasts.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/toast.js') ?>"></script>

    <!-- Constants -->
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>

    <!-- Auth Helpers -->
    <script src="<?= asset_url('/assets/js/utils/auth-helpers.js') ?>"></script>

    <!-- Logout Handler -->
    <script src="<?= asset_url('/assets/js/pages/authentication/logout.js') ?>"></script>

    <!-- API Handler -->
    <script src="<?= asset_url('/assets/js/pages/system-administration/api-handler.js') ?>"></script>

    <!-- ============================================================================ -->
    <!-- USER MANAGEMENT MODULES (Reusing from Users Page) -->
    <!-- ============================================================================ -->
    <script
        src="<?= asset_url('/assets/js/pages/system-administration/user-management/user-management-utils.js') ?>">
    </script>
    <script
        src="<?= asset_url('/assets/js/pages/system-administration/user-management/user-management-table.js') ?>">
    </script>
    <script
        src="<?= asset_url('/assets/js/pages/system-administration/role-management/role-modals.js') ?>">
    </script>

    <!-- Initialize Role Management -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🎯 Role Management Page - Starting initialization...');

            // Wait for all dependencies to load
            setTimeout(function() {
                if (typeof UserManagementTable !== 'undefined') {
                    console.log('📦 Initializing Role Management using UserManagementTable...');

                    // Load roles using the same function as users page
                    UserManagementTable.loadRolesTab();

                    // Setup filter event listeners for standalone page
                    setupRoleFilters();

                    console.log('✅ Role Management initialized successfully');
                } else {
                    console.error('❌ UserManagementTable not loaded');
                }
            }, 100);
        });

        function setupRoleFilters() {
            console.log('🔧 Setting up role filter listeners...');

            // Initialize UserManagementState if it doesn't exist
            if (!window.UserManagementState) {
                console.log('📦 Initializing UserManagementState...');
                window.UserManagementState = {
                    currentTab: 'roles',
                    currentPage: 1,
                    perPage: 15,
                    filters: {
                        users: { search: '', territory: '', role: '', status: '' },
                        roles: { search: '', territory_level: '', status: '' },
                        permissions: { search: '', module: '', action: '', territory: '' }
                    },
                    cache: {
                        users: null,
                        roles: null,
                        permissions: null,
                        modules: null,
                        territories: null,
                        lastFetch: {
                            users: null,
                            roles: null,
                            permissions: null,
                            modules: null
                        }
                    }
                };
            }

            const STATE = window.UserManagementState;

            // Search input - with debounce
            const roleSearchInput = document.getElementById('roleSearchInput');
            if (roleSearchInput) {
                let searchTimeout;
                roleSearchInput.addEventListener('input', function(e) {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        console.log('🔍 Search filter changed:', e.target.value);
                        STATE.filters.roles.search = e.target.value;
                        STATE.currentPage = 1;
                        UserManagementTable.loadRolesTab(true);
                    }, 500);
                });
                console.log('✅ Search filter listener attached');
            }

            // Territory filter
            const roleTerritoryFilter = document.getElementById('roleTerritoryFilter');
            if (roleTerritoryFilter) {
                roleTerritoryFilter.addEventListener('change', function(e) {
                    console.log('🌍 Territory filter changed:', e.target.value);
                    STATE.filters.roles.territory_level = e.target.value;
                    STATE.currentPage = 1;
                    UserManagementTable.loadRolesTab(true);
                });
                console.log('✅ Territory filter listener attached');
            }

            // Status filter
            const roleStatusFilter = document.getElementById('roleStatusFilter');
            if (roleStatusFilter) {
                roleStatusFilter.addEventListener('change', function(e) {
                    console.log('📊 Status filter changed:', e.target.value);
                    STATE.filters.roles.status = e.target.value;
                    STATE.currentPage = 1;
                    UserManagementTable.loadRolesTab(true);
                });
                console.log('✅ Status filter listener attached');
            }

            // Apply Filters button (optional, since filters work in real-time)
            const applyFiltersBtn = document.getElementById('applyRoleFilters');
            if (applyFiltersBtn) {
                applyFiltersBtn.addEventListener('click', function() {
                    console.log('🔄 Apply Filters clicked');
                    STATE.currentPage = 1;
                    UserManagementTable.loadRolesTab(true);
                });
                console.log('✅ Apply Filters button listener attached');
            }

            console.log('✅ All role filter listeners set up successfully');
        }
    </script>

</body>

</html>
