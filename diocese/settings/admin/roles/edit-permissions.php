<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/../../../../includes/session-manager.php';
require_once __DIR__ . '/../../../../includes/auth-check.php';
require_once __DIR__ . '/../../../../includes/permission-check.php';

// 2. Check specific permission
requirePermission('diocese.dashboard.dashboardoverview.read');

// 3. Get user data
$user = getAuthUser();
$currentRole = getCurrentRole();

// 4. Get and validate role ID from URL
$roleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($roleId <= 0) {
    header('Location: <?= SITE_URL ?>/diocese/settings/admin/users');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <!-- Meta Data -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Edit Role Permissions - Makueni West Diocese</title>
    <meta name="Description" content="Manage role permissions and access control" />

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
    <?php include '../../../../includes/start-switcher.php' ?>

    <!-- Loader -->
    <?php include "../../../../includes/loader.php" ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php include '../../../../includes/header.php' ?>
        <!-- /app-header -->
        <!-- Start::app-sidebar -->
        <?php include '../../../../includes/sidebar.php' ?>
        <!-- End::app-sidebar -->

        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">
                        <i class="ri-shield-keyhole-line me-2"></i>Edit Role Permissions
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">System Administration</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="../users#roles-tab">Roles</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Edit Permissions</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Main Content Row -->
                <div class="row">
                    <!-- LEFT PANEL - Module List -->
                    <div class="col-xl-3">
                        <div class="card custom-card">
                            <div class="card-header">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-apps-line me-2"></i>Modules
                                </h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush" id="moduleList">
                                    <!-- Populated by JavaScript -->
                                    <div class="text-center p-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="text-muted mt-2 mb-0">Loading modules...</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Back Button -->
                        <div class="d-grid mt-3">
                            <a href="../users#roles-tab" class="btn btn-light">
                                <i class="ri-arrow-left-line me-1"></i>Back to Roles
                            </a>
                        </div>
                    </div>

                    <!-- RIGHT PANEL - Permission Matrix -->
                    <div class="col-xl-9">
                        <!-- Role Summary Card -->
                        <div class="card custom-card mb-3">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center">
                                            <span class="avatar avatar-lg bg-primary-transparent text-primary me-3">
                                                <i class="ri-shield-star-line fs-4"></i>
                                            </span>
                                            <div>
                                                <h5 class="mb-1" id="roleName">
                                                    <span class="spinner-border spinner-border-sm me-2"></span>Loading...
                                                </h5>
                                                <span class="badge bg-primary-transparent" id="territoryLevel"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                                        <div class="d-flex justify-content-md-end gap-3">
                                            <div>
                                                <small class="text-muted d-block">Total Permissions</small>
                                                <strong class="fs-5" id="totalPermissions">0</strong>
                                            </div>
                                            <div>
                                                <small class="text-muted d-block">Assigned</small>
                                                <strong class="fs-5 text-success" id="assignedPermissions">0</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Search & Filter Card -->
                        <div class="card custom-card mb-3">
                            <div class="card-body p-3">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="ri-search-line"></i>
                                            </span>
                                            <input type="text" class="form-control" id="searchSubmodules" 
                                                   placeholder="Search submodules...">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" id="filterModule">
                                            <option value="">All Modules</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-light w-100" id="clearFilters">
                                            <i class="ri-close-line"></i> Clear
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Permission Matrix Table -->
                        <div class="card custom-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="ri-key-2-line me-2"></i>Permission Matrix
                                </h6>
                                <div>
                                    <button class="btn btn-sm btn-success-light me-2" id="selectAllBtn">
                                        <i class="ri-checkbox-multiple-line me-1"></i>Select All
                                    </button>
                                    <button class="btn btn-sm btn-danger-light" id="deselectAllBtn">
                                        <i class="ri-checkbox-blank-line me-1"></i>Deselect All
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="permissionsTable">
                                        <thead class="bg-light">
                                            <tr>
                                                <th style="width: 20%;">Module</th>
                                                <th style="width: 25%;">Submodule</th>
                                                <th class="text-center" style="width: 9%;">
                                                    <i class="ri-add-circle-line text-success"></i> Create
                                                </th>
                                                <th class="text-center" style="width: 9%;">
                                                    <i class="ri-eye-line text-info"></i> Read
                                                </th>
                                                <th class="text-center" style="width: 9%;">
                                                    <i class="ri-edit-line text-warning"></i> Update
                                                </th>
                                                <th class="text-center" style="width: 9%;">
                                                    <i class="ri-delete-bin-line text-danger"></i> Delete
                                                </th>
                                                <th class="text-center" style="width: 9%;">
                                                    <i class="ri-check-line text-primary"></i> Approve
                                                </th>
                                                <th class="text-center" style="width: 10%;">
                                                    <i class="ri-download-line text-secondary"></i> Export
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody id="permissionsTableBody">
                                            <!-- Populated by JavaScript -->
                                            <tr>
                                                <td colspan="8" class="text-center p-5">
                                                    <div class="spinner-border text-primary" role="status">
                                                        <span class="visually-hidden">Loading...</span>
                                                    </div>
                                                    <p class="text-muted mt-2 mb-0">Loading permissions...</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between mt-3">
                            <a href="../users#roles-tab" class="btn btn-danger">
                                <i class="ri-close-line me-1"></i>Cancel
                            </a>
                            <button class="btn btn-primary btn-lg" id="savePermissions">
                                <i class="ri-save-line me-1"></i>Save Changes
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- End::app-content -->

        <!-- Footer Start -->
        <?php include '../../../../includes/footer.php' ?>
        <!-- Footer End -->
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
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

    <!-- Toast JS -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- Constants -->
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>

    <!-- API Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/api-handler.js"></script>

    <!-- Edit Permissions Page -->
    <script>
        // Pass role ID to JavaScript
        const ROLE_ID = <?php echo $roleId; ?>;
    </script>
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/role-management/edit-permissions.js"></script>
</body>

</html>
