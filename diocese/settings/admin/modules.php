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
    <title>Module Management - YNEX</title>
    <meta name="Description" content="Bootstrap Responsive Admin Web Dashboard Template" />
    <meta name="Author" content="Spruko Technologies Private Limited" />
    <meta name="keywords"
        content="blazor bootstrap, c# blazor, admin panel, blazor c#, template dashboard, admin, bootstrap admin template, blazor, blazorbootstrap, bootstrap 5 templates, dashboard, dashboard template bootstrap, admin dashboard bootstrap." />

    <!-- Favicon -->
    <link rel="icon" href="http://localhost:8080/makueni-west/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Choices JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/scripts/choices.min.js">
    </script>

    <!-- Main Theme Js -->
    <script src="http://localhost:8080/makueni-west/assets/js/main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="http://localhost:8080/makueni-west/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/icons.css" rel="stylesheet" />

    <!-- Node Waves Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.css" rel="stylesheet" />

    <!-- Simplebar Css -->
    <link href="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />

    <!-- Color Picker Css -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/flatpickr/flatpickr.min.css" />
    <link rel="stylesheet"
        href="http://localhost:8080/makueni-west/assets/libs/%40simonwep/pickr/themes/nano.min.css" />

    <!-- Choices Css -->
    <link rel="stylesheet"
        href="http://localhost:8080/makueni-west/assets/libs/choices.js/public/assets/styles/choices.min.css" />

    <!-- Dragula CSS for drag-and-drop -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/dragula/dragula.min.css" />

    <!-- Module Management Custom Styles -->
    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/css/pages/module-management.css" />

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
                        <i class="ri-settings-3-line me-2"></i>System Administration
                    </h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="/makueni-west/diocese/dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">Settings</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">System Administration</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">Modules</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Module Management Card -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">
                                    <i class="ri-folder-line me-2 text-primary"></i>Module Structure
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Module Structure Header -->
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="mb-1 fw-semibold">
                                            <i class="ri-folder-line me-2 text-primary"></i>Module Structure
                                        </h5>
                                        <p class="text-muted mb-0 fs-13">Define system modules and
                                            navigation hierarchy</p>
                                    </div>
                                </div>

                                <!-- Territory Filter Section -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold mb-1">
                                            <i class="ri-filter-3-line me-1"></i>Territory Level
                                        </label>
                                        <select class="form-select" id="moduleTerritoryFilter">
                                            <option value="">All Territories</option>
                                            <option value="diocese" selected>Diocese</option>
                                            <option value="region">Region</option>
                                            <option value="subregion">Sub-Region</option>
                                            <option value="church">Church</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold mb-1">&nbsp;</label>
                                        <button class="btn btn-primary w-100" id="applyModuleFilters">
                                            <i class="ri-refresh-line me-1"></i>Apply Filter
                                        </button>
                                    </div>
                                    <div class="col-md-3 ms-auto">
                                        <label class="form-label fw-semibold mb-1">&nbsp;</label>
                                        <button class="btn btn-success w-100" id="createModuleBtn">
                                            <i class="ri-add-line me-1"></i>Create Module
                                        </button>
                                    </div>
                                </div>

                                <!-- Module Tree View -->
                                <div class="card border">
                                    <div class="card-body">
                                        <div id="moduleTreeContainer">
                                            <!-- Populated by JS with collapsible tree -->
                                            <div class="text-center py-5">
                                                <div class="spinner-border text-primary" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                <p class="text-muted mt-2 mb-0">Loading module structure...
                                                </p>
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

        <!-- Create/Edit Module Modal -->
        <div class="modal fade" id="moduleModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-folder-add-line me-2"></i><span id="moduleModalTitle">Create Module</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <form id="moduleForm">
                            <input type="hidden" id="moduleId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 m-3 mb-0 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Module Creation Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Modules organize your system's navigation structure</li>
                                            <li class="mb-1">Choose a descriptive name and appropriate icon</li>
                                            <li class="mb-0">Auto-generate permissions to save time</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Navigation -->
                            <div class="p-3 border-bottom">
                                <ul class="nav nav-tabs mb-0 tab-style-6" id="moduleModalTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="module-basic-tab" data-bs-toggle="tab"
                                                data-bs-target="#module-basic" type="button" role="tab">
                                            <i class="ri-file-text-line me-1"></i>Basic Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="module-group-tab" data-bs-toggle="tab"
                                                data-bs-target="#module-group" type="button" role="tab">
                                            <i class="ri-stack-line me-1"></i>Module Group
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="module-status-tab" data-bs-toggle="tab"
                                                data-bs-target="#module-status" type="button" role="tab">
                                            <i class="ri-settings-3-line me-1"></i>Module Status
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="module-permissions-tab" data-bs-toggle="tab"
                                                data-bs-target="#module-permissions" type="button" role="tab">
                                            <i class="ri-key-2-line me-1"></i>Permissions
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <!-- Tab Content -->
                            <div class="tab-content p-4" id="moduleModalTabContent">

                                <!-- TAB 1: BASIC INFORMATION -->
                                <div class="tab-pane fade show active" id="module-basic" role="tabpanel">
                                    <div class="row g-3">
                                        <!-- Module Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Module Name <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-folder-line text-success"></i>
                                                </span>
                                                <input type="text" class="form-control" id="moduleName"
                                                       placeholder="e.g., Finance, HR" required>
                                            </div>
                                        </div>

                                        <!-- Icon -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Icon <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-palette-line text-warning"></i>
                                                </span>
                                                <input type="text" class="form-control" id="moduleIcon"
                                                       placeholder="ri-folder-line" required>
                                            </div>
                                            <small class="text-muted fs-12 mt-1 d-block">
                                                <i class="ri-information-line me-1"></i>RemixIcon class name
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
                                                <input type="number" class="form-control" id="moduleNumber"
                                                       min="1" placeholder="e.g., 1, 2, 3" required>
                                            </div>
                                            <small class="text-muted fs-12 mt-1 d-block">
                                                <i class="ri-information-line me-1"></i>Display order in menu
                                            </small>
                                        </div>

                                        <!-- Description -->
                                        <div class="col-12">
                                            <label class="form-label fw-semibold mb-2">Description</label>
                                            <textarea class="form-control" id="moduleDescription" rows="3"
                                                      placeholder="Brief description of this module's purpose..."></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 2: MODULE GROUP SELECTION -->
                                <div class="tab-pane fade" id="module-group" role="tabpanel">
                                    <!-- Current Territory Display -->
                                    <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                        <div class="d-flex align-items-center">
                                            <i class="ri-information-line fs-20 me-3 text-info"></i>
                                            <div class="flex-fill">
                                                <h6 class="alert-heading mb-1 fw-semibold">Territory Context</h6>
                                                <p class="mb-0 fs-13 text-dark">
                                                    You are creating a module for: <strong id="currentTerritoryDisplay" class="text-primary">Diocese</strong>
                                                </p>
                                                <small class="text-muted">Only module groups for this territory will be shown below</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Module Group Selection -->
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h6 class="card-title mb-0 fw-semibold">
                                                <i class="ri-stack-line me-2 text-primary"></i>Select Module Group
                                                <span class="text-danger">*</span>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row g-3">
                                                <!-- Module Group Dropdown -->
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold mb-2">
                                                        Module Group <span class="text-danger">*</span>
                                                    </label>
                                                    <select class="form-select form-select-lg" id="moduleGroup" required>
                                                        <option value="">Loading groups...</option>
                                                    </select>
                                                    <small class="text-muted fs-12 mt-2 d-block">
                                                        <i class="ri-information-line me-1"></i>
                                                        The module will be organized under the selected group in the navigation menu
                                                    </small>
                                                </div>

                                                <!-- Selected Group Details -->
                                                <div class="col-12" id="selectedGroupDetails" style="display: none;">
                                                    <div class="border rounded p-3 bg-light">
                                                        <h6 class="fw-semibold mb-2 text-success">
                                                            <i class="ri-checkbox-circle-line me-1"></i>Selected Group
                                                        </h6>
                                                        <div class="d-flex align-items-center mb-2">
                                                            <i id="selectedGroupIcon" class="fs-24 me-3 text-primary"></i>
                                                            <div>
                                                                <p class="mb-0 fw-semibold" id="selectedGroupName">Group Name</p>
                                                                <small class="text-muted" id="selectedGroupDescription">Description</small>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2">
                                                            <span class="badge bg-primary-transparent">
                                                                <i class="ri-map-pin-line me-1"></i>
                                                                Territory: <span id="selectedGroupTerritory">Diocese</span>
                                                            </span>
                                                            <span class="badge bg-success-transparent ms-2">
                                                                <i class="ri-folder-line me-1"></i>
                                                                Modules: <span id="selectedGroupModulesCount">0</span>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Help Text -->
                                    <div class="alert alert-secondary bg-secondary-transparent border-0 mt-3 p-3">
                                        <div class="d-flex align-items-start">
                                            <i class="ri-lightbulb-line fs-18 me-2 text-secondary"></i>
                                            <div class="flex-fill">
                                                <h6 class="alert-heading mb-1 fw-semibold">What are Module Groups?</h6>
                                                <small class="text-dark">
                                                    Module groups organize related modules together in the navigation menu.
                                                    For example, all financial modules would be grouped under "Finance".
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 3: MODULE STATUS -->
                                <div class="tab-pane fade" id="module-status" role="tabpanel">
                                    <div class="d-flex align-items-center justify-content-between p-4 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this module
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="moduleActive" checked>
                                        </div>
                                    </div>

                                    <div class="alert alert-warning bg-warning-transparent border-0 mt-3 p-3">
                                        <div class="d-flex align-items-start">
                                            <i class="ri-information-line fs-18 me-2 text-warning"></i>
                                            <div class="flex-fill">
                                                <h6 class="alert-heading mb-1 fw-semibold">Status Information</h6>
                                                <small class="text-dark">
                                                    Inactive modules will not appear in the navigation menu.
                                                    All submodules under this module will also be hidden.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 3: AUTO-GENERATE PERMISSIONS -->
                                <div class="tab-pane fade" id="module-permissions" role="tabpanel">
                                    <div class="form-check mb-3 p-3 border rounded bg-light">
                                        <input class="form-check-input" type="checkbox" id="autoGeneratePermissions" checked>
                                        <label class="form-check-label fw-semibold" for="autoGeneratePermissions">
                                            <i class="ri-magic-line me-1 text-warning"></i>
                                            Automatically create standard permissions for this module
                                        </label>
                                    </div>

                                    <div id="permissionOptions">
                                        <p class="text-muted fs-13 mb-3">
                                            <i class="ri-checkbox-circle-line me-1"></i>Select permissions to generate:
                                        </p>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="create" id="permCreate" checked>
                                                    <label class="form-check-label" for="permCreate">
                                                        <i class="ri-add-circle-line me-1 text-success"></i>Create
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="read" id="permRead" checked>
                                                    <label class="form-check-label" for="permRead">
                                                        <i class="ri-eye-line me-1 text-info"></i>Read
                                                    </label>
                                                </div>
                                                <div class="form-check p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="update" id="permUpdate" checked>
                                                    <label class="form-check-label" for="permUpdate">
                                                        <i class="ri-edit-line me-1 text-primary"></i>Update
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="delete" id="permDelete" checked>
                                                    <label class="form-check-label" for="permDelete">
                                                        <i class="ri-delete-bin-line me-1 text-danger"></i>Delete
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="approve" id="permApprove">
                                                    <label class="form-check-label" for="permApprove">
                                                        <i class="ri-checkbox-circle-line me-1 text-success"></i>Approve
                                                    </label>
                                                </div>
                                                <div class="form-check p-2 border rounded">
                                                    <input class="form-check-input permission-checkbox" type="checkbox"
                                                           value="export" id="permExport">
                                                    <label class="form-check-label" for="permExport">
                                                        <i class="ri-file-download-line me-1 text-warning"></i>Export
                                                    </label>
                                                </div>
                                            </div>
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
                        <button type="button" class="btn btn-secondary" id="modulePrevBtn" style="display: none;">
                            <i class="ri-arrow-left-line me-1"></i>Previous
                        </button>
                        <button type="button" class="btn btn-primary" id="moduleNextBtn">
                            Next<i class="ri-arrow-right-line ms-1"></i>
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-4" id="saveModuleBtn" style="display: none;">
                            <i class="ri-save-line me-1"></i>Save Module
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================================ -->
        <!-- CREATE/EDIT SUBMODULE MODAL (Fresh Implementation) -->
        <!-- ============================================================================ -->
        <div class="modal fade" id="submoduleModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- Modal Header -->
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-file-add-line me-2"></i><span id="submoduleModalTitle">Create Submodule</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-0">
                        <form id="submoduleForm">
                            <input type="hidden" id="submoduleId">
                            <input type="hidden" id="submoduleModuleId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 m-3 mb-0 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Submodule Creation Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Submodules represent specific pages or features</li>
                                            <li class="mb-1">Path is auto-generated from title (module-name/page-name)</li>
                                            <li class="mb-0">Auto-generate permissions for quick setup</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Navigation -->
                            <div class="p-3 border-bottom">
                                <ul class="nav nav-tabs mb-0 tab-style-6" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="sm-tab-basic" data-bs-toggle="tab"
                                                data-bs-target="#sm-content-basic" type="button" role="tab">
                                            <i class="ri-file-text-line me-1"></i>Basic Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="sm-tab-status" data-bs-toggle="tab"
                                                data-bs-target="#sm-content-status" type="button" role="tab">
                                            <i class="ri-settings-3-line me-1"></i>Status
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="sm-tab-permissions" data-bs-toggle="tab"
                                                data-bs-target="#sm-content-permissions" type="button" role="tab">
                                            <i class="ri-key-2-line me-1"></i>Permissions
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <!-- Tab Content -->
                            <div class="tab-content p-4">

                                <!-- TAB 1: BASIC INFORMATION -->
                                <div class="tab-pane fade show active" id="sm-content-basic" role="tabpanel">
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-semibold">
                                                <i class="ri-file-text-line me-2 text-primary"></i>Basic Information
                                            </h6>
                                        </div>
                                        <div class="card-body p-4">
                                            <div class="row g-3">
                                                <!-- Title -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold mb-2">
                                                        Title <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light">
                                                            <i class="ri-file-line text-primary"></i>
                                                        </span>
                                                        <input type="text" class="form-control" id="submoduleTitle"
                                                               placeholder="e.g., Budget Management" required>
                                                    </div>
                                                </div>

                                                <!-- Path (Auto-generated) -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold mb-2">
                                                        Path <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light">
                                                            <i class="ri-link text-success"></i>
                                                        </span>
                                                        <input type="text" class="form-control bg-light" id="submodulePath"
                                                               placeholder="/auto-generated-from-title" readonly required>
                                                    </div>
                                                    <small class="text-muted fs-12 mt-1 d-block">
                                                        <i class="ri-magic-line me-1 text-warning"></i>Auto-generated as: module-name/page-name
                                                    </small>
                                                </div>

                                                <!-- Description -->
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold mb-2">Description</label>
                                                    <textarea class="form-control" id="submoduleDescription" rows="3"
                                                              placeholder="Brief description..."></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 2: STATUS -->
                                <div class="tab-pane fade" id="sm-content-status" role="tabpanel">
                                    <div class="d-flex align-items-center justify-content-between p-4 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this submodule
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="submoduleActive" checked>
                                        </div>
                                    </div>

                                    <div class="alert alert-warning bg-warning-transparent border-0 mt-3 p-3">
                                        <div class="d-flex align-items-start">
                                            <i class="ri-information-line fs-18 me-2 text-warning"></i>
                                            <div class="flex-fill">
                                                <h6 class="alert-heading mb-1 fw-semibold">Status Information</h6>
                                                <small class="text-dark">
                                                    Inactive submodules will not appear in the navigation menu or be accessible to users.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 3: PERMISSIONS -->
                                <div class="tab-pane fade" id="sm-content-permissions" role="tabpanel">
                                    <div class="form-check mb-3 p-3 border rounded bg-light">
                                        <input class="form-check-input" type="checkbox" id="autoGenerateSubmodulePermissions" checked>
                                        <label class="form-check-label fw-semibold" for="autoGenerateSubmodulePermissions">
                                            <i class="ri-magic-line me-1 text-warning"></i>
                                            Automatically create standard permissions for this submodule
                                        </label>
                                    </div>

                                    <div id="submodulePermissionOptions">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="create" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-add-circle-line me-1 text-success"></i>Create
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="read" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-eye-line me-1 text-primary"></i>Read
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="update" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-edit-line me-1 text-warning"></i>Update
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="delete" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-delete-bin-line me-1 text-danger"></i>Delete
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="approve">
                                                    <label class="form-check-label">
                                                        <i class="ri-checkbox-circle-line me-1 text-info"></i>Approve
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input submodule-permission-checkbox"
                                                           type="checkbox" value="export">
                                                    <label class="form-check-label">
                                                        <i class="ri-download-line me-1 text-secondary"></i>Export
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </form>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-light border-top p-3">
                        <div class="d-flex justify-content-between w-100">
                            <div>
                                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                                    <i class="ri-close-line me-1"></i>Cancel
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary me-2" id="sm-btn-prev" style="display: none;">
                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="sm-btn-next">
                                    Next<i class="ri-arrow-right-line ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-success btn-lg px-4" id="sm-btn-save"
                                        onclick="ModuleManagement.saveSubmodule()" style="display: none;">
                                    <i class="ri-save-line me-1"></i>Save Submodule
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================================ -->
        <!-- CREATE/EDIT SUB-SUBMODULE MODAL -->
        <!-- ============================================================================ -->
        <div class="modal fade" id="subSubmoduleModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- Modal Header -->
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-file-add-line me-2"></i><span id="subSubmoduleModalTitle">Create Sub-submodule</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-0">
                        <form id="subSubmoduleForm">
                            <input type="hidden" id="subSubmoduleId">
                            <input type="hidden" id="subSubmoduleSubmoduleId">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 m-3 mb-0 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Sub-submodule Creation Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Sub-submodules are nested features within submodules</li>
                                            <li class="mb-1">Path is auto-generated from parent and title</li>
                                            <li class="mb-0">Auto-generate permissions for quick setup</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Navigation -->
                            <div class="p-3 border-bottom">
                                <ul class="nav nav-tabs mb-0 tab-style-6" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="ssm-tab-basic" data-bs-toggle="tab"
                                                data-bs-target="#ssm-content-basic" type="button" role="tab">
                                            <i class="ri-file-text-line me-1"></i>Basic Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="ssm-tab-status" data-bs-toggle="tab"
                                                data-bs-target="#ssm-content-status" type="button" role="tab">
                                            <i class="ri-settings-3-line me-1"></i>Status
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="ssm-tab-permissions" data-bs-toggle="tab"
                                                data-bs-target="#ssm-content-permissions" type="button" role="tab">
                                            <i class="ri-key-2-line me-1"></i>Permissions
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <!-- Tab Content -->
                            <div class="tab-content p-4">

                                <!-- TAB 1: BASIC INFORMATION -->
                                <div class="tab-pane fade show active" id="ssm-content-basic" role="tabpanel">
                                    <div class="card border mb-4">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-semibold">
                                                <i class="ri-file-text-line me-2 text-info"></i>Basic Information
                                            </h6>
                                        </div>
                                        <div class="card-body p-4">
                                            <div class="row g-3">
                                                <!-- Title -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold mb-2">
                                                        Title <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light">
                                                            <i class="ri-file-line text-info"></i>
                                                        </span>
                                                        <input type="text" class="form-control" id="subSubmoduleTitle"
                                                               placeholder="e.g., View Details" required>
                                                    </div>
                                                </div>

                                                <!-- Path (Auto-generated) -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold mb-2">
                                                        Path <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light">
                                                            <i class="ri-link text-success"></i>
                                                        </span>
                                                        <input type="text" class="form-control bg-light" id="subSubmodulePath"
                                                               placeholder="/auto-generated-from-title" readonly required>
                                                    </div>
                                                    <small class="text-muted fs-12 mt-1 d-block">
                                                        <i class="ri-magic-line me-1 text-warning"></i>Auto-generated as: submodule-path/page-name
                                                    </small>
                                                </div>

                                                <!-- Description -->
                                                <div class="col-12">
                                                    <label class="form-label fw-semibold mb-2">Description</label>
                                                    <textarea class="form-control" id="subSubmoduleDescription" rows="3"
                                                              placeholder="Brief description..."></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 2: STATUS -->
                                <div class="tab-pane fade" id="ssm-content-status" role="tabpanel">
                                    <div class="d-flex align-items-center justify-content-between p-4 border rounded bg-light">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">
                                                <i class="ri-toggle-line me-2 text-success"></i>Active Status
                                            </h6>
                                            <p class="text-muted mb-0 fs-13">
                                                Toggle to activate or deactivate this sub-submodule
                                            </p>
                                        </div>
                                        <div class="form-check form-switch form-switch-lg">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="subSubmoduleActive" checked>
                                        </div>
                                    </div>

                                    <div class="alert alert-warning bg-warning-transparent border-0 mt-3 p-3">
                                        <div class="d-flex align-items-start">
                                            <i class="ri-information-line fs-18 me-2 text-warning"></i>
                                            <div class="flex-fill">
                                                <h6 class="alert-heading mb-1 fw-semibold">Status Information</h6>
                                                <small class="text-dark">
                                                    Inactive sub-submodules will not appear in the navigation menu or be accessible to users.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- TAB 3: PERMISSIONS -->
                                <div class="tab-pane fade" id="ssm-content-permissions" role="tabpanel">
                                    <div class="form-check mb-3 p-3 border rounded bg-light">
                                        <input class="form-check-input" type="checkbox" id="autoGenerateSubSubmodulePermissions" checked>
                                        <label class="form-check-label fw-semibold" for="autoGenerateSubSubmodulePermissions">
                                            <i class="ri-magic-line me-1 text-warning"></i>
                                            Automatically create standard permissions for this sub-submodule
                                        </label>
                                    </div>

                                    <div id="subSubmodulePermissionOptions">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="create" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-add-circle-line me-1 text-success"></i>Create
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="read" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-eye-line me-1 text-primary"></i>Read
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="update" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-edit-line me-1 text-warning"></i>Update
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="delete" checked>
                                                    <label class="form-check-label">
                                                        <i class="ri-delete-bin-line me-1 text-danger"></i>Delete
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="approve">
                                                    <label class="form-check-label">
                                                        <i class="ri-checkbox-circle-line me-1 text-info"></i>Approve
                                                    </label>
                                                </div>
                                                <div class="form-check mb-2 p-2 border rounded">
                                                    <input class="form-check-input sub-submodule-permission-checkbox"
                                                           type="checkbox" value="export">
                                                    <label class="form-check-label">
                                                        <i class="ri-download-line me-1 text-secondary"></i>Export
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </form>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-light border-top p-3">
                        <div class="d-flex justify-content-between w-100">
                            <div>
                                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                                    <i class="ri-close-line me-1"></i>Cancel
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary me-2" id="ssm-btn-prev" style="display: none;">
                                    <i class="ri-arrow-left-line me-1"></i>Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="ssm-btn-next">
                                    Next<i class="ri-arrow-right-line ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-success btn-lg px-4" id="ssm-btn-save"
                                        onclick="ModuleManagement.saveSubSubmodule()" style="display: none;">
                                    <i class="ri-save-line me-1"></i>Save Sub-submodule
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Start -->
        <?php include '../../../includes/footer.php' ?>
        <!-- Footer End -->
    </div>

    <!-- ============================================ -->
    <!-- DELETE CONFIRMATION MODALS -->
    <!-- ============================================ -->

    <!-- Delete Module Confirmation Modal -->
    <div class="modal fade" id="deleteModuleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-semibold text-white">
                        <i class="ri-delete-bin-line me-2"></i>Delete Module
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

                    <p class="mb-2">Are you sure you want to delete the module:</p>
                    <p class="fw-semibold text-primary fs-16 mb-3" id="deleteModuleName"></p>

                    <div class="alert alert-warning bg-warning-transparent border-0 p-3">
                        <p class="mb-1 fw-semibold text-dark">
                            <i class="ri-information-line me-1"></i>This will also delete:
                        </p>
                        <ul class="mb-0 ps-3 text-dark fs-13">
                            <li>All submodules under this module</li>
                            <li>All sub-submodules</li>
                            <li>All associated permissions</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteModuleBtn">
                        <i class="ri-delete-bin-line me-1"></i>Yes, Delete Module
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Submodule Confirmation Modal -->
    <div class="modal fade" id="deleteSubmoduleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-semibold text-white">
                        <i class="ri-delete-bin-line me-2"></i>Delete Submodule
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

                    <p class="mb-2">Are you sure you want to delete the submodule:</p>
                    <p class="fw-semibold text-primary fs-16 mb-3" id="deleteSubmoduleName"></p>

                    <div class="alert alert-warning bg-warning-transparent border-0 p-3">
                        <p class="mb-1 fw-semibold text-dark">
                            <i class="ri-information-line me-1"></i>This will also delete:
                        </p>
                        <ul class="mb-0 ps-3 text-dark fs-13">
                            <li>All sub-submodules under this submodule</li>
                            <li>All associated permissions</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteSubmoduleBtn">
                        <i class="ri-delete-bin-line me-1"></i>Yes, Delete Submodule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Sub-submodule Confirmation Modal -->
    <div class="modal fade" id="deleteSubSubmoduleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-semibold text-white">
                        <i class="ri-delete-bin-line me-2"></i>Delete Sub-submodule
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

                    <p class="mb-2">Are you sure you want to delete the sub-submodule:</p>
                    <p class="fw-semibold text-primary fs-16 mb-3" id="deleteSubSubmoduleName"></p>

                    <div class="alert alert-warning bg-warning-transparent border-0 p-3">
                        <p class="mb-1 fw-semibold text-dark">
                            <i class="ri-information-line me-1"></i>This will also delete:
                        </p>
                        <ul class="mb-0 ps-3 text-dark fs-13">
                            <li>All associated permissions</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger btn-lg px-4" id="confirmDeleteSubSubmoduleBtn">
                        <i class="ri-delete-bin-line me-1"></i>Yes, Delete Sub-submodule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll To Top -->
    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>
    <!-- Scroll To Top -->

    <!-- Popper JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/%40popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Defaultmenu JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/defaultmenu.min.js"></script>

    <!-- Node Waves JS-->
    <script src="http://localhost:8080/makueni-west/assets/libs/node-waves/waves.min.js"></script>

    <!-- Sticky JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/sticky.js"></script>

    <!-- Simplebar JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/simplebar.js"></script>

    <!-- Color Picker JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/%40simonwep/pickr/pickr.es5.min.js"></script>

    <!-- Custom-Switcher JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom-switcher.min.js"></script>

    <!-- Custom JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/custom.js"></script>

    <!-- Toast JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/Toasts.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/utils/toast.js"></script>

    <!-- Constants -->
    <script src="http://localhost:8080/makueni-west/assets/js/config/constants.js"></script>

    <!-- Auth Helpers -->
    <script src="http://localhost:8080/makueni-west/assets/js/utils/auth-helpers.js"></script>

    <!-- Logout Handler -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/authentication/logout.js"></script>

    <!-- API Handler -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/system-administration/api-handler.js"></script>

    <!-- Dragula JS for drag-and-drop -->
    <script src="http://localhost:8080/makueni-west/assets/libs/dragula/dragula.min.js"></script>

    <!-- Module Management JS -->
    <script
        src="http://localhost:8080/makueni-west/assets/js/pages/system-administration/module-management/modules.js">
    </script>

    <!-- Delete Modals JS -->
    <script
        src="http://localhost:8080/makueni-west/assets/js/pages/system-administration/module-management/delete-modals.js">
    </script>

    <!-- Initialize Modules Page -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit for all modules to load
            setTimeout(function() {
                if (typeof ModuleManagement !== 'undefined' && typeof ModuleManagement.init === 'function') {
                    console.log('📁 Initializing Module Management...');
                    ModuleManagement.init();
                } else {
                    console.error('❌ ModuleManagement not loaded');
                }
            }, 100);
        });
    </script>
</body>

</html>
