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
    <link rel="icon" href="http://localhost:8080/makueni-west/assets/images/brand-logos/favicon/favicon.ico"
        type="image/x-icon" />

    <!-- Main Theme Js -->
    <script src="http://localhost:8080/makueni-west/assets/js/authentication-main.js"></script>

    <!-- Bootstrap Css -->
    <link id="style" href="http://localhost:8080/makueni-west/assets/libs/bootstrap/css/bootstrap.min.css"
        rel="stylesheet" />

    <!-- Style Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/styles.min.css" rel="stylesheet" />

    <!-- Icons Css -->
    <link href="http://localhost:8080/makueni-west/assets/css/icons.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="http://localhost:8080/makueni-west/assets/libs/swiper/swiper-bundle.min.css" />
</head>

<body class="bg-white">
    <!-- Start Switcher -->
    <?php include "./includes/start-switcher.php" ?>
    <!-- End Switcher -->

    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-6 col-xl-7 col-lg-7 col-md-7 col-sm-8 col-12">
                    <div class="p-5">
                        <p class="h5 fw-semibold mb-2">Sign In</p>
                        <p class="mb-3 text-muted op-7 fw-normal">Welcome to Makueni West Diocese Management System</p>

                        <!-- Login Method Tabs -->
                        <ul class="nav nav-pills nav-justified mb-4" id="loginTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="code-tab" data-bs-toggle="pill"
                                    data-bs-target="#code-login" type="button" role="tab" aria-controls="code-login"
                                    aria-selected="true">
                                    <i class="ri-qr-code-line me-1"></i> Employee Code
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="credentials-tab" data-bs-toggle="pill"
                                    data-bs-target="#credentials-login" type="button" role="tab"
                                    aria-controls="credentials-login" aria-selected="false">
                                    <i class="ri-lock-password-line me-1"></i> Email/Username
                                </button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="loginTabsContent">

                            <!-- Employee Code Login -->
                            <div class="tab-pane fade show active" id="code-login" role="tabpanel"
                                aria-labelledby="code-tab">
                                <form id="codeLoginForm">
                                    <div class="row gy-3">
                                        <div class="col-xl-12">
                                            <label for="employee-code" class="form-label text-default">
                                                Employee Code
                                            </label>
                                            <input type="text" class="form-control form-control-lg text-center"
                                                id="employee-code" placeholder="Enter 6-digit code" maxlength="6"
                                                pattern="[0-9]{6}" required />
                                            <small class="text-muted">Enter your 6-digit employee code</small>
                                        </div>
                                        <div class="col-xl-12 d-grid mt-4">
                                            <button type="submit" class="btn btn-lg btn-primary">
                                                <i class="ri-login-circle-line me-1"></i> Sign In with Code
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Email/Username & Password Login -->
                            <div class="tab-pane fade" id="credentials-login" role="tabpanel"
                                aria-labelledby="credentials-tab">
                                <form id="credentialsLoginForm">
                                    <div class="row gy-3">
                                        <div class="col-xl-12 mt-0">
                                            <label for="signin-identifier" class="form-label text-default">
                                                Email or Username
                                            </label>
                                            <input type="text" class="form-control form-control-lg"
                                                id="signin-identifier" placeholder="Enter email or username" required />
                                        </div>
                                        <div class="col-xl-12 mb-3">
                                            <label for="signin-password" class="form-label text-default d-block">
                                                Password
                                                <a href="authentication/forgot-password.php"
                                                    class="float-end text-danger">
                                                    Forgot password?
                                                </a>
                                            </label>
                                            <div class="input-group">
                                                <input type="password" class="form-control form-control-lg"
                                                    id="signin-password" placeholder="Enter password" required />
                                                <button class="btn btn-light" type="button"
                                                    onclick="createpassword('signin-password',this)" id="button-addon2">
                                                    <i class="ri-eye-off-line align-middle"></i>
                                                </button>
                                            </div>
                                            <div class="mt-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" value=""
                                                        id="remember-me" />
                                                    <label class="form-check-label text-muted fw-normal"
                                                        for="remember-me">
                                                        Remember me
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-12 d-grid mt-2">
                                            <button type="submit" class="btn btn-lg btn-primary">
                                                <i class="ri-login-circle-line me-1"></i> Sign In
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                        </div>
                        <!-- Help Text -->
                        <div class="text-center mt-4">
                            <p class="fs-12 text-muted mb-0">
                                <i class="ri-information-line me-1"></i>
                                Need help?
                                <a href="http://localhost:8080/makueni-west/authentication/support"
                                    class="text-primary fw-semibold">
                                    <i class="ri-customer-service-line me-1"></i>Contact Support
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
                                            <img src="http://localhost:8080/makueni-west/assets/images/authentication/2.png"
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
                                            <img src="http://localhost:8080/makueni-west/assets/images/authentication/3.png"
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
                                            <img src="http://localhost:8080/makueni-west/assets/images/authentication/2.png"
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
    <script src="http://localhost:8080/makueni-west/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Swiper JS -->
    <script src="http://localhost:8080/makueni-west/assets/libs/swiper/swiper-bundle.min.js"></script>

    <!-- Internal Sing-Up JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/authentication.js"></script>

    <!-- Show Password JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/show-password.js"></script>
    <!-- Toast JS -->
    <script src="http://localhost:8080/makueni-west/assets/js/Toasts.js"></script>
    <!-- Load Bootstrap first (if not already loaded) -->
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Load config files -->
    <script src="http://localhost:8080/makueni-west/assets/js/config/app.js"></script>
    <script src="http://localhost:8080/makueni-west/assets/js/config/constants.js"></script>

    <!-- Load utilities -->
    <script src="http://localhost:8080/makueni-west/assets/js/utils/toast.js"></script>

    <!-- Load login handler -->
    <script src="http://localhost:8080/makueni-west/assets/js/pages/authentication/login.js"></script>

</body>

<!-- Mirrored from spruko.com/demo/blazor/ynex/ynex/dist/html/sign-in-cover.html by HTTrack Website Copier/3.x [XR&CO'2014], Fri, 05 Jan 2024 16:07:09 GMT -->

</html>