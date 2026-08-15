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
        YNEX - Blazor Server Bootstrap 5 Premium Admin & Dashboard Template
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
                                    <a href="dashboard"><i class="ri-home-4-line"></i> Home</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="javascript:void(0);">System Administration</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">User Management</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Main Card with Tabs -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card custom-card">
                            <div class="card-body p-0">

                                <!-- Page Header (No Tabs - Single Users View) -->
                                <div class="p-3 border-bottom border-block-end-dashed">
                                    <div class="d-flex align-items-center">
                                        <i class="ri-user-line me-2 text-primary fs-20"></i>
                                        <h5 class="mb-0 fw-semibold">User Management</h5>
                                    </div>
                                </div>

                                <!-- Content -->
                                <div class="p-3">

                                            <!-- Users Table Header -->
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <div>
                                                    <h5 class="mb-1 fw-semibold">
                                                        <i class="ri-group-line me-2 text-primary"></i>User Accounts
                                                    </h5>
                                                    <p class="text-muted mb-0 fs-13">Manage system users and their
                                                        assignments</p>
                                                </div>
                                                <a href="<?= SITE_URL ?>/diocese/settings/admin/add-user" class="btn btn-primary">
                                                    <i class="ri-user-add-line me-1"></i>Create User
                                                </a>
                                            </div>

                                            <!-- Filters -->
                                            <div class="card border mb-3">
                                                <div class="card-body p-3">
                                                    <div class="row g-3 align-items-end">
                                                        <div class="col-md-3">
                                                            <label class="form-label fw-semibold mb-1">Search</label>
                                                            <input type="text" class="form-control" id="userSearchInput"
                                                                placeholder="Search by name, email, code...">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label fw-semibold mb-1">Territory</label>
                                                            <select class="form-select" id="userTerritoryFilter">
                                                                <option value="">All Territories</option>
                                                                <!-- Populated by JS -->
                                                            </select>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label fw-semibold mb-1">Role</label>
                                                            <select class="form-select" id="userRoleFilter">
                                                                <option value="">All Roles</option>
                                                                <!-- Populated by JS -->
                                                            </select>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label fw-semibold mb-1">Status</label>
                                                            <select class="form-select" id="userStatusFilter">
                                                                <option value="">All Status</option>
                                                                <option value="active">Active</option>
                                                                <option value="inactive">Inactive</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <button class="btn btn-primary w-100" id="applyUserFilters">
                                                                <i class="ri-filter-3-line me-1"></i>Apply Filters
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Users Table -->
                                            <div class="card border">
                                                <div class="card-body p-0">
                                                    <div class="table-responsive">
                                                        <table class="table text-nowrap mb-0" id="usersTable">
                                                            <thead class="bg-light">
                                                                <tr>
                                                                    <th class="fw-semibold">#</th>
                                                                    <th class="fw-semibold">Name</th>
                                                                    <th class="fw-semibold">Email / Phone</th>
                                                                    <th class="fw-semibold">Employee Code</th>
                                                                    <th class="fw-semibold">Primary Assignment</th>
                                                                    <th class="fw-semibold">Status</th>
                                                                    <th class="fw-semibold text-center">Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody id="usersTableBody">
                                                                <!-- Populated by JS -->
                                                                <tr>
                                                                    <td colspan="7" class="text-center py-5">
                                                                        <div class="spinner-border text-primary"
                                                                            role="status">
                                                                            <span
                                                                                class="visually-hidden">Loading...</span>
                                                                        </div>
                                                                        <p class="text-muted mt-2 mb-0">Loading users...
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
                                                <div class="text-muted fs-13" id="usersPaginationInfo">
                                                    Showing 1 to 10 of 50 entries
                                                </div>
                                                <nav>
                                                    <ul class="pagination mb-0" id="usersPagination">
                                                        <!-- Populated by JS -->
                                                    </ul>
                                                </nav>
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


        <!-- ============================================ -->
        <!-- MODALS (USERS ONLY) -->
        <!-- ============================================ -->
        <!-- MODAL 1: CREATE USER -->
        <!-- ============================================ -->
        <div class="modal fade" id="createUserModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-user-add-line me-2"></i>Create New User
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="createUserForm">
                        <div class="modal-body p-4">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">User Creation Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Employee code will be auto-generated if not provided</li>
                                            <li class="mb-1">Default password: <strong>Diocese@2025</strong> (if not
                                                set)</li>
                                            <li class="mb-0">User will be prompted to change password on first login
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 1: BASIC INFORMATION -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-user-line me-2 text-primary"></i>Basic Information
                                    </h6>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <!-- First Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                First Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="createUserFirstname"
                                                placeholder="Enter first name" required>
                                        </div>

                                        <!-- Last Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Last Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="createUserLastname"
                                                placeholder="Enter last name" required>
                                        </div>

                                        <!-- Email -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Email Address
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-mail-line text-primary"></i>
                                                </span>
                                                <input type="email" class="form-control" id="createUserEmail"
                                                    placeholder="user@makueniwestdiocese.or.ke">
                                            </div>
                                            <small class="text-muted fs-12">
                                                <i class="ri-information-line me-1"></i>Optional - Used for
                                                notifications
                                            </small>
                                        </div>

                                        <!-- Phone -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Phone Number
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-phone-line text-success"></i>
                                                </span>
                                                <input type="tel" class="form-control" id="createUserPhone"
                                                    placeholder="+254712345678">
                                            </div>
                                        </div>

                                        <!-- Position -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Position/Title
                                            </label>
                                            <input type="text" class="form-control" id="createUserPosition"
                                                placeholder="e.g., Senior Pastor, Council Member">
                                        </div>

                                        <!-- Username -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Username
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-user-3-line text-info"></i>
                                                </span>
                                                <input type="text" class="form-control" id="createUserUsername"
                                                    placeholder="username">
                                            </div>
                                            <small class="text-muted fs-12">
                                                <i class="ri-information-line me-1"></i>Optional - Can use email or
                                                employee code to login
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 2: SECURITY CREDENTIALS -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-shield-keyhole-line me-2 text-warning"></i>Security Credentials
                                    </h6>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <!-- Employee Code -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Employee Code (6 digits)
                                            </label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="createUserEmployeeCode"
                                                    placeholder="Auto-generated" maxlength="6" pattern="[0-9]{6}">
                                                <button type="button" class="btn btn-primary"
                                                    id="generateEmployeeCodeBtn">
                                                    <i class="ri-refresh-line me-1"></i>Generate
                                                </button>
                                            </div>
                                            <small class="text-muted fs-12">
                                                <i class="ri-information-line me-1"></i>Leave empty to auto-generate
                                            </small>
                                        </div>

                                        <!-- Password -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Initial Password
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="createUserPassword"
                                                    placeholder="Default: Diocese@2025" minlength="8">
                                                <button type="button" class="btn btn-primary" id="generatePasswordBtn">
                                                    <i class="ri-key-2-line me-1"></i>Generate
                                                </button>
                                                <button type="button" class="btn btn-light border"
                                                    id="togglePasswordBtn">
                                                    <i class="ri-eye-line"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted fs-12">
                                                <i class="ri-information-line me-1"></i>Leave empty for default password
                                            </small>
                                        </div>

                                        <!-- Force Password Change -->
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                    id="createUserMustChangePassword" checked>
                                                <label class="form-check-label fw-semibold"
                                                    for="createUserMustChangePassword">
                                                    Force user to change password on first login
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 3: ACCOUNT STATUS -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-settings-3-line me-2 text-success"></i>Account Status
                                    </h6>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <!-- Status -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Account Status
                                            </label>
                                            <select class="form-select" id="createUserStatus">
                                                <option value="active" selected>Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 4: TERRITORIAL ASSIGNMENT (OPTIONAL) -->
                            <div class="card border">
                                <div class="card-header bg-light border-bottom">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0 fw-semibold">
                                            <i class="ri-map-pin-line me-2 text-danger"></i>Territorial Assignment
                                        </h6>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="assignTerritoryToggle">
                                            <label class="form-check-label fw-semibold" for="assignTerritoryToggle">
                                                Assign now
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-4" id="territoryAssignmentSection" style="display: none;">
                                    <div class="alert alert-warning bg-warning-transparent border-0 mb-3 p-3">
                                        <div class="d-flex align-items-center">
                                            <i class="ri-information-line fs-18 me-2 text-warning"></i>
                                            <small class="mb-0 text-dark">
                                                You can skip this and assign territories later from the user details
                                                page
                                            </small>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <!-- Territory -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Territory <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select" id="createTerritoryId">
                                                <option value="">Select territory...</option>
                                                <!-- Populated by JS -->
                                            </select>
                                        </div>

                                        <!-- Role -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Role <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select" id="createRoleId">
                                                <option value="">Select role...</option>
                                                <!-- Populated by JS based on territory -->
                                            </select>
                                        </div>

                                        <!-- Assignment Type -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Assignment Type
                                            </label>
                                            <select class="form-select" id="createAssignmentType">
                                                <option value="primary" selected>Primary</option>
                                                <option value="secondary">Secondary</option>
                                                <option value="temporary">Temporary</option>
                                            </select>
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
                                <i class="ri-user-add-line me-1"></i>Create User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- MODAL 2: EDIT USER -->
        <!-- ============================================ -->
        <div class="modal fade" id="editUserModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-edit-line me-2"></i>Edit User
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="editUserForm">
                        <input type="hidden" id="editUserId">
                        <div class="modal-body p-4">

                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Edit Guidelines</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Leave password empty to keep current password</li>
                                            <li class="mb-1">Territorial assignments are managed separately</li>
                                            <li class="mb-0">Changes take effect immediately</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 1: BASIC INFORMATION -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-user-line me-2 text-primary"></i>Basic Information
                                    </h6>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <!-- First Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                First Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="editUserFirstname" required>
                                        </div>

                                        <!-- Last Name -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Last Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="editUserLastname" required>
                                        </div>

                                        <!-- Email -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Email Address
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-mail-line text-primary"></i>
                                                </span>
                                                <input type="email" class="form-control" id="editUserEmail">
                                            </div>
                                        </div>

                                        <!-- Phone -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Phone Number
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-phone-line text-success"></i>
                                                </span>
                                                <input type="tel" class="form-control" id="editUserPhone">
                                            </div>
                                        </div>

                                        <!-- Position -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Position/Title
                                            </label>
                                            <input type="text" class="form-control" id="editUserPosition">
                                        </div>

                                        <!-- Username -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Username
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light">
                                                    <i class="ri-user-3-line text-info"></i>
                                                </span>
                                                <input type="text" class="form-control" id="editUserUsername">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 2: SECURITY (OPTIONAL UPDATE) -->
                            <div class="card border mb-4">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-shield-keyhole-line me-2 text-warning"></i>Security Credentials
                                    </h6>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <!-- Employee Code -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                Employee Code (6 digits)
                                            </label>
                                            <input type="text" class="form-control" id="editUserEmployeeCode" maxlength="6"
                                                pattern="[0-9]{6}">
                                            <small class="text-muted fs-12">
                                                <i class="ri-error-warning-line me-1 text-warning"></i>Changing this
                                                will affect user login
                                            </small>
                                        </div>

                                        <!-- Password (Optional) -->
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2">
                                                New Password (Optional)
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="editUserPassword"
                                                    placeholder="Leave empty to keep current" minlength="8">
                                                <button type="button" class="btn btn-light border"
                                                    id="toggleEditPassword">
                                                    <i class="ri-eye-line"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted fs-12">
                                                <i class="ri-information-line me-1"></i>Only fill if you want to change
                                                password
                                            </small>
                                        </div>

                                        <!-- Force Password Change -->
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                    id="editUserMustChangePassword">
                                                <label class="form-check-label fw-semibold"
                                                    for="editUserMustChangePassword">
                                                    Force user to change password on next login
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION 3: ACCOUNT STATUS -->
                            <div class="card border">
                                <div class="card-header bg-light border-bottom">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="ri-settings-3-line me-2 text-success"></i>Account Status
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <!-- Account Status Toggle -->
                                        <div class="col-12">
                                            <label class="form-label fw-semibold mb-3">Account Status</label>
                                            <div class="d-flex align-items-center justify-content-between p-3 border rounded">
                                                <div>
                                                    <h6 class="mb-1 fw-semibold" id="editStatusLabel">Active</h6>
                                                    <small class="text-muted">Toggle to activate or deactivate this account</small>
                                                </div>
                                                <div class="form-check form-switch form-switch-lg">
                                                    <input class="form-check-input" type="checkbox" role="switch" 
                                                           id="editUserStatus" checked>
                                                </div>
                                            </div>
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

        <!-- ============================================ -->
        <!-- MODAL 3: VIEW USER DETAILS -->
        <!-- ============================================ -->
        <div class="modal fade" id="viewUserModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-user-line me-2"></i>
                            <span id="viewUserFullName">User Details</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">

                        <!-- User Profile Header -->
                        <div class="bg-light border-bottom p-4">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="avatar avatar-xxl bg-primary-transparent text-primary rounded-circle"
                                        id="viewUserAvatar">
                                        <!-- Initials populated by JS -->
                                    </span>
                                </div>
                                <div class="col">
                                    <h4 class="mb-1 fw-bold" id="viewUserName"></h4>
                                    <div class="mb-2">
                                        <span class="badge bg-primary me-2" id="viewUserEmployeeCode"></span>
                                        <span class="badge" id="viewUserStatus"></span>
                                    </div>
                                    <div class="text-muted">
                                        <small class="d-block mb-1">
                                            <i class="ri-mail-line me-1"></i>
                                            <span id="viewUserEmail"></span>
                                        </small>
                                        <small class="d-block mb-1">
                                            <i class="ri-phone-line me-1"></i>
                                            <span id="viewUserPhone"></span>
                                        </small>
                                        <small class="d-block">
                                            <i class="ri-briefcase-line me-1"></i>
                                            <span id="viewUserPosition"></span>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="d-flex flex-column gap-2">
                                        <button class="btn btn-primary btn-sm"
                                            onclick="UserManagement.editUserFromView()">
                                            <i class="ri-edit-line me-1"></i>Edit Profile
                                        </button>
                                        <button class="btn btn-warning btn-sm"
                                            onclick="UserManagement.resetPasswordFromView()">
                                            <i class="ri-lock-password-line me-1"></i>Reset Password
                                        </button>
                                        <button class="btn btn-success btn-sm"
                                            onclick="UserManagement.manageAssignmentsFromView()">
                                            <i class="ri-map-pin-line me-1"></i>Manage Assignments
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Navigation -->
                        <div class="p-3 border-bottom">
                            <ul class="nav nav-tabs tab-style-6 mb-0" id="viewUserTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" data-bs-toggle="tab"
                                        data-bs-target="#viewAssignmentsTab">
                                        <i class="ri-map-pin-line me-1"></i>Assignments
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#viewPermissionsTab">
                                        <i class="ri-shield-keyhole-line me-1"></i>Permissions
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#viewSecurityTab">
                                        <i class="ri-lock-line me-1"></i>Security
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#viewAuditTab">
                                        <i class="ri-history-line me-1"></i>Audit Trail
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="p-4">
                            <div class="tab-content">

                                <!-- ASSIGNMENTS TAB -->
                                <div class="tab-pane fade show active" id="viewAssignmentsTab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0 fw-semibold">Active Territorial Assignments</h6>
                                        <button class="btn btn-primary btn-sm" onclick="UserManagement.addAssignment()">
                                            <i class="ri-add-line me-1"></i>Add Assignment
                                        </button>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover" id="viewAssignmentsTable">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Territory</th>
                                                    <th>Role</th>
                                                    <th>Type</th>
                                                    <th>Assigned Date</th>
                                                    <th>Assigned By</th>
                                                    <th class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="viewAssignmentsTableBody">
                                                <!-- Populated by JS -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Assignment History -->
                                    <div class="mt-4">
                                        <h6 class="mb-3 fw-semibold">Assignment History</h6>
                                        <div id="viewAssignmentHistory">
                                            <!-- Populated by JS -->
                                        </div>
                                    </div>
                                </div>

                                <!-- PERMISSIONS TAB -->
                                <div class="tab-pane fade" id="viewPermissionsTab">
                                    <h6 class="mb-3 fw-semibold">User Permissions (Aggregated from Roles)</h6>
                                    <div id="viewPermissionsList">
                                        <!-- Populated by JS -->
                                    </div>
                                </div>

                                <!-- SECURITY TAB -->
                                <div class="tab-pane fade" id="viewSecurityTab">

                                    <!-- Password Status -->
                                    <div class="card border mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0 fw-semibold">
                                                <i class="ri-key-2-line me-2 text-warning"></i>Password Status
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Last Changed</small>
                                                    <strong id="viewPasswordLastChanged">-</strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Expires On</small>
                                                    <strong id="viewPasswordExpires">-</strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Must Change</small>
                                                    <span id="viewPasswordMustChange">-</span>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Days Until Expiry</small>
                                                    <strong id="viewPasswordDaysRemaining">-</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Login Activity -->
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0 fw-semibold">
                                                <i class="ri-login-box-line me-2 text-success"></i>Login Activity
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Last Login</small>
                                                    <strong id="viewLastLogin">-</strong>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Failed Attempts</small>
                                                    <span id="viewLoginAttempts">-</span>
                                                </div>
                                                <div class="col-12">
                                                    <div class="d-flex gap-2">
                                                        <button class="btn btn-warning btn-sm"
                                                            onclick="UserManagement.resetPasswordFromView()">
                                                            <i class="ri-lock-password-line me-1"></i>Reset Password
                                                        </button>
                                                        <button class="btn btn-info btn-sm"
                                                            onclick="UserManagement.unlockAccount()">
                                                            <i class="ri-lock-unlock-line me-1"></i>Unlock Account
                                                        </button>
                                                        <button class="btn btn-danger btn-sm"
                                                            onclick="UserManagement.forcePasswordChange()">
                                                            <i class="ri-shield-keyhole-line me-1"></i>Force Password
                                                            Change
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- AUDIT TRAIL TAB -->
                                <div class="tab-pane fade" id="viewAuditTab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0 fw-semibold">Activity History</h6>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-light border" data-audit-type="all">All</button>
                                            <button class="btn btn-light border" data-audit-type="login">Login</button>
                                            <button class="btn btn-light border"
                                                data-audit-type="password">Password</button>
                                            <button class="btn btn-light border"
                                                data-audit-type="profile">Profile</button>
                                            <button class="btn btn-light border"
                                                data-audit-type="status">Status</button>
                                        </div>
                                    </div>

                                    <div id="viewAuditTimeline">
                                        <!-- Populated by JS -->
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- MODAL 4: RESET PASSWORD (ADMIN ACTION) -->
        <!-- ============================================ -->
        <div class="modal fade" id="resetPasswordModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title fw-semibold">
                            <i class="ri-lock-password-line me-2"></i>Reset User Password
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="resetPasswordForm">
                        <input type="hidden" id="resetPasswordUserId">
                        <div class="modal-body p-4">

                            <!-- User Info -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-user-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-1 fw-semibold" id="resetPasswordUserName"></h6>
                                        <small class="text-dark">
                                            <strong>Employee Code:</strong> <span
                                                id="resetPasswordUserCode"></span><br>
                                            <strong>Email:</strong> <span id="resetPasswordUserEmail"></span>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Password Options -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold mb-3">Choose Reset Method</label>

                                <!-- Option 1: Auto-generate -->
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="resetMethod" id="resetPasswordAuto"
                                        value="auto" checked>
                                    <label class="form-check-label fw-semibold" for="resetPasswordAuto">
                                        Auto-generate password (Recommended)
                                    </label>
                                </div>

                                <!-- Generated Password Display -->
                                <div id="generatedPasswordSection" class="ms-4 mb-3" style="display: none;">
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light" id="resetGeneratedPassword" readonly
                                            value="Diocese@2025">
                                        <button type="button" class="btn btn-primary" id="copyPasswordBtn">
                                            <i class="ri-file-copy-line me-1"></i>Copy
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        <i class="ri-information-line me-1"></i>Password will be shown after reset
                                    </small>
                                </div>

                                <!-- Option 2: Custom password -->
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="resetMethod"
                                        id="resetPasswordCustom" value="custom">
                                    <label class="form-check-label fw-semibold" for="resetPasswordCustom">
                                        Set custom password
                                    </label>
                                </div>

                                <!-- Custom Password Fields -->
                                <div id="customPasswordSection" class="ms-4 mt-3" style="display: none;">
                                    <div class="mb-3">
                                        <label class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="customPassword"
                                            placeholder="Enter password" minlength="8">
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Confirm Password</label>
                                        <input type="password" class="form-control" id="customPasswordConfirm"
                                            placeholder="Re-enter password">
                                    </div>
                                </div>
                            </div>

                            <!-- Force Change Checkbox -->
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="resetForceChange" checked>
                                <label class="form-check-label fw-semibold" for="resetForceChange">
                                    Force user to change password on next login
                                </label>
                            </div>

                            <!-- Warning Alert -->
                            <div class="alert alert-warning bg-warning-transparent border-0 mb-0 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-error-warning-line fs-18 me-2 text-warning"></i>
                                    <div class="flex-fill">
                                        <strong class="d-block mb-1">Security Notice</strong>
                                        <small class="text-dark">
                                            All active sessions will be terminated. User will need to login again with
                                            the new password.
                                        </small>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer bg-light border-top p-3">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                                <i class="ri-close-line me-1"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-warning btn-lg px-4">
                                <i class="ri-lock-password-line me-1"></i>Reset Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


    <!-- Popper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/%40popperjs/core/umd/popper.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

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

    <!-- ============================================================================ -->
</body>

</html>