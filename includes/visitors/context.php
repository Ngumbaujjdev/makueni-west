<?php
/**
 * Visitors - visitors and their follow-up (docs/specs/people-and-care-spec.md,
 * P2). The bodies live in includes/visitors/body-*.php; church/visitors/*.php
 * are thin wrappers, and region/people/visitors.php and
 * diocese/people/visitors.php are the totals pages (counts, never names).
 * What the API allows is decided server-side (App\Support\PeopleAccess);
 * this only checks the page may open and tells the scripts where they are.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page list | new | visitor | insights | totals
 */
function visitorsPageContext(string $level, string $page): array
{
    $permission = [
        'totals' => "{$level}.visitors.below.read",
        'new' => 'church.visitors.visitors.manage',
        'insights' => 'church.visitors.insights.read',
    ][$page] ?? 'church.visitors.visitors.read';
    requirePermission($permission);
    $role = getCurrentRole() ?? [];
    $user = getAuthUser() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("{$level}.{$p}");

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . '/church/visitors',
        'membersUrl' => SITE_URL . '/church/members',
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'careUrl' => SITE_URL . '/church/pastoral-care',
        'messagesUrl' => SITE_URL . "/{$level}/messages/new",
        'userId' => (int) ($user['id'] ?? 0),
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'care' => $level === 'church' && $can('pastoral.care.read'),
            'care_manage' => $level === 'church' && $can('pastoral.care.manage'),
            'manage' => $level === 'church' && $can('visitors.visitors.manage'),
            'insights' => $level === 'church' && $can('visitors.insights.read'),
            'message' => $level === 'church' && $can('messages.messages.send'),
        ],
    ];
}

function visitorsPageStyles(string $page): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    $css = ['assets/libs/select2/select2.min.css', 'assets/libs/flatpickr/flatpickr.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'];
    if ($page === 'list') {
        $css[] = 'assets/libs/dragula/dragula.min.css';
    }
    foreach ($css as $file) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$file}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function visitorsPageScripts(string $page): void
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
    $libs = ['assets/libs/select2/select2.min.js', 'assets/libs/flatpickr/flatpickr.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'];
    if ($page === 'list') {
        $libs[] = 'assets/libs/dragula/dragula.min.js';
    }
    foreach ($libs as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    // Members' look (avatars, dates, empty states) is shared; the Visitors API and look sit on top.
    foreach (['assets/js/utils/date-field.js', 'assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/api.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', ...($page === 'visitor' ? ['assets/js/pages/care/api.js', 'assets/js/pages/care/ui.js'] : []), 'assets/js/pages/visitors/api.js', 'assets/js/pages/visitors/ui.js', "assets/js/pages/visitors/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
