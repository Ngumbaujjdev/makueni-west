<?php
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

requirePermission('church.settings.attendancesettings.gatheringtypes.read');

$canWrite = hasPermission('church.settings.attendancesettings.gatheringtypes.create')
    || hasPermission('church.settings.attendancesettings.gatheringtypes.update');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Gathering Types';
$pageIcon = 'ri-settings-3-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance Settings' => null,
    'Gathering Types' => null,
];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light"
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Gathering Types - Makueni West Diocese</title>
    <meta name="Description" content="Configure this church's own gathering types used in attendance entry" />

    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/styles.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />

    <script>
        const USER_TERRITORY = {
            id: <?= json_encode($userTerritoryId) ?>,
            name: '<?= addslashes($userTerritoryName) ?>'
        };
        const CAN_WRITE_GATHERING_TYPES = <?= $canWrite ? 'true' : 'false' ?>;
    </script>
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <?php include __DIR__ . '/../../../includes/start-switcher.php' ?>
    <?php include __DIR__ . '/../../../includes/loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../../../includes/header.php' ?>
        <?php include __DIR__ . '/../../../includes/sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../../../includes/page-header.php' ?>

                <div class="alert alert-info bg-info-transparent border-0 mb-3">
                    <i class="ri-information-line me-2"></i>
                    These are your own church's gathering types - not shared with other churches. Sunday Service isn't
                    listed here since it doesn't use a gathering type.
                </div>

                <div class="row g-3 mb-3" id="statCardsRow">
                    <!-- Stat cards injected by attendance-gathering-types.js -->
                </div>

                <div class="card custom-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="card-title"><i class="ri-list-check-2 me-2 text-primary"></i>Gathering Types</div>
                        <?php if ($canWrite): ?>
                        <button type="button" class="btn btn-primary btn-wave" id="addGatheringTypeBtn">
                            <i class="ri-add-line me-1"></i>Add Gathering Type
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="gatheringTypesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="fw-semibold text-dark">Name</th>
                                        <th class="fw-semibold text-dark">Category</th>
                                        <th class="fw-semibold text-dark text-center">Status</th>
                                        <th class="fw-semibold text-dark text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="gatheringTypesTableBody">
                                    <!-- Rows injected by attendance-gathering-types.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../../../includes/footer.php' ?>
    </div>

    <!-- Create/Edit Gathering Type Modal -->
    <div class="modal fade" id="gatheringTypeModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold" id="gatheringTypeModalTitle">Add Gathering Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="gatheringTypeId">
                    <div class="mb-3">
                        <label for="gatheringTypeName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="gatheringTypeName" placeholder="e.g. Kesha (All-Night Prayer)" required>
                    </div>
                    <div class="mb-3">
                        <label for="gatheringTypeCategory" class="form-label">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="gatheringTypeCategory" required>
                            <option value="">Loading categories...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="gatheringTypeIcon" class="form-label">Icon <span class="fs-12 text-body">(optional, Remix Icon class - browse icons at remixicon.com)</span></label>
                        <div class="input-group">
                            <span class="input-group-text" id="gatheringTypeIconPreview"><i class="ri-calendar-event-line"></i></span>
                            <input type="text" class="form-control" id="gatheringTypeIcon" placeholder="e.g. ri-moon-line">
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="gatheringTypeActive" checked>
                        <label class="form-check-label" for="gatheringTypeActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveGatheringTypeBtn">
                        <i class="ri-save-line me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div class="modal fade" id="gatheringTypeAuditModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="ri-history-line me-2"></i>Activity Log</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="gatheringTypeAuditBody">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>
    </div>

    <div class="scrollToTop">
        <span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span>
    </div>
    <div id="responsive-overlay"></div>

    <script src="<?= SITE_URL ?>/assets/libs/@popperjs/core/umd/popper.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/defaultmenu.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/sticky.js"></script>
    <script src="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/simplebar.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom-switcher.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/custom.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/utils/toast.js"></script>

    <!-- jQuery + DataTables (search/filter/pagination) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/jquery.dataTables.min.js"></script>
    <script src="<?= SITE_URL ?>/assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js"></script>

    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/api-handler.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/ui-helpers.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/pages/demographics/attendance-gathering-types.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => window.AttendanceGatheringTypes.init());
    </script>
</body>

</html>
