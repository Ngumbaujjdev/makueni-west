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
        Permission Management - YNEX
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
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />

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
                        <i class="ri-key-2-line me-2"></i>Permission Management
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/diocese">Home</a></li>
                                <li class="breadcrumb-item"><a href="#">Settings</a></li>
                                <li class="breadcrumb-item"><a href="#">System Administration</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Permissions</li>
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

                                <!-- Permissions Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-key-2-line me-2 text-primary"></i>Permission
                                            Management
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Manage system permissions and
                                            actions</p>
                                    </div>
                                    <button class="btn btn-primary" id="createPermissionBtn">
                                        <i class="ri-add-line me-1"></i>Create Permission
                                    </button>
                                </div>

                                <!-- Filters -->
                                <div class="card border mb-3">
                                    <div class="card-body p-3">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold mb-1">Search</label>
                                                <input type="text" class="form-control"
                                                    id="permissionSearchInput"
                                                    placeholder="Search permissions...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-semibold mb-1">Module</label>
                                                <select class="form-select" id="permissionModuleFilter">
                                                    <option value="">All Modules</option>
                                                    <!-- Populated by JS -->
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-semibold mb-1">Action</label>
                                                <select class="form-select" id="permissionActionFilter">
                                                    <option value="">All Actions</option>
                                                    <option value="create">Create</option>
                                                    <option value="read">Read</option>
                                                    <option value="update">Update</option>
                                                    <option value="delete">Delete</option>
                                                    <option value="approve">Approve</option>
                                                    <option value="export">Export</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label fw-semibold mb-1">Territory
                                                    Scope</label>
                                                <select class="form-select" id="permissionTerritoryFilter">
                                                    <option value="">All Scopes</option>
                                                    <option value="diocese">Diocese</option>
                                                    <option value="region">Region</option>
                                                    <option value="subregion">Sub-Region</option>
                                                    <option value="church">Church</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button class="btn btn-primary w-100"
                                                    id="applyPermissionFilters">
                                                    <i class="ri-filter-3-line me-1"></i>Apply
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Permissions Table -->
                                <div class="card border">
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" id="permissionsTable">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="fw-semibold">#</th>
                                                        <th class="fw-semibold">Permission Name</th>
                                                        <th class="fw-semibold">Module</th>
                                                        <th class="fw-semibold">Submodule</th>
                                                        <th class="fw-semibold">Action</th>
                                                        <th class="fw-semibold">Territory Scope</th>
                                                        <th class="fw-semibold text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="permissionsTableBody">
                                                    <!-- Populated by JS -->
                                                    <tr>
                                                        <td colspan="7" class="text-center py-5">
                                                            <div class="spinner-border text-primary"
                                                                role="status">
                                                                <span
                                                                    class="visually-hidden">Loading...</span>
                                                            </div>
                                                            <p class="text-muted mt-2 mb-0">Loading
                                                                permissions...</p>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pagination -->
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="text-muted fs-13" id="permissionsPaginationInfo">
                                        Showing permissions
                                    </div>
                                    <nav>
                                        <ul class="pagination mb-0" id="permissionsPagination">
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
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS-->
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>

    <!-- Color Picker JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <!-- Logout Handler -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- Constants (IMPORTANT - Add this!) -->
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>

    <!-- Auth Helpers -->
    <script src="<?= SITE_URL ?>/assets/js/utils/auth-helpers.js"></script>

    <!-- Logout Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/authentication/logout.js"></script>

    <!-- Dependencies (existing files) -->
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/permission-matrix.js">
    </script>

    <!-- ============================================================================ -->
    <!-- USER MANAGEMENT MODULES (Load in this exact order!) -->
    <!-- ============================================================================ -->
    <script
        src="<?= SITE_URL ?>/assets/js/pages/system-administration/user-management/user-management-utils.js">
    </script>
    <script
        src="<?= SITE_URL ?>/assets/js/pages/system-administration/user-management/user-management-table.js">
    </script>
    <script
        src="<?= SITE_URL ?>/assets/js/pages/system-administration/user-management/user-management-actions.js">
    </script>
    <script
        src="<?= SITE_URL ?>/assets/js/pages/system-administration/user-management/user-management-modals.js">
    </script>
    <script
        src="<?= SITE_URL ?>/assets/js/pages/system-administration/user-management/user-management.js">
    </script>

    <!-- Initialize Permissions Page -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit for all modules to load
            setTimeout(function() {
                if (typeof UserManagementTable !== 'undefined' && typeof UserManagementTable.loadPermissionsTab === 'function') {
                    console.log('🔑 Loading permissions data...');
                    UserManagementTable.loadPermissionsTab();
                } else {
                    console.error('❌ UserManagementTable not loaded');
                }
            }, 100);
        });
    </script>

</body>

</html>
