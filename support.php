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

    <!-- Quill Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

    <!-- FilePond -->
    <link href="https://unpkg.com/filepond@^4/dist/filepond.css" rel="stylesheet">
    <link href="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css"
        rel="stylesheet">

    <!-- Date & Time Picker CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/flatpickr/flatpickr.min.css" />
</head>

<body>
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
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
        <!-- Start::app-content -->
        <div class="main-content app-content">
            <div class="container-fluid">

                <!-- Page Header -->
                <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
                    <h1 class="page-title fw-semibold fs-18 mb-0">Help & Support</h1>
                    <div class="ms-md-1 ms-0">
                        <nav>
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="index"><i class="ri-home-4-line"></i> Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Help & Support</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <!-- Page Header Close -->

                <!-- Start:: row-1 -->
                <div class="row">

                    <!-- Left Column - Support Form -->
                    <div class="col-xxl-9 col-xl-12 col-lg-12 col-md-12 col-sm-12">

                        <!-- Auth Status Badge (Hidden by default, shown by JS if logged in) -->
                        <div class="alert alert-info d-none" id="authBadge">
                            <div class="d-flex align-items-center">
                                <i class="ri-user-check-line fs-20 me-3"></i>
                                <div>
                                    <strong>Logged in as:</strong> <span id="authUserName"></span>
                                    <span class="badge bg-primary ms-2" id="authUserPosition"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Guest Notice (Hidden by default, shown by JS if NOT logged in) -->
                        <div class="alert alert-warning d-none" id="guestNotice">
                            <div class="d-flex align-items-center">
                                <i class="ri-information-line fs-20 me-3"></i>
                                <div>
                                    <strong>You're submitting as a guest.</strong>
                                    For faster support, please <a href="<?= SITE_URL ?>/authentication/login"
                                        class="alert-link">login here</a>.
                                </div>
                            </div>
                        </div>

                        <!-- Support Categories Cards -->
                        <div class="row mb-4">
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
                                <div class="card custom-card text-center">
                                    <div class="card-body">
                                        <div class="avatar avatar-lg bg-primary-transparent text-primary mx-auto mb-3">
                                            <i class="ri-shield-keyhole-line fs-24"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Account Issues</h6>
                                        <p class="text-muted fs-12 mb-0">Login problems, password resets, locked
                                            accounts</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
                                <div class="card custom-card text-center">
                                    <div class="card-body">
                                        <div class="avatar avatar-lg bg-success-transparent text-success mx-auto mb-3">
                                            <i class="ri-tools-line fs-24"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">Technical Support</h6>
                                        <p class="text-muted fs-12 mb-0">System errors, bugs, performance issues</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
                                <div class="card custom-card text-center">
                                    <div class="card-body">
                                        <div class="avatar avatar-lg bg-warning-transparent text-warning mx-auto mb-3">
                                            <i class="ri-book-open-line fs-24"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">How-To Guides</h6>
                                        <p class="text-muted fs-12 mb-0">Training resources, user manuals, tutorials</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
                                <div class="card custom-card text-center">
                                    <div class="card-body">
                                        <div class="avatar avatar-lg bg-info-transparent text-info mx-auto mb-3">
                                            <i class="ri-question-line fs-24"></i>
                                        </div>
                                        <h6 class="fw-semibold mb-2">General Inquiries</h6>
                                        <p class="text-muted fs-12 mb-0">Questions, feedback, feature requests</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Support Request Form -->
                        <div class="card custom-card">
                            <div class="card-header">
                                <div class="card-title">Submit Support Request</div>
                            </div>
                            <div class="card-body">
                                <form id="supportForm">
                                    <div class="row gy-3">

                                        <!-- Name -->
                                        <div class="col-xl-6">
                                            <label for="supportName" class="form-label">Full Name <span
                                                    class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="supportName"
                                                placeholder="Enter your full name" required>
                                        </div>

                                        <!-- Email -->
                                        <div class="col-xl-6">
                                            <label for="supportEmail" class="form-label">Email Address <span
                                                    class="text-danger">*</span></label>
                                            <input type="email" class="form-control" id="supportEmail"
                                                placeholder="Enter your email" required>
                                        </div>

                                        <!-- Phone -->
                                        <div class="col-xl-6">
                                            <label for="supportPhone" class="form-label">Phone Number <span
                                                    class="text-danger">*</span></label>
                                            <input type="tel" class="form-control" id="supportPhone"
                                                placeholder="Enter your phone number" required>
                                        </div>

                                        <!-- Category -->
                                        <div class="col-xl-6">
                                            <label for="supportCategory" class="form-label">Category <span
                                                    class="text-danger">*</span></label>
                                            <select class="form-control" id="supportCategory" required>
                                                <option value="">Select Category</option>
                                                <option value="account_issues">Account Issues</option>
                                                <option value="technical_support">Technical Support</option>
                                                <option value="how_to_guides">How-To Guides</option>
                                                <option value="general_inquiry">General Inquiry</option>
                                            </select>
                                        </div>

                                        <!-- Priority -->
                                        <div class="col-xl-6">
                                            <label for="supportPriority" class="form-label">Priority <span
                                                    class="text-danger">*</span></label>
                                            <select class="form-control" id="supportPriority" required>
                                                <option value="low"> Low</option>
                                                <option value="medium" selected>Medium</option>
                                                <option value="high">High</option>
                                                <option value="urgent">Urgent</option>
                                            </select>
                                        </div>

                                        <!-- Subject -->
                                        <div class="col-xl-6">
                                            <label for="supportSubject" class="form-label">Subject <span
                                                    class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="supportSubject"
                                                placeholder="Brief description of your issue" required>
                                        </div>

                                        <!-- Message with Quill Editor -->
                                        <div class="col-xl-12">
                                            <label class="form-label">Message <span class="text-danger">*</span></label>
                                            <div id="editor">
                                                <p>Describe your issue in detail...</p>
                                            </div>
                                        </div>

                                        <!-- File Attachments with Filepond -->
                                        <div class="col-xl-12">
                                            <label for="supportAttachments" class="form-label">Attachments
                                                (Optional)</label>
                                            <input type="file" class="multiple-filepond" name="filepond" multiple
                                                data-allow-reorder="true" data-max-file-size="5MB" data-max-files="5" />
                                            <small class="text-muted d-block mt-2">Max 5 files, 5MB each. Accepted:
                                                Images, PDF, Word, Excel</small>
                                        </div>

                                    </div>
                                </form>
                            </div>
                            <div class="card-footer">
                                <div class="btn-list text-end">
                                    <button type="button" class="btn btn-light"
                                        onclick="document.getElementById('supportForm').reset();">
                                        <i class="ri-refresh-line me-1"></i>Reset Form
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="submitSupportBtn"
                                        form="supportForm">
                                        <i class="ri-send-plane-line me-1"></i>Submit Request
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Right Column - Quick Help & Contact -->
                    <div class="col-xxl-3 col-xl-12 col-lg-12 col-md-12 col-sm-12">

                        <!-- Quick Help Tips -->
                        <div class="card custom-card">
                            <div class="card-header bg-primary-transparent">
                                <div class="card-title">
                                    <i class="ri-lightbulb-line me-2"></i>Quick Help Tips
                                </div>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item px-0">
                                        <div class="d-flex align-items-start">
                                            <span
                                                class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2">
                                                <i class="ri-key-2-line"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-1 fs-14">Forgot Password?</h6>
                                                <p class="mb-0 text-muted fs-12">Click "Forgot Password" on the login
                                                    page</p>
                                            </div>
                                        </div>
                                    </li>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex align-items-start">
                                            <span
                                                class="avatar avatar-sm bg-success-transparent text-success rounded-circle me-2">
                                                <i class="ri-lock-line"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-1 fs-14">Account Locked?</h6>
                                                <p class="mb-0 text-muted fs-12">Contact your system administrator</p>
                                            </div>
                                        </div>
                                    </li>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex align-items-start">
                                            <span
                                                class="avatar avatar-sm bg-warning-transparent text-warning rounded-circle me-2">
                                                <i class="ri-refresh-line"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-1 fs-14">Page Not Loading?</h6>
                                                <p class="mb-0 text-muted fs-12">Try clearing your browser cache</p>
                                            </div>
                                        </div>
                                    </li>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex align-items-start">
                                            <span
                                                class="avatar avatar-sm bg-info-transparent text-info rounded-circle me-2">
                                                <i class="ri-smartphone-line"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-1 fs-14">Mobile Access</h6>
                                                <p class="mb-0 text-muted fs-12">Use your employee code to login quickly
                                                </p>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Contact System Administrator -->
                        <div class="card custom-card">
                            <div class="card-header bg-success-transparent">
                                <div class="card-title">
                                    <i class="ri-customer-service-2-line me-2"></i>Contact Administrator
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <span class="avatar avatar-xxl avatar-rounded bg-primary text-white"
                                        id="adminAvatar">
                                        JJ
                                    </span>
                                    <h5 class="mt-3 mb-1" id="adminName">Loading...</h5>
                                    <p class="text-muted fs-12 mb-0" id="adminPosition">System Administrator</p>
                                </div>
                                <ul class="list-group">
                                    <li class="list-group-item d-flex align-items-center">
                                        <span
                                            class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2">
                                            <i class="ri-mail-line"></i>
                                        </span>
                                        <div class="flex-fill">
                                            <small class="text-muted d-block">Email</small>
                                            <span class="fw-medium fs-13" id="adminEmail">Loading...</span>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center">
                                        <span
                                            class="avatar avatar-sm bg-success-transparent text-success rounded-circle me-2">
                                            <i class="ri-phone-line"></i>
                                        </span>
                                        <div class="flex-fill">
                                            <small class="text-muted d-block">Phone</small>
                                            <span class="fw-medium fs-13" id="adminPhone">Loading...</span>
                                        </div>
                                    </li>
                                </ul>
                                <div class="alert alert-primary mt-3 mb-0" role="alert">
                                    <i class="ri-information-line me-2"></i>
                                    <small>For urgent issues, please call directly</small>
                                </div>
                            </div>
                        </div>

                        <!-- Average Response Time -->
                        <div class="card custom-card">
                            <div class="card-body text-center">
                                <div class="avatar avatar-lg bg-warning-transparent text-warning mx-auto mb-3">
                                    <i class="ri-time-line fs-24"></i>
                                </div>
                                <h6 class="fw-semibold mb-1">Average Response Time</h6>
                                <h3 class="mb-1 text-primary">&lt; 24 Hours</h3>
                                <p class="text-muted fs-12 mb-0">We typically respond within one business day</p>
                            </div>
                        </div>

                    </div>

                </div>
                <!-- End:: row-1 -->

            </div>
        </div>
        <!-- End::app-content -->
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

    <!-- Gallery JS -->
    <script src="<?= SITE_URL ?>/assets/libs/glightbox/js/glightbox.min.js"></script>

    <!-- Internal Profile JS -->
    <script src="<?= SITE_URL ?>/assets/js/profile.js"></script>


    <!-- Dropzone JS -->
    <script src="<?= SITE_URL ?>/assets/libs/dropzone/dropzone-min.js"></script>


    <!-- Custom JS -->
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>

    <!-- Quill Editor JS -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

    <!-- FilePond JS -->
    <script src="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.js"></script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.js">
    </script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.js">
    </script>
    <script src="https://unpkg.com/filepond@^4/dist/filepond.js"></script>
    <!-- toasts -->
    <script src="<?= SITE_URL ?>/assets/js/pages/authentication/logout.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/auth-helpers.js"></script>"></script>
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/profile/profile.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/support/support.js"></script>


</body>

</html>
