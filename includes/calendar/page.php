<?php
// Calendar - one page per level (docs/specs/calendar-spec.md). The wrapper sets $calendarCtx (includes/calendar/context.php).
// Everything below is drawn by assets/js/pages/calendar/*.js from GET /calendar/*.
$pageTitle = 'Calendar';
$pageIcon = 'ri-calendar-event-line';
$breadcrumbs = ['Home' => $calendarCtx['homeUrl'], 'Calendar' => null];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="<?= appearanceHtmlClasses() ?>" data-nav-layout="vertical" <?= appearanceThemeAttributes() ?>
    data-menu-styles="dark" data-toggled="close">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>Calendar - Makueni West Diocese</title>
    <meta name="Description" content="Your events, and the CCI, diocese and regional calendars above you" />
    <link rel="icon" href="<?= SITE_URL ?>/assets/images/brand-logos/favicon/favicon.ico" type="image/x-icon" />
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
    <link id="style" href="<?= SITE_URL ?>/assets/libs/bootstrap/css/bootstrap.min.css" rel="stylesheet" />
    <?php calendarPageStyles() ?>
    <link href="<?= SITE_URL ?>/assets/css/icons.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/node-waves/waves.min.css" rel="stylesheet" />
    <link href="<?= SITE_URL ?>/assets/libs/simplebar/simplebar.min.css" rel="stylesheet" />
</head>

<body>
    <script src="<?= SITE_URL ?>/assets/js/config/app.js"></script>
    <script src="<?= SITE_URL ?>/assets/js/config/constants.js"></script>
    <script>window.CALENDAR_CTX = <?= json_encode($calendarCtx) ?>;</script>
    <?php include __DIR__ . '/../start-switcher.php' ?>
    <?php include __DIR__ . '/../loader.php' ?>

    <div class="page">
        <?php include __DIR__ . '/../header.php' ?>
        <?php include __DIR__ . '/../sidebar.php' ?>

        <div class="main-content app-content">
            <div class="container-fluid">

                <?php include __DIR__ . '/../page-header.php' ?>

                <div class="page-toolbar">
                    <div class="page-toolbar-sub" id="placeLine"><?= htmlspecialchars($calendarCtx['place']['name'] ?: 'Your calendar') ?></div>
                    <div class="page-toolbar-controls">
                        <div id="viewSwitchWrap"></div>
                        <button type="button" class="btn btn-outline-primary" id="icsBtn" title="Download what's on screen, to import into Google or Outlook"><i class="ri-download-2-line me-1"></i>Download (.ics)</button>
                        <button type="button" class="btn btn-primary d-none" id="addEventBtn"><i class="ri-add-line me-1"></i>New date</button>
                    </div>
                </div>

                <div class="nav section-tabs d-none" id="calendarTabs" role="tablist" aria-label="Calendar">
                    <button class="nav-link section-tab<?= $calendarCtx['tab'] === 'calendar' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-calendar" data-tab="calendar" type="button" role="tab">
                        <span class="section-tab-icon bg-primary"><i class="ri-calendar-event-line"></i></span>
                        <span class="section-tab-text"><strong>Calendar</strong><small data-tab-figure="calendar">&nbsp;</small></span>
                    </button>
                    <button class="nav-link section-tab<?= $calendarCtx['tab'] === 'cci' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-cci" data-tab="cci" type="button" role="tab">
                        <span class="section-tab-icon bg-danger"><i class="ri-government-line"></i></span>
                        <span class="section-tab-text"><strong>CCI national calendar</strong><small data-tab-figure="cci">&nbsp;</small></span>
                    </button>
                </div>

                <div class="tab-content section-tab-content">
                    <div class="tab-pane fade<?= $calendarCtx['tab'] === 'calendar' ? ' show active' : '' ?>" id="tab-calendar" role="tabpanel">
                        <!-- The clean month (2026-10-08): a left column (small month, the chosen day, what to show, whose dates, what needs doing) beside the grid -->
                        <div class="row g-4 cal-layout">
                            <div class="col-xxl-3 col-xl-4 cal-side-col">
                                <div class="card custom-card"><div class="card-body cal-mini" id="calMini"><span class="skel skel-line"></span><span class="skel skel-chart mt-2" style="height:200px;display:block"></span></div></div>
                                <div class="card custom-card cal-day" id="calDay"><div class="card-body"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:70%"></span></div></div>
                                <div class="card custom-card"><div class="card-body cal-checks" id="calShow"><span class="skel skel-line"></span><span class="skel skel-line mt-2"></span><span class="skel skel-line mt-2" style="width:70%"></span></div></div>
                                <div class="card custom-card"><div class="card-body cal-checks" id="calWhose"><span class="skel skel-line"></span><span class="skel skel-line mt-2" style="width:70%"></span></div></div>
                                <div class="card custom-card d-none" id="calAttentionCard"><div class="card-body cal-checks" id="calAttention"></div></div>
                            </div>
                            <div class="col-xxl-9 col-xl-8">
                                <div class="card custom-card">
                                    <div class="card-body">
                                        <div id="calendar" class="cal-board cal-clean" aria-live="polite"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade<?= $calendarCtx['tab'] === 'cci' ? ' show active' : '' ?>" id="tab-cci" role="tabpanel">
                        <div id="cciBody"></div>
                    </div>
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/../footer.php' ?>
    </div>

    <div class="scrollToTop"><span class="arrow"><i class="ri-arrow-up-s-fill fs-20"></i></span></div>
    <div id="responsive-overlay"></div>

    <?php calendarPageScripts() ?>
    <script>document.addEventListener('DOMContentLoaded', () => window.CalendarPage.init());</script>
</body>

</html>
