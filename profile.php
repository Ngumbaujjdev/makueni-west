<?php
// My Profile - the signed-in person's own page: their details, security,
// roles, activity and sign-ins. Everyone with a login can open it.
require_once __DIR__ . '/includes/session-manager.php';
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/permission-check.php';
require_once __DIR__ . '/includes/budget/context.php'; // the shared page styles and scripts

$pageTitle = 'My Profile';
$pageIcon = 'ri-user-3-line';
$breadcrumbs = ['Home' => SITE_URL, 'Profile' => null];

// A grey bar standing in for text until the page has loaded.
$ph = fn (string $w = 'col-6', string $extra = '') => '<span class="placeholder ' . $w . ' rounded ' . $extra . '"></span>';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>My Profile - Makueni West Diocese</title>
    <meta name="Description" content="Your details, security, roles, activity and sign-ins" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php budgetPageStyles(true) ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>
        // Appearance moved from a Profile tab to its own page - keep old
        // /profile#appearance links and bookmarks working.
        if (window.location.hash === '#appearance') window.location.replace('<?= SITE_URL ?>/appearance');
    </script>
    <?php include __DIR__ . '/includes/start-switcher.php' ?>
    <?php include __DIR__ . '/includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/includes/header.php' ?>
        <?php include __DIR__ . '/includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/includes/page-header.php' ?>

                <div class="row">
                    <!-- Left: who you are -->
                    <div class="col-xxl-4 col-xl-12">
                        <div class="card custom-card overflow-hidden">
                            <div class="card-body p-0">
                                <div class="d-sm-flex align-items-top p-4 main-profile-cover placeholder-glow" id="profileCover">
                                    <div class="profile-photo-wrap me-3">
                                        <span class="avatar avatar-xxl avatar-rounded bg-primary" id="profileHeaderAvatar"><span class="placeholder col-12 h-100 rounded-circle"></span></span>
                                        <button type="button" class="profile-photo-btn" data-photo-open title="Change photo" aria-label="Change your photo" disabled><i class="ri-camera-line"></i></button>
                                    </div>
                                    <div class="flex-fill main-profile-info">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <h6 class="fw-semibold mb-1 text-fixed-white" id="profileHeaderName"><?= $ph('col-8') ?></h6>
                                            <button type="button" class="btn btn-light btn-wave flex-shrink-0" data-edit-profile disabled>
                                                <i class="ri-edit-line me-1 align-middle"></i>Edit
                                            </button>
                                        </div>
                                        <p class="mb-1 text-fixed-white op-7" id="profileHeaderPosition"><?= $ph('col-10') ?></p>
                                        <p class="fs-12 text-fixed-white mb-4 op-7 text-break" id="profileHeaderContact"><?= $ph('col-9') ?></p>
                                        <div class="d-flex mb-0">
                                            <div class="me-4">
                                                <p class="fw-bold fs-20 text-fixed-white text-shadow mb-0" id="profileAssignmentsCount"><?= $ph('col-12', 'px-3') ?></p>
                                                <p class="mb-0 fs-11 op-7 text-fixed-white">Active roles</p>
                                            </div>
                                            <div class="me-4">
                                                <p class="fw-bold fs-20 text-fixed-white text-shadow mb-0" id="profileHeaderStatus"><?= $ph('col-12', 'px-4') ?></p>
                                                <p class="mb-0 fs-11 op-7 text-fixed-white">Account</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contact information -->
                                <div class="p-4 border-bottom border-block-end-dashed bg-light">
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="avatar avatar-sm bg-primary text-white rounded-circle me-2"><i class="ri-contacts-line fs-16"></i></span>
                                        <h6 class="mb-0 fw-semibold text-primary">Contact Information</h6>
                                    </div>
                                    <div class="list-group list-group-flush placeholder-glow" id="contactList">
                                        <?php foreach ([['ri-mail-line', 'primary', 'Email Address', 'email'], ['ri-phone-line', 'success', 'Phone Number', 'phone'], ['ri-user-line', 'info', 'Username', 'username']] as $i => [$icon, $color, $label, $key]): ?>
                                            <div class="list-group-item bg-white border rounded <?= $i < 2 ? 'mb-2' : '' ?> shadow-sm">
                                                <div class="d-flex align-items-center p-2">
                                                    <span class="avatar avatar-sm avatar-rounded me-3 bg-<?= $color ?>-transparent text-<?= $color ?>"><i class="<?= $icon ?> align-middle fs-14"></i></span>
                                                    <div class="flex-fill" style="min-width: 0;">
                                                        <span class="d-block fs-11 mb-1"><i class="ri-arrow-right-s-line fs-12"></i><?= $label ?></span>
                                                        <span class="fw-semibold text-dark text-break" data-contact="<?= $key ?>"><?= $ph('col-8') ?></span>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-icon btn-light profile-copy d-none" data-copy-field="<?= $key ?>" title="Copy" aria-label="Copy <?= strtolower($label) ?>"><i class="ri-file-copy-line"></i></button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Account details -->
                                <div class="p-4">
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="avatar avatar-sm bg-purple text-white rounded-circle me-2"><i class="ri-information-line fs-16"></i></span>
                                        <h6 class="mb-0 fw-semibold">Account Details</h6>
                                    </div>
                                    <div class="placeholder-glow" id="accountDetails">
                                        <?php foreach (range(1, 4) as $i): ?>
                                            <div class="profile-fact"><span class="placeholder col-12 rounded" style="height: 2.2rem;"></span></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: sections -->
                    <div class="col-xxl-8 col-xl-12">
                        <div class="nav section-tabs is-five" id="profileTabs" role="tablist" aria-label="Profile">
                            <?php foreach ([
                                ['details', 'ri-user-line', 'primary', 'Details'],
                                ['security', 'ri-shield-keyhole-line', 'danger', 'Security'],
                                ['roles', 'ri-briefcase-4-line', 'success', 'Roles'],
                                ['activity', 'ri-history-line', 'purple', 'Activity'],
                                ['signins', 'ri-login-circle-line', 'warning', 'Sign-ins'],
                            ] as $i => [$key, $icon, $color, $label]): ?>
                                <button class="nav-link section-tab<?= $i === 0 ? ' active' : '' ?>" id="tab-<?= $key ?>-btn" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" data-tab="<?= $key ?>" type="button" role="tab" aria-controls="tab-<?= $key ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                                    <span class="section-tab-icon bg-<?= $color ?>"><i class="<?= $icon ?>"></i></span>
                                    <span class="section-tab-text"><strong><?= $label ?></strong><small data-tab-figure="<?= $key ?>">&nbsp;</small></span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="tab-content section-tab-content">
                            <!-- Personal info -->
                            <div class="tab-pane fade show active" id="tab-details" role="tabpanel" aria-labelledby="tab-details-btn">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Your details</div>
                                            <div class="fs-12">How you appear across the system, and how people reach you</div>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm" data-edit-profile disabled><i class="ri-edit-line me-1"></i>Edit my details</button>
                                    </div>
                                    <div class="card-body">
                                        <div class="profile-complete mb-4 placeholder-glow" id="profileComplete"><span class="placeholder col-12 rounded" style="height: 3rem;"></span></div>
                                        <div class="row g-3 placeholder-glow" id="detailsGrid">
                                            <?php foreach (range(1, 8) as $i): ?>
                                                <div class="col-md-6"><span class="placeholder col-12 rounded" style="height: 3.4rem;"></span></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Security -->
                            <div class="tab-pane fade" id="tab-security" role="tabpanel" aria-labelledby="tab-security-btn">
                                <div class="row g-3 mb-1" id="securityCards"></div>
                                <div id="securityAlerts"></div>
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Password changes</div>
                                            <div class="fs-12">Each time your password was changed, and from where</div>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-sm" id="changePasswordBtn"><i class="ri-lock-password-line me-1"></i>Change password</button>
                                    </div>
                                    <div class="card-body" id="passwordTimeline"></div>
                                </div>
                            </div>

                            <!-- Roles -->
                            <div class="tab-pane fade" id="tab-roles" role="tabpanel" aria-labelledby="tab-roles-btn">
                                <div class="card custom-card">
                                    <div class="card-header">
                                        <div>
                                            <div class="card-title">Your roles now</div>
                                            <div class="fs-12">Where you serve. Switch between them from the menu at the top right.</div>
                                        </div>
                                    </div>
                                    <div class="card-body"><div class="row g-3" id="rolesGrid"></div></div>
                                </div>
                                <div class="card custom-card">
                                    <div class="card-header">
                                        <div>
                                            <div class="card-title">Role history</div>
                                            <div class="fs-12">Every role you have been given, including ones that have ended</div>
                                        </div>
                                    </div>
                                    <div class="card-body pb-0"><div id="rolesToolbar"></div></div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" id="rolesTable" width="100%">
                                                <thead><tr><th>Role</th><th>Place</th><th class="d-none d-md-table-cell">Given by</th><th>Since</th><th>Status</th></tr></thead>
                                                <tbody id="rolesTableBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Activity -->
                            <div class="tab-pane fade" id="tab-activity" role="tabpanel" aria-labelledby="tab-activity-btn">
                                <div class="card custom-card">
                                    <div class="card-header justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="card-title">Your activity</div>
                                            <div class="fs-12" id="activitySub">Sign-ins and changes to your account, newest first</div>
                                        </div>
                                        <div id="activityViewWrap"></div>
                                    </div>
                                    <div class="card-body pb-0"><div id="activityToolbar"></div></div>
                                    <div class="card-body pt-2" id="activityTimelineWrap">
                                        <div id="activityTimeline"></div>
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-top pt-3" id="activityTimelineFoot" hidden>
                                            <span class="fs-12 fw-semibold" id="activityTimelineCount"></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="activityMore"><i class="ri-arrow-down-line me-1"></i>Show 20 more</button>
                                        </div>
                                    </div>
                                    <div class="card-body p-0" id="activityTableWrap" hidden>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" id="activityTable" width="100%">
                                                <thead><tr><th>When</th><th>What happened</th><th class="d-none d-md-table-cell">Where</th><th class="text-end">Details</th></tr></thead>
                                                <tbody id="activityTableBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sign-ins -->
                            <div class="tab-pane fade" id="tab-signins" role="tabpanel" aria-labelledby="tab-signins-btn">
                                <div class="row g-3 mb-1 budget-equal-row">
                                    <div class="col-lg-8">
                                        <div class="card custom-card h-100">
                                            <div class="card-header">
                                                <div>
                                                    <div class="card-title">Sign-ins over the last 30 days</div>
                                                    <div class="fs-12" id="signinChartSub">Successful and failed, day by day</div>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div id="signinChart"></div>
                                                <div id="signinChartEmpty" hidden></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4 d-flex flex-column gap-3" id="signinFacts"></div>
                                </div>
                                <div class="card custom-card">
                                    <div class="card-header">
                                        <div>
                                            <div class="card-title">Every sign-in</div>
                                            <div class="fs-12">The browser and network each one came from</div>
                                        </div>
                                    </div>
                                    <div class="card-body pb-0"><div id="signinToolbar"></div></div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" id="signinTable" width="100%">
                                                <thead><tr><th>When</th><th>Browser</th><th class="d-none d-md-table-cell">Network (IP)</th><th>Result</th></tr></thead>
                                                <tbody id="signinTableBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/includes/footer.php' ?>
    </div>

    <!-- Edit my details -->
    <div class="modal fade app-modal" id="editProfileModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="editProfileTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <form class="modal-content" id="editProfileForm" novalidate>
                <div class="modal-header">
                    <span class="app-modal-icon bg-primary"><i class="ri-user-settings-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="editProfileTitle">Edit my details</h5>
                        <div class="app-modal-subtitle">Changes show everywhere straight away</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-preview mb-3" aria-live="polite">
                        <span class="avatar avatar-lg avatar-rounded bg-primary text-white flex-shrink-0" id="previewAvatar">?</span>
                        <div class="flex-fill" style="min-width: 0;">
                            <div class="profile-preview-label">How you'll appear</div>
                            <div class="fw-semibold fs-15 text-break" id="previewName">&nbsp;</div>
                            <div class="fs-13 text-break" id="previewPosition">&nbsp;</div>
                            <div class="fs-12 text-break mt-1" id="previewContact">&nbsp;</div>
                        </div>
                    </div>
                    <div class="profile-changes mb-3" id="previewChanges" hidden></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editFirstname">First name</label>
                            <input type="text" class="form-control" id="editFirstname" name="firstname" maxlength="255" autocomplete="given-name" placeholder="e.g. Benson" required>
                            <div class="invalid-feedback" data-error="firstname"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editLastname">Last name</label>
                            <input type="text" class="form-control" id="editLastname" name="lastname" maxlength="255" autocomplete="family-name" placeholder="e.g. Manoo" required>
                            <div class="invalid-feedback" data-error="lastname"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editPhone">Phone</label>
                            <input type="tel" class="form-control" id="editPhone" name="phone" maxlength="20" autocomplete="tel" placeholder="0712 345 678">
                            <div class="form-text">A Kenyan mobile number. It's saved as +254…</div>
                            <div class="invalid-feedback" data-error="phone"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editEmail">Email</label>
                            <input type="email" class="form-control" id="editEmail" name="email" maxlength="255" autocomplete="email" placeholder="name@example.com">
                            <div class="invalid-feedback" data-error="email"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editUsername">Username</label>
                            <input type="text" class="form-control" id="editUsername" name="username" maxlength="255" autocomplete="username" placeholder="e.g. benson.manoo">
                            <div class="invalid-feedback" data-error="username"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="budget-field-label" for="editPosition">Position</label>
                            <input type="text" class="form-control" id="editPosition" name="position" maxlength="255" placeholder="e.g. Senior Pastor - CCI Sultan Hamud">
                            <div class="invalid-feedback" data-error="position"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto fs-12" id="editProfileSummary">Nothing changed yet</div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editProfileSave" disabled><i class="ri-check-line me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Your photo -->
    <div class="modal fade app-modal" id="photoModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="photoModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <form class="modal-content" id="photoForm" novalidate>
                <div class="modal-header">
                    <span class="app-modal-icon bg-primary"><i class="ri-camera-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="photoModalTitle">Your photo</h5>
                        <div class="app-modal-subtitle">Shown beside your name across the system</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="profile-photo-stage">
                        <span class="profile-photo-preview bg-primary text-white" id="photoPreview">?</span>
                        <div class="fw-semibold fs-15 mt-3 text-break" id="photoName">&nbsp;</div>
                        <div class="fs-13" id="photoState">&nbsp;</div>
                        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                            <button type="button" class="btn btn-primary" id="photoPick"><i class="ri-image-add-line me-1"></i>Choose a photo</button>
                            <button type="button" class="btn btn-outline-danger" id="photoRemove" hidden><i class="ri-delete-bin-line me-1"></i>Remove photo</button>
                        </div>
                        <input type="file" id="photoInput" name="photo" accept="image/png,image/jpeg,image/webp" hidden>
                        <div class="text-danger fs-13 fw-semibold mt-2" id="photoError" role="alert" hidden></div>
                    </div>
                    <div class="profile-photo-hint mt-3">
                        <span class="profile-photo-hint-icon"><i class="ri-lightbulb-line"></i></span>
                        <span>A clear photo of your face works best. We crop it to a square from the middle. PNG, JPG or WebP, up to 5 MB.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto fs-12" id="photoSummary">Choose a photo to save</div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="photoSave" disabled><i class="ri-check-line me-1"></i>Save photo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change password -->
    <div class="modal fade app-modal" id="passwordModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="passwordModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <form class="modal-content" id="passwordForm" novalidate>
                <div class="modal-header">
                    <span class="app-modal-icon bg-danger"><i class="ri-lock-password-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="passwordModalTitle">Change password</h5>
                        <div class="app-modal-subtitle">You'll stay signed in on this device</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php foreach ([['currentPassword', 'current_password', 'Current password', 'current-password', 'The password you use now'], ['newPassword', 'new_password', 'New password', 'new-password', 'At least 8 characters, with a letter and a number'], ['confirmPassword', 'new_password_confirmation', 'Type the new password again', 'new-password', 'The same new password']] as [$id, $name, $label, $auto, $hint]): ?>
                        <label class="budget-field-label" for="<?= $id ?>"><?= $label ?></label>
                        <div class="input-group has-validation mb-3">
                            <input type="password" class="form-control" id="<?= $id ?>" name="<?= $name ?>" autocomplete="<?= $auto ?>" placeholder="<?= $hint ?>" required>
                            <button type="button" class="btn btn-light border" data-toggle-password="<?= $id ?>" aria-label="Show password"><i class="ri-eye-line"></i></button>
                            <div class="invalid-feedback" data-error="<?= $name ?>"></div>
                        </div>
                        <?php if ($id === 'newPassword'): ?>
                            <div class="profile-strength mb-2" aria-hidden="true"><span id="strengthBar"></span></div>
                            <div class="fs-12 mb-3 fw-semibold" id="strengthLabel">&nbsp;</div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div id="passwordChecklist"></div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto fs-12" id="passwordSummary">Fill in all three</div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="passwordSave" disabled><i class="ri-lock-line me-1"></i>Change password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity details -->
    <div class="modal fade app-modal" id="activityModal" tabindex="-1" aria-labelledby="activityModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="app-modal-icon" id="activityModalIcon"><i class="ri-history-line"></i></span>
                    <div class="flex-fill" style="min-width: 0;">
                        <h5 class="modal-title" id="activityModalTitle">Activity</h5>
                        <div class="app-modal-subtitle" id="activityModalSub">&nbsp;</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="activityModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php budgetPageScripts('assets/js/pages/profile/profile.js', true) ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.ProfilePage.init());</script>
</body>

</html>
