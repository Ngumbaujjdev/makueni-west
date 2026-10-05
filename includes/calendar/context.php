<?php
/**
 * The Calendar is shared by every level (docs/specs/calendar-spec.md): the
 * page body lives in includes/calendar/page.php and each level has a thin
 * wrapper - church/calendar/index.php, region/calendar/index.php,
 * diocese/calendar/index.php - that calls calendarPageContext() and
 * includes it. What the API allows (whose events, who may edit, the CCI
 * calendar for global admins) is decided server-side (App\Support\CalendarAccess).
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/** Check the page's permission and build its context. */
function calendarPageContext(string $level): array
{
    requirePermission("{$level}.calendar.events.read");
    $role = getCurrentRole() ?? [];
    $tab = ($_GET['tab'] ?? '') === 'cci' && $level === 'diocese' ? 'cci' : 'calendar';

    return [
        'level' => $level,
        'tab' => $tab,
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL, // Church life items (C3) link to their own pages
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
    ];
}

function calendarPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/libs/fullcalendar/main.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function calendarPageScripts(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach ([
        'assets/libs/@popperjs/core/umd/popper.min.js',
        'assets/libs/bootstrap/js/bootstrap.bundle.min.js',
        'assets/js/defaultmenu.min.js',
        'assets/libs/node-waves/waves.min.js',
        'assets/js/sticky.js',
        'assets/libs/simplebar/simplebar.min.js',
        'assets/js/simplebar.js',
        'assets/js/custom-switcher.min.js',
        'assets/js/custom.js',
    ] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    echo '<script src="' . $v('assets/js/utils/toast.js') . '"></script>' . "\n";
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>' . "\n";
    foreach ([
        'assets/libs/select2/select2.min.js',
        'assets/data-tables/1.12.1/js/jquery.dataTables.min.js',
        'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js',
        'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js',
        'assets/libs/apexcharts/apexcharts.min.js',
        'assets/libs/fullcalendar/main.min.js',
    ] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    foreach (['assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/calendar/api.js', 'assets/js/pages/calendar/event-modal.js', 'assets/js/pages/calendar/cci.js', 'assets/js/pages/calendar/calendar.js'] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
