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
    <title>Module Groups Management - YNEX</title>
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
                        <i class="ri-stack-line me-2"></i>Module Groups Management
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="<?= SITE_URL ?>/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">Settings</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">System Administration</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Module Groups</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Module Groups Management Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="ri-stack-line me-2 text-primary"></i>Module Groups
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-stack-line me-2 text-primary"></i>Module Groups
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Organize modules into logical groups for navigation</p>
                                    </div>
                                </div>

                                <!-- Territory Filter Section -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold mb-1">
                                            <i class="ri-filter-3-line me-1"></i>Territory Level
                                        </label>
                                        <select class="form-select" id="groupTerritoryFilter">
                                            <option value="">All Territories</option>
                                            <option value="diocese" selected>Diocese</option>
                                            <option value="region">Region</option>
                                            <option value="subregion">Sub-Region</option>
                                            <option value="church">Church</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold mb-1">&nbsp;</label>
                                        <button class="btn btn-primary w-100" id="applyGroupFilters">
                                            <i class="ri-refresh-line me-1"></i>Apply Filter
                                        </button>
                                    </div>
                                    <div class="col-md-3 ms-auto">
                                        <label class="form-label fw-semibold mb-1">&nbsp;</label>
                                        <button class="btn btn-success w-100" id="createGroupBtn">
                                            <i class="ri-add-line me-1"></i>Create Group
                                        </button>
                                    </div>
                                </div>

                                <!-- Groups List -->
                                <div class="card border">
                                    <div class="card-body">
                                        <div id="groupsListContainer">
                                            <!-- Populated by JS -->
                                            <div class="text-center py-5">
                                                <div class="spinner-border text-primary" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                <p class="text-muted mt-2 mb-0">Loading module groups...</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <!-- End::app-content -->

        <!-- Create/Edit Module Group Modal -->
        <div class="modal fade" id="moduleGroupModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-stack-add-line me-2"></i><span id="groupModalTitle">Create Module Group</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <form id="moduleGroupForm">
                            <input type="hidden" id="groupId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Module Group Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Groups organize related modules together in the navigation</li>
                                            <li class="mb-1">Each group should have a clear purpose (e.g., Dashboard, Finance, HR)</li>
                                            <li class="mb-0">Choose descriptive names and appropriate icons</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="row g-3">
                                <!-- Group Name -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Group Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-stack-line text-success"></i>
                                        </span>
                                        <input type="text" class="form-control" id="groupName"
                                               placeholder="e.g., Dashboard, Finance" required>
                                    </div>
                                </div>

                                <!-- Icon -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Icon <span class="text-muted">(Optional - Auto-generated)</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-palette-line text-warning"></i>
                                        </span>
                                        <input type="text" class="form-control" id="groupIcon"
                                               placeholder="Leave empty for auto-generation">
                                    </div>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-magic-line me-1 text-success"></i>Auto-generated from name if empty (e.g., "Finance" → ri-money-dollar-circle-line)
                                    </small>
                                </div>

                                <!-- Territory Scope -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Territory Scope <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="groupTerritoryScope" required>
                                        <option value="">Select Territory</option>
                                        <option value="diocese">Diocese</option>
                                        <option value="region">Region</option>
                                        <option value="subregion">Sub-Region</option>
                                        <option value="church">Church</option>
                                    </select>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-information-line me-1"></i>Which territory level can access this group
                                    </small>
                                </div>

                                <!-- Order Number -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Order Number <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">
                                            <i class="ri-sort-asc text-info"></i>
                                        </span>
                                        <input type="number" class="form-control" id="groupOrderNumber"
                                               min="1" placeholder="e.g., 1, 2, 3" required>
                                    </div>
                                    <small class="text-muted fs-12 mt-1 d-block">
                                        <i class="ri-information-line me-1"></i>Display order in menu
                                    </small>
                                </div>

                                <!-- Description -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold mb-2">Description</label>
                                    <textarea class="form-control" id="groupDescription" rows="3"
                                              placeholder="Brief description of this group's purpose..."></textarea>
                                </div>

                                <!-- Active Status -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between p-3 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this group
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="groupActive" checked>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-4" id="saveGroupBtn">
                            <i class="ri-save-line me-1"></i>Save Group
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteGroupModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-delete-bin-line me-2"></i>Delete Module Group
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-danger bg-danger-transparent border-0 mb-3 p-3">
                            <div class="d-flex align-items-start">
                                <i class="ri-error-warning-line fs-20 me-3 text-danger"></i>
                                <div class="flex-fill">
                                    <h6 class="alert-heading mb-2 fw-semibold">Warning: Permanent Action</h6>
                                    <p class="mb-0 text-dark fs-13">
                                        This action cannot be undone. All associated data will be permanently deleted.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p class="mb-2">Are you sure you want to delete the module group:</p>
                        <p class="fw-semibold text-primary fs-16 mb-3" id="deleteGroupName"></p>

                        <div class="alert alert-warning bg-warning-transparent border-0 p-3">
                            <p class="mb-1 fw-semibold text-dark">
                                <i class="ri-information-line me-1"></i>This will also affect:
                            </p>
                            <ul class="mb-0 ps-3 text-dark fs-13">
                                <li>All modules under this group will need to be reassigned</li>
                                <li>Navigation structure will be updated</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top p-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteGroupBtn">
                            <i class="ri-delete-bin-line me-1"></i>Yes, Delete Group
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Start -->
        <?php include '../../../includes/footer.php' ?>
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

    <!-- Auth Helpers -->
    <script src="<?= SITE_URL ?>/assets/js/utils/auth-helpers.js"></script>

    <!-- Logout Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/authentication/logout.js"></script>

    <!-- API Handler -->
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/api-handler.js"></script>

    <!-- Module Groups Management JS -->
    <script src="<?= SITE_URL ?>/assets/js/pages/system-administration/module-groups/module-groups.js"></script>

    <!-- Initialize Module Groups Page -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (typeof ModuleGroupsManagement !== 'undefined' && typeof ModuleGroupsManagement.init === 'function') {
                    console.log('📦 Initializing Module Groups Management...');
                    ModuleGroupsManagement.init();
                } else {
                    console.error('❌ ModuleGroupsManagement not loaded');
                }
            }, 100);
        });
    </script>
</body>

</html>
