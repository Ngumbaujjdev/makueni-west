<?php
/**
 * Facilities (docs/specs/people-and-care-spec.md, P5): the church's rooms and
 * bookings, equipment and loans, repairs and the duty rota. The bodies live
 * in includes/facilities/body-*.php; church/facilities/*.php are thin
 * wrappers. Who may book, change or cancel is decided server-side.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/** @param string $page index | bookings | equipment | item | assets | repairs | rota */
function facilitiesPageContext(string $page): array
{
    $permission = [
        'bookings' => 'church.facilities.bookings.read',
        'equipment' => 'church.facilities.equipment.read',
        'item' => 'church.facilities.equipment.read',
        'assets' => 'church.facilities.assets.read',
        'repairs' => 'church.facilities.repairs.read',
        'rota' => 'church.facilities.rota.read',
    ][$page] ?? 'church.facilities.facilities.read';
    requirePermission($permission);
    $role = getCurrentRole() ?? [];
    $user = getAuthUser() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("church.{$p}");

    return [
        'page' => $page,
        'baseUrl' => SITE_URL . '/church/facilities',
        'membersUrl' => SITE_URL . '/church/members',
        'ministriesUrl' => SITE_URL . '/church/ministries',
        'eventsUrl' => SITE_URL . '/church/events',
        'settingsUrl' => SITE_URL . '/church/settings/?section=facilities',
        'homeUrl' => SITE_URL . '/church/dashboard',
        'siteUrl' => SITE_URL,
        'userId' => (int) ($user['id'] ?? 0),
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'manage' => $can('facilities.facilities.manage'),
            'book' => $can('facilities.facilities.book') || $can('facilities.facilities.manage'),
            'members' => $can('members.members.read'),
            'budget' => $can('budget.budgets.read') || $can('budgets.budgets.read') || hasGlobalAccess(),
            'export' => $can('facilities.facilities.export'),
        ],
    ];
}

function facilitiesPageStyles(string $page): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    $css = ['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'];
    if ($page === 'bookings') {
        $css[] = 'assets/libs/fullcalendar/main.min.css';
    }
    if ($page === 'repairs') {
        $css[] = 'assets/libs/dragula/dragula.min.css';
    }
    if ($page === 'item') {
        // Its photos open full size.
        $css[] = 'assets/libs/glightbox/css/glightbox.min.css';
    }
    foreach ($css as $c) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$c}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function facilitiesPageScripts(string $page): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach ([
        'assets/libs/@popperjs/core/umd/popper.min.js', 'assets/libs/bootstrap/js/bootstrap.bundle.min.js', 'assets/js/defaultmenu.min.js',
        'assets/libs/node-waves/waves.min.js', 'assets/js/sticky.js', 'assets/libs/simplebar/simplebar.min.js', 'assets/js/simplebar.js',
        'assets/js/custom-switcher.min.js', 'assets/js/custom.js', 'assets/libs/apexcharts/apexcharts.min.js',
    ] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    echo '<script src="' . $v('assets/js/utils/toast.js') . '"></script>' . "\n";
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>' . "\n";
    $libs = ['assets/libs/select2/select2.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'];
    if ($page === 'bookings') {
        $libs[] = 'assets/libs/fullcalendar/main.min.js';
    }
    if ($page === 'repairs') {
        $libs[] = 'assets/libs/dragula/dragula.min.js';
    }
    if ($page === 'item') {
        $libs[] = 'assets/libs/glightbox/js/glightbox.min.js';
    }
    foreach ($libs as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    $scripts = ['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/api.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', 'assets/js/pages/ministries/ui.js', 'assets/js/pages/facilities/api.js', 'assets/js/pages/facilities/ui.js'];
    if ($page === 'repairs' || $page === 'item') {
        // "Record the cost" opens the Budgets Record money window.
        $scripts = [...$scripts, 'assets/js/pages/budgets/api.js', 'assets/js/pages/budgets/ui.js', 'assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/demographics/attendance-form-shared.js'];
    }
    $scripts[] = "assets/js/pages/facilities/{$page}.js";
    foreach ($scripts as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
