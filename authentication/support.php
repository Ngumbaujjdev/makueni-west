<?php require_once __DIR__ . '/../includes/session-manager.php'; ?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-vertical-style="overlay" data-theme-mode="light"
    data-header-styles="light" data-menu-styles="light" data-toggled="close">

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

    <!-- Main Theme Js -->
    <script src="<?= SITE_URL ?>/assets/js/authentication-main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="<?= SITE_URL ?>/assets/css/icons.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/libs/swiper/swiper-bundle.min.css" />
    <!-- Quill Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

    <!-- FilePond -->
    <link href="https://unpkg.com/filepond@^4/dist/filepond.css" rel="stylesheet">
    <link href="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css"
        rel="stylesheet">
</head>

<body class="bg-white">
    <!-- App config (must load before any page script that uses AppConfig) -->
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <!-- Start Switcher -->
    <?php include "../includes/start-switcher.php" ?>
    <!-- End Switcher -->

    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-9 col-xl-10 col-lg-10 col-md-10 col-sm-10 col-12">
                    <div class="p-5">
                        <p class="h5 fw-semibold mb-2">
                            <i class="ri-customer-service-2-line me-2"></i>Contact Support
                        </p>
                        <p class="mb-4 text-muted op-7 fw-normal">
                            Need help? Submit a support request and our team will get back to you within 24 hours.
                        </p>

                        <!-- Guest Notice -->
                        <div class="alert alert-info">
                            <i class="ri-information-line me-2"></i>
                            You're submitting as a guest. For faster support, please
                            <a href="<?= SITE_URL ?>/authentication/login"
                                class="alert-link fw-semibold">login here</a>.
                        </div>

                        <!-- Support Form -->
                        <form id="supportForm">
                            <div class="row gy-3">

                                <!-- Full Name -->
                                <div class="col-xl-12">
                                    <label for="supportName" class="form-label text-default">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="supportName"
                                        placeholder="Enter your full name" required>
                                </div>

                                <!-- Email -->
                                <div class="col-xl-12">
                                    <label for="supportEmail" class="form-label text-default">
                                        Email Address <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" class="form-control" id="supportEmail"
                                        placeholder="Enter your email" required>
                                </div>

                                <!-- Phone -->
                                <div class="col-xl-12">
                                    <label for="supportPhone" class="form-label text-default">
                                        Phone Number <span class="text-danger">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="supportPhone"
                                        placeholder="Enter your phone number" required>
                                </div>

                                <!-- Category -->
                                <div class="col-xl-6">
                                    <label for="supportCategory" class="form-label text-default">
                                        Category <span class="text-danger">*</span>
                                    </label>
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
                                    <label for="supportPriority" class="form-label text-default">
                                        Priority <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="supportPriority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high"> High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>

                                <!-- Subject -->
                                <div class="col-xl-12">
                                    <label for="supportSubject" class="form-label text-default">
                                        Subject <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="supportSubject"
                                        placeholder="Brief description of your issue" required>
                                </div>

                                <!-- Message -->
                                <div class="col-xl-12">
                                    <label class="form-label text-default">
                                        Message <span class="text-danger">*</span>
                                    </label>
                                    <div id="editor">
                                        <p>Describe your issue in detail...</p>
                                    </div>
                                </div>

                                <!-- File Attachments -->
                                <div class="col-xl-12">
                                    <label for="supportAttachments" class="form-label text-default">
                                        Attachments (Optional)
                                    </label>
                                    <input type="file" class="multiple-filepond" name="filepond" multiple
                                        data-allow-reorder="true" data-max-file-size="5MB" data-max-files="5" />
                                    <small class="text-muted d-block mt-2">
                                        Max 5 files, 5MB each. Accepted: Images, PDF, Word, Excel
                                    </small>
                                </div>

                                <!-- Submit Button -->
                                <div class="col-xl-12 d-grid mt-4">
                                    <button type="submit" class="btn btn-lg btn-primary" id="submitSupportBtn">
                                        <i class="ri-send-plane-line me-1"></i> Submit Support Request
                                    </button>
                                </div>

                            </div>
                        </form>

                        <!-- Help Text -->
                        <div class="text-center mt-4">
                            <p class="fs-12 text-muted mb-2">
                                <i class="ri-time-line me-1"></i>
                                <strong>Average Response Time:</strong> &lt; 24 Hours
                            </p>
                            <p class="fs-12 text-muted mb-0">
                                <i class="ri-login-circle-line me-1"></i>
                                Already have an account?
                                <a href="<?= SITE_URL ?>/" class="text-primary fw-semibold">
                                    Login here
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-5 col-xl-5 col-lg-5 d-xl-block d-none px-0">
            <div class="authentication-cover">
                <div class="aunthentication-cover-content rounded">
                    <div class="swiper keyboard-control">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div
                                    class="text-fixed-white text-center p-5 d-flex align-items-center justify-content-center">
                                    <div>
                                        <div class="mb-5">
                                            <img src="<?= SITE_URL ?>/assets/images/authentication/2.png"
                                                class="authentication-image" alt="Diocese Management" />
                                        </div>
                                        <h6 class="fw-semibold text-fixed-white">Makueni West Diocese</h6>
                                        <p class="fw-normal fs-14 op-7">
                                            A comprehensive management system for efficient church administration,
                                            financial stewardship, and member management across the entire diocese.
                                            Empowering leaders at every level with real-time insights and data-driven
                                            decisions.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div
                                    class="text-fixed-white text-center p-5 d-flex align-items-center justify-content-center">
                                    <div>
                                        <div class="mb-5">
                                            <img src="<?= SITE_URL ?>/assets/images/authentication/3.png"
                                                class="authentication-image" alt="Hierarchical Management" />
                                        </div>
                                        <h6 class="fw-semibold text-fixed-white">Hierarchical Management</h6>
                                        <p class="fw-normal fs-14 op-7">
                                            Seamlessly connecting diocese leadership with regional overseers,
                                            subregion overseers, and local church pastors. Complete oversight
                                            of member growth, financial tracking, and ministry effectiveness
                                            across all organizational levels.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div
                                    class="text-fixed-white text-center p-5 d-flex align-items-center justify-content-center">
                                    <div>
                                        <div class="mb-5">
                                            <img src="<?= SITE_URL ?>/assets/images/authentication/2.png"
                                                class="authentication-image" alt="Transparent Stewardship" />
                                        </div>
                                        <h6 class="fw-semibold text-fixed-white">Transparent Stewardship</h6>
                                        <p class="fw-normal fs-14 op-7">
                                            Comprehensive financial management with tithe tracking, budget oversight,
                                            and resource allocation. Ensuring accountability and transparency across
                                            the entire diocesan structure while supporting church growth and ministry
                                            expansion.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-pagination"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Swiper JS -->
    <script src="<?= SITE_URL ?>/assets/libs/swiper/swiper-bundle.min.js"></script>

    <!-- Internal Sing-Up JS -->
    <script src="<?= SITE_URL ?>/assets/js/authentication.js"></script>

    <!-- Show Password JS -->
    <script src="<?= SITE_URL ?>/assets/js/show-password.js"></script>
    <!-- Toast JS -->
    <script src="<?= SITE_URL ?>/assets/js/Toasts.js"></script>
    <!-- Load Bootstrap first (if not already loaded) -->
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
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
    <!-- <script src="<?= SITE_URL ?>/assets/js/pages/profile/profile.js"></script> -->
    <script src="<?= SITE_URL ?>/assets/js/pages/support/support.js"></script>

    <!-- Load login handler -->


</body>

<!-- Mirrored from spruko.com/demo/blazor/ynex/ynex/dist/html/sign-in-cover.html by HTTrack Website Copier/3.x [XR&CO'2014], Fri, 05 Jan 2024 16:07:09 GMT -->

</html>
