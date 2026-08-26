<?php
// 1. FIRST: Start session & check auth (NO HTML BEFORE THIS!)
require_once __DIR__ . '/includes/session-manager.php';
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/permission-check.php';
// / 2. Check specific permission
// requirePermission('profile.read');

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

    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/glightbox/css/glightbox.min.css" />
</head>

<body>
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= asset_url('/assets/js/config/app.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/config/constants.js') ?>"></script>
    <!-- Start Switcher -->
    <?php  include "includes/start-switcher.php" ?>
    <!-- End Switcher -->

    <!-- Loader -->
    <?php include "includes/loader.php" ?>
    <!-- Loader -->

    <div class="page">
        <!-- app-header -->
        <?php include "includes/header.php" ?>
        <!-- /app-header -->
        <!-- Start::app-sidebar -->
        <?php include "includes/sidebar.php" ?>
        <!-- End::app-sidebar -->
        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">My Profile</h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="index"><i class="ri-home-4-line"></i> Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Profile</li>
                            </ol>
                        </nav>
                    </div>
                </div>

                <!-- Start::row-1 -->
                <div class="row">
                    <!-- Left Column - Profile Card -->
                    <div class="col-xxl-4 col-xl-12">
                        <div class="card custom-card overflow-hidden">
                            <div class="card-body p-0">
                                <!-- Profile Header -->
                                <div class="d-sm-flex align-items-top p-4 border-bottom-0 main-profile-cover">
                                    <div>
                                        <span class="avatar avatar-xxl avatar-rounded me-3 bg-primary"
                                            id="profileHeaderAvatar">
                                            <!-- JS will populate -->
                                        </span>
                                    </div>
                                    <div class="flex-fill main-profile-info">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <h6 class="fw-semibold mb-1 text-fixed-white" id="profileHeaderName">
                                                <!-- JS will populate -->
                                            </h6>
                                            <button type="button" class="btn btn-light btn-wave"
                                                onclick="ProfilePage.showEditProfileModal()">
                                                <i class="ri-edit-line me-1 align-middle"></i>Edit Profile
                                            </button>
                                        </div>
                                        <p class="mb-1 text-muted text-fixed-white op-7" id="profileHeaderPosition">
                                            <!-- JS will populate -->
                                        </p>
                                        <p class="fs-12 text-fixed-white mb-4 op-5">
                                            <span class="me-3">
                                                <i class="ri-mail-line me-1 align-middle"></i>
                                                <span id="profileHeaderEmail">
                                                    <!-- JS -->
                                                </span>
                                            </span>
                                            <span>
                                                <i class="ri-phone-line me-1 align-middle"></i>
                                                <span id="profileHeaderPhone">
                                                    <!-- JS -->
                                                </span>
                                            </span>
                                        </p>
                                        <div class="d-flex mb-0">
                                            <div class="me-4">
                                                <p class="fw-bold fs-20 text-fixed-white text-shadow mb-0"
                                                    id="profileAssignmentsCount">0</p>
                                                <p class="mb-0 fs-11 op-5 text-fixed-white">Active Assignments</p>
                                            </div>
                                            <div class="me-4">
                                                <p class="fw-bold fs-20 text-fixed-white text-shadow mb-0">
                                                    <span class="badge" id="profileHeaderStatus">
                                                        <!-- JS -->
                                                    </span>
                                                </p>
                                                <p class="mb-0 fs-11 op-5 text-fixed-white">Account Status</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contact Information -->
                                <div class="p-4 border-bottom border-block-end-dashed bg-light">
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="avatar avatar-sm bg-primary text-white rounded-circle me-2">
                                            <i class="ri-contacts-line fs-16"></i>
                                        </span>
                                        <h6 class="mb-0 fw-semibold text-primary">Contact Information</h6>
                                    </div>

                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item bg-white border rounded mb-2 shadow-sm">
                                            <div class="d-flex align-items-center p-2">
                                                <span
                                                    class="avatar avatar-sm avatar-rounded me-3 bg-primary-transparent text-primary">
                                                    <i class="ri-mail-line align-middle fs-14"></i>
                                                </span>
                                                <div class="flex-fill">
                                                    <span class="d-block text-muted fs-11 mb-1">
                                                        <i class="ri-arrow-right-s-line fs-12"></i>Email Address
                                                    </span>
                                                    <span class="fw-semibold text-dark" id="profileSidebarEmail">
                                                        <!-- JS -->
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="list-group-item bg-white border rounded mb-2 shadow-sm">
                                            <div class="d-flex align-items-center p-2">
                                                <span
                                                    class="avatar avatar-sm avatar-rounded me-3 bg-success-transparent text-success">
                                                    <i class="ri-phone-line align-middle fs-14"></i>
                                                </span>
                                                <div class="flex-fill">
                                                    <span class="d-block text-muted fs-11 mb-1">
                                                        <i class="ri-arrow-right-s-line fs-12"></i>Phone Number
                                                    </span>
                                                    <span class="fw-semibold text-dark" id="profileSidebarPhone">
                                                        <!-- JS -->
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="list-group-item bg-white border rounded shadow-sm">
                                            <div class="d-flex align-items-center p-2">
                                                <span
                                                    class="avatar avatar-sm avatar-rounded me-3 bg-info-transparent text-info">
                                                    <i class="ri-user-line align-middle fs-14"></i>
                                                </span>
                                                <div class="flex-fill">
                                                    <span class="d-block text-muted fs-11 mb-1">
                                                        <i class="ri-arrow-right-s-line fs-12"></i>Username
                                                    </span>
                                                    <span class="fw-semibold text-dark" id="profileSidebarUsername">
                                                        <!-- JS -->
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Account Details -->
                                <div class="p-4">
                                    <p class="fs-15 mb-3 fw-semibold">
                                        <i class="ri-information-line me-2 text-primary"></i>Account Details
                                    </p>
                                    <ul class="list-group">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium"><i
                                                    class="ri-shield-user-line me-2 text-primary"></i>System
                                                Code:</span>
                                            <span class="badge bg-primary fs-13 px-3 py-2" id="profileEmployeeCode">
                                                <!-- JS -->
                                            </span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium"><i
                                                    class="ri-login-circle-line me-2 text-success"></i>Last
                                                Login:</span>
                                            <span class="text-dark fw-medium fs-13" id="profileLastLogin">
                                                <!-- JS -->
                                            </span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium"><i
                                                    class="ri-calendar-check-line me-2 text-info"></i>Member
                                                Since:</span>
                                            <span class="text-dark fw-medium fs-13" id="profileMemberSince">
                                                <!-- JS -->
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Tabs -->
                    <div class="col-xxl-8 col-xl-12">
                        <div class="row">
                            <div class="col-xl-12">
                                <div class="card custom-card">
                                    <div class="card-body p-0">
                                        <!-- Tab Navigation -->
                                        <div class="p-3 border-bottom border-block-end-dashed">
                                            <ul class="nav nav-tabs mb-0 tab-style-6 justify-content-start"
                                                id="profileTabs" role="tablist">
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link active" id="personal-info-tab"
                                                        data-bs-toggle="tab" data-bs-target="#personal-info-pane"
                                                        type="button" role="tab">
                                                        <i class="ri-user-line me-1"></i>Personal Info
                                                    </button>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link" id="account-status-tab"
                                                        data-bs-toggle="tab" data-bs-target="#account-status-pane"
                                                        type="button" role="tab">
                                                        <i class="ri-shield-check-line me-1"></i>Account Status
                                                    </button>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link" id="assignments-tab" data-bs-toggle="tab"
                                                        data-bs-target="#assignments-pane" type="button" role="tab">
                                                        <i class="ri-briefcase-line me-1"></i>Assignments
                                                    </button>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link" id="security-tab" data-bs-toggle="tab"
                                                        data-bs-target="#security-pane" type="button" role="tab">
                                                        <i class="ri-lock-password-line me-1"></i>Security
                                                    </button>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link" id="activity-log-tab" data-bs-toggle="tab"
                                                        data-bs-target="#activity-log-pane" type="button" role="tab">
                                                        <i class="ri-history-line me-1"></i>Activity Log
                                                    </button>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <button class="nav-link" id="login-history-tab" data-bs-toggle="tab"
                                                        data-bs-target="#login-history-pane" type="button" role="tab">
                                                        <i class="ri-login-box-line me-1"></i>Login History
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>

                                        <!-- Tab Content -->
                                        <div class="p-3">
                                            <div class="tab-content" id="profileTabContent">

                                                <!-- Tab 1: Personal Information -->
                                                <div class="tab-pane fade show active" id="personal-info-pane"
                                                    role="tabpanel">
                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-user-3-line me-2"></i>Personal Information
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-user-smile-line me-1 text-primary"></i>First
                                                                        Name
                                                                    </label>
                                                                    <p class="mb-0 fw-semibold text-dark"
                                                                        id="profileFirstname"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-user-smile-line me-1 text-primary"></i>Last
                                                                        Name
                                                                    </label>
                                                                    <p class="mb-0 fw-semibold text-dark"
                                                                        id="profileLastname"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i class="ri-mail-line me-1 text-info"></i>Email
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="profileEmail"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-phone-line me-1 text-success"></i>Phone
                                                                        Number
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="profilePhone"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-user-line me-1 text-secondary"></i>Username
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="profileUsername"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-shield-user-line me-1 text-primary"></i>Diocese
                                                                        Code
                                                                    </label>
                                                                    <p class="mb-0">
                                                                        <span class="badge bg-primary fs-13 px-3 py-2"
                                                                            id="profileEmpCode"></span>
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-briefcase-line me-1 text-warning"></i>Position
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="profilePositionDetail"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-checkbox-circle-line me-1 text-success"></i>Status
                                                                    </label>
                                                                    <p class="mb-0">
                                                                        <span class="badge"
                                                                            id="profileStatusDetail"></span>
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div class="text-end mt-3">
                                                                <a href="profile-settings/edit" class="btn btn-primary">
                                                                    <i class="ri-edit-line me-1"></i>Edit Profile
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Tab 2: Account Status -->
                                                <div class="tab-pane fade" id="account-status-pane" role="tabpanel">
                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-shield-check-line me-2"></i>Account
                                                                Security Status
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <!-- Warnings (Hidden by default, shown by JS) -->
                                                            <div class="alert alert-warning d-none"
                                                                id="mustChangePasswordWarning">
                                                                <i class="ri-error-warning-line me-2"></i>
                                                                <strong>Password Change Required!</strong> You must
                                                                change your password.
                                                            </div>
                                                            <div class="alert d-none" id="passwordExpiryWarning"></div>
                                                            <div class="alert alert-danger d-none"
                                                                id="accountLockedWarning">
                                                                <i class="ri-lock-line me-2"></i>
                                                                <strong>Account Locked!</strong> Too many failed login
                                                                attempts.
                                                            </div>

                                                            <div class="row">
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-key-2-line me-1 text-warning"></i>Last
                                                                        Password Change
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="lastPasswordChange"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-calendar-event-line me-1 text-danger"></i>Password
                                                                        Expires
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="passwordExpires"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i class="ri-time-line me-1 text-info"></i>Days
                                                                        Until Expiry
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark"
                                                                        id="passwordExpiryDays"></p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-login-circle-line me-1 text-success"></i>Last
                                                                        Login
                                                                    </label>
                                                                    <p class="mb-0 fw-medium text-dark" id="lastLogin">
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-6 mb-3">
                                                                    <label class="form-label fw-semibold text-muted">
                                                                        <i
                                                                            class="ri-error-warning-line me-1 text-danger"></i>Failed
                                                                        Login Attempts
                                                                    </label>
                                                                    <p class="mb-0">
                                                                        <span class="badge bg-danger fs-13 px-3 py-2"
                                                                            id="loginAttempts">0</span>
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div class="text-end mt-3">
                                                                <button class="btn btn-primary" data-bs-toggle="modal"
                                                                    data-bs-target="#changePasswordModal">
                                                                    <i class="ri-lock-password-line me-1"></i>Change
                                                                    Password
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>


                                                <!-- Tab 3: Assignments -->
                                                <div class="tab-pane fade" id="assignments-pane" role="tabpanel">
                                                    <div class="card shadow-none border mb-3">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0 fw-semibold">
                                                                <i
                                                                    class="ri-briefcase-4-line me-2 text-primary"></i>Active
                                                                Assignments
                                                            </h6>
                                                        </div>
                                                        <div class="card-body p-4">
                                                            <div id="activeAssignmentsContainer"></div>
                                                        </div>
                                                    </div>

                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-warning-transparent">
                                                            <h6 class="card-title mb-0 fw-semibold">
                                                                <i
                                                                    class="ri-history-line me-2 text-warning"></i>Assignment
                                                                History
                                                            </h6>
                                                        </div>
                                                        <div class="card-body p-4">
                                                            <div id="assignmentHistoryContainer"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Tab 4: Security -->
                                                <div class="tab-pane fade" id="security-pane" role="tabpanel">
                                                    <div class="card shadow-none border mb-3">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-shield-keyhole-line me-2"></i>Security
                                                                Actions
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <button class="btn btn-primary me-2" data-bs-toggle="modal"
                                                                data-bs-target="#changePasswordModal">
                                                                <i class="ri-lock-password-line me-1"></i>Change
                                                                Password
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-secondary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-key-2-line me-2"></i>Password Change
                                                                History
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <div id="passwordHistoryContainer"></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Tab 5: Activity Log -->
                                                <div class="tab-pane fade" id="activity-log-pane" role="tabpanel">
                                                    <div class="card shadow-none border mb-3">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-filter-3-line me-2"></i>Filter Activity Log
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <div class="row g-3">
                                                                <div class="col-md-3">
                                                                    <label class="form-label">Event Type</label>
                                                                    <select class="form-select" id="auditTypeFilter">
                                                                        <option value="all">All Events</option>
                                                                        <option value="login">Login Events</option>
                                                                        <option value="password">Password Changes
                                                                        </option>
                                                                        <option value="profile">Profile Updates</option>
                                                                        <option value="status">Status Changes</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label">From Date</label>
                                                                    <input type="date" class="form-control"
                                                                        id="auditFromDate">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label">To Date</label>
                                                                    <input type="date" class="form-control"
                                                                        id="auditToDate">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="form-label">Limit</label>
                                                                    <select class="form-select" id="auditLimitSelect">
                                                                        <option value="20">20</option>
                                                                        <option value="50" selected>50</option>
                                                                        <option value="100">100</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-12">
                                                                    <button class="btn btn-primary"
                                                                        id="applyActivityFilters">
                                                                        <i class="ri-filter-3-line me-1"></i>Apply
                                                                        Filters
                                                                    </button>
                                                                    <button class="btn btn-secondary"
                                                                        id="clearActivityFilters">
                                                                        <i class="ri-close-line me-1"></i>Clear Filters
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-primary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-file-list-3-line me-2"></i>Activity
                                                                Timeline
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <ul class="list-unstyled profile-timeline"
                                                                id="activityLogContainer">
                                                                <!-- JS will populate timeline -->
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Tab 6: Login History -->
                                                <div class="tab-pane fade" id="login-history-pane" role="tabpanel">
                                                    <div class="card shadow-none border mb-3">
                                                        <div
                                                            class="card-header bg-primary-transparent d-flex justify-content-between align-items-center">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-filter-3-line me-2"></i>Filter Login
                                                                History
                                                            </h6>
                                                            <select class="form-select w-auto"
                                                                id="loginHistoryLimitSelect">
                                                                <option value="50">50 Records</option>
                                                                <option value="100" selected>100 Records</option>
                                                                <option value="200">200 Records</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="card shadow-none border">
                                                        <div class="card-header bg-secondary-transparent">
                                                            <h6 class="card-title mb-0">
                                                                <i class="ri-login-box-line me-2"></i>Login History
                                                            </h6>
                                                        </div>
                                                        <div class="card-body">
                                                            <div id="loginHistoryContainer"></div>
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
                </div>
                <!--End::row-1 -->
            </div>
        </div>
        <!-- End::app-content -->


        <!-- Change Password Modal -->
        <div class="modal fade" id="changePasswordModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text-white">
                            <i class="ri-lock-password-line me-2"></i>Change Your Password
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <form id="changePasswordForm">
                        <div class="modal-body p-4">
                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-2 fw-semibold">Password Requirements</h6>
                                        <ul class="mb-0 ps-3 fs-13 text-dark">
                                            <li class="mb-1">Minimum 8 characters long</li>
                                            <li class="mb-1">Must be different from current password</li>
                                            <li class="mb-0">Passwords will expire after 6 months</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- New Password Input -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold mb-2">
                                    <i class="ri-key-2-line me-1 text-primary"></i>New Password
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-primary text-white border-primary">
                                        <i class="ri-lock-line"></i>
                                    </span>
                                    <input type="password" class="form-control form-control-lg border-primary"
                                        id="newPassword" placeholder="Enter new password" required>
                                </div>
                                <small class="text-muted d-block mt-1 fs-12">
                                    <i class="ri-error-warning-line me-1"></i>Must be at least 8 characters
                                </small>
                            </div>

                            <!-- Confirm Password Input -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold mb-2">
                                    <i class="ri-shield-check-line me-1 text-success"></i>Confirm New Password
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-success text-white border-success">
                                        <i class="ri-shield-keyhole-line"></i>
                                    </span>
                                    <input type="password" class="form-control form-control-lg border-success"
                                        id="confirmPassword" placeholder="Re-enter new password" required>
                                </div>
                                <small class="text-muted d-block mt-1 fs-12">
                                    <i class="ri-error-warning-line me-1"></i>Must match the new password
                                </small>
                            </div>

                            <!-- Security Notice -->
                            <div class="alert alert-warning bg-warning-transparent border-0 mb-0 p-3">
                                <div class="d-flex align-items-center">
                                    <i class="ri-shield-keyhole-line fs-18 me-2 text-warning"></i>
                                    <small class="mb-0 text-dark">
                                        <strong>Security Tip:</strong> Use a strong, unique password you haven't used
                                        elsewhere
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light border-top p-3">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                                <i class="ri-close-line me-1"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary btn-lg px-4">
                                <i class="ri-check-line me-1"></i>Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Audit Details Modal -->
        <div class="modal fade" id="auditDetailsModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold">
                            <i class="ri-file-info-line me-2"></i>Audit Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4" id="auditDetailsModalBody">
                        <!-- Loading State (shown before JS populates) -->
                        <div class="text-center py-5" id="auditDetailsLoader">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="text-muted mb-0">Loading audit details...</p>
                        </div>

                        <!-- Placeholder structure (will be replaced by JS) -->
                        <div id="auditDetailsContent" class="d-none">
                            <!-- Event Summary Card -->
                            <div class="card border mb-3">
                                <div class="card-body bg-light">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span
                                                class="avatar avatar-lg bg-primary-transparent text-primary rounded-circle">
                                                <i class="ri-calendar-event-line fs-24"></i>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <h6 class="mb-1 fw-semibold">Event Type</h6>
                                            <span class="badge bg-primary" id="auditEventType">Loading...</span>
                                        </div>
                                        <div class="col-auto text-end">
                                            <small class="text-muted d-block mb-1">
                                                <i class="ri-calendar-line me-1"></i>Date & Time
                                            </small>
                                            <span class="fw-medium text-dark" id="auditDateTime">Loading...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Details Grid -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="card border h-100">
                                        <div class="card-body">
                                            <label class="form-label fw-semibold text-muted mb-2">
                                                <i class="ri-user-line me-1 text-primary"></i>Changed By
                                            </label>
                                            <p class="mb-0 fw-medium text-dark" id="auditChangedBy">Loading...</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border h-100">
                                        <div class="card-body">
                                            <label class="form-label fw-semibold text-muted mb-2">
                                                <i class="ri-map-pin-line me-1 text-success"></i>IP Address
                                            </label>
                                            <p class="mb-0">
                                                <code class="bg-light px-2 py-1 rounded"
                                                    id="auditIpAddress">Loading...</code>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="card border">
                                        <div class="card-body">
                                            <label class="form-label fw-semibold text-muted mb-2">
                                                <i class="ri-computer-line me-1 text-info"></i>User Agent
                                            </label>
                                            <p class="mb-0 fs-12">
                                                <code class="bg-light px-2 py-1 rounded d-inline-block"
                                                    id="auditUserAgent">Loading...</code>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Changes Section -->
                            <div id="auditChangesSection">
                                <h6 class="mb-3 fw-semibold border-bottom pb-2">
                                    <i class="ri-git-commit-line me-2 text-warning"></i>Changes Made
                                </h6>

                                <!-- Old Values -->
                                <div class="card border mb-3" id="auditOldValuesCard">
                                    <div class="card-header bg-danger-transparent">
                                        <h6 class="card-title mb-0 fw-semibold text-danger">
                                            <i class="ri-arrow-left-line me-1"></i>Old Values
                                        </h6>
                                    </div>
                                    <div class="card-body bg-light">
                                        <pre
                                            class="mb-0 p-3 bg-white border rounded"><code id="auditOldValues">No old values</code></pre>
                                    </div>
                                </div>

                                <!-- New Values -->
                                <div class="card border mb-0" id="auditNewValuesCard">
                                    <div class="card-header bg-success-transparent">
                                        <h6 class="card-title mb-0 fw-semibold text-success">
                                            <i class="ri-arrow-right-line me-1"></i>New Values
                                        </h6>
                                    </div>
                                    <div class="card-body bg-light">
                                        <pre
                                            class="mb-0 p-3 bg-white border rounded"><code id="auditNewValues">No new values</code></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-top">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                            <i class="ri-close-line me-1"></i>Close
                        </button>
                        <button type="button" class="btn btn-primary" onclick="window.print()">
                            <i class="ri-printer-line me-1"></i>Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Edit Profile Modal -->
        <div class="modal fade" id="editProfileModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fw-semibold text">
                            <i class="ri-user-settings-line me-2"></i>Edit Profile
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <form id="editProfileForm">
                        <div class="modal-body p-4">
                            <!-- Info Alert -->
                            <div class="alert alert-info bg-info-transparent border-0 mb-4 p-3">
                                <div class="d-flex align-items-start">
                                    <i class="ri-information-line fs-20 me-3 text-info"></i>
                                    <div class="flex-fill">
                                        <h6 class="alert-heading mb-1 fw-semibold">Update Your Information</h6>
                                        <p class="mb-0 fs-13">Update your personal details. All fields are required
                                            except email.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Personal Information -->
                            <div class="row g-3">
                                <!-- First Name -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-user-smile-line me-1 text-primary"></i>First Name
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary-transparent border-primary">
                                            <i class="ri-user-line text-primary"></i>
                                        </span>
                                        <input type="text" class="form-control border-primary" id="editFirstname"
                                            placeholder="Enter first name" required>
                                    </div>
                                </div>

                                <!-- Last Name -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-user-smile-line me-1 text-primary"></i>Last Name
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-primary-transparent border-primary">
                                            <i class="ri-user-line text-primary"></i>
                                        </span>
                                        <input type="text" class="form-control border-primary" id="editLastname"
                                            placeholder="Enter last name" required>
                                    </div>
                                </div>

                                <!-- Email -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-mail-line me-1 text-info"></i>Email
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-info-transparent border-info">
                                            <i class="ri-mail-line text-info"></i>
                                        </span>
                                        <input type="email" class="form-control border-info" id="editEmail"
                                            placeholder="Enter email address">
                                    </div>
                                    <small class="text-muted d-block mt-1 fs-12">
                                        <i class="ri-information-line me-1"></i>Optional field
                                    </small>
                                </div>

                                <!-- Phone -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-phone-line me-1 text-success"></i>Phone Number
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-success-transparent border-success">
                                            <i class="ri-phone-line text-success"></i>
                                        </span>
                                        <input type="tel" class="form-control border-success" id="editPhone"
                                            placeholder="Enter phone number" required>
                                    </div>
                                </div>

                                <!-- Username -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-at-line me-1 text-secondary"></i>Username
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-secondary-transparent border-secondary">
                                            <i class="ri-user-line text-secondary"></i>
                                        </span>
                                        <input type="text" class="form-control border-secondary" id="editUsername"
                                            placeholder="Enter username" required>
                                    </div>
                                    <small class="text-muted d-block mt-1 fs-12">
                                        <i class="ri-error-warning-line me-1"></i>Must be unique
                                    </small>
                                </div>

                                <!-- Position -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="ri-briefcase-line me-1 text-warning"></i>Position
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-warning-transparent border-warning">
                                            <i class="ri-briefcase-line text-warning"></i>
                                        </span>
                                        <input type="text" class="form-control border-warning" id="editPosition"
                                            placeholder="Enter position/title" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Security Notice -->
                            <div class="alert alert-warning bg-warning-transparent border-0 mt-4 mb-0 p-3">
                                <div class="d-flex align-items-center">
                                    <i class="ri-shield-check-line fs-18 me-2 text-warning"></i>
                                    <small class="mb-0 text-dark">
                                        <strong>Note:</strong> Changes will be reflected immediately across the system
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light border-top p-3">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                                <i class="ri-close-line me-1"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary btn-lg px-4">
                                <i class="ri-save-line me-1"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Footer Start -->
        <footer class="footer mt-auto py-3 bg-white text-center">
            <div class="container">
                <span class="text-muted">
                    Copyright © <span id="year"></span>
                    <a href="javascript:void(0);" class="text-dark fw-semibold">Ynex</a>. Designed with
                    <span class="bi bi-heart-fill text-danger"></span> by
                    <a href="javascript:void(0);">
                        <span class="fw-semibold text-primary text-decoration-underline">Spruko</span>
                    </a>
                    All rights reserved
                </span>
            </div>
        </footer>
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

    <!-- Gallery JS -->
    <script src="<?= SITE_URL ?>/assets/libs/glightbox/js/glightbox.min.js"></script>

    <!-- Internal Profile JS -->
    <script src="<?= asset_url('/assets/js/profile.js') ?>"></script>

    <!-- Custom JS -->
    <script src="<?= asset_url('/assets/js/custom.js') ?>"></script>
    <!-- toasts -->
    <script src="<?= asset_url('/assets/js/pages/authentication/logout.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/auth-helpers.js') ?>"></script>"></script>
    <script src="<?= asset_url('/assets/js/Toasts.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/utils/toast.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/pages/profile/profile.js') ?>"></script>
</body>

</html>
