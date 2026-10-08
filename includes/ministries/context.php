<?php
/**
 * Ministries (docs/specs/people-and-care-spec.md, P4). The bodies live in
 * includes/ministries/body-*.php; church/ministries/*.php are thin wrappers,
 * and region/people/ministries.php and diocese/people/ministries.php are the
 * totals pages (counts, never a name). What a ministry's own leader may
 * change is decided server-side.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page index | ministry | insights | totals
 */
function ministriesPageContext(string $level, string $page): array
{
    $permission = [
        'totals' => "{$level}.ministries.below.read",
        'insights' => 'church.ministries.insights.read',
    ][$page] ?? 'church.ministries.ministries.read';
    requirePermission($permission);
    $role = getCurrentRole() ?? [];
    $user = getAuthUser() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("{$level}.{$p}");

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . '/church/ministries',
        'membersUrl' => SITE_URL . '/church/members',
        'visitorsUrl' => SITE_URL . '/church/visitors',
        'attendanceUrl' => SITE_URL . '/church/attendance',
        'eventsUrl' => SITE_URL . '/church/events',
        'initiativesUrl' => SITE_URL . '/church/initiatives',
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'messagesUrl' => SITE_URL . "/{$level}/messages/new",
        'userId' => (int) ($user['id'] ?? 0),
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'manage' => $level === 'church' && $can('ministries.ministries.manage'),
            'members' => $level === 'church' && $can('members.members.read'),
            'insights' => $level === 'church' && $can('ministries.insights.read'),
            'message' => $level === 'church' && $can('messages.messages.send'),
            'book_room' => $level === 'church' && ($can('facilities.facilities.book') || $can('facilities.facilities.manage')),
        ],
    ];
}

function ministriesPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

/** The Ministries API and look - also loaded on the members list (Add to ministry) and a member's page. */
function ministriesScripts(): array
{
    return ['assets/js/pages/ministries/api.js', 'assets/js/pages/ministries/ui.js'];
}

function ministriesPageScripts(string $page): void
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
    foreach (['assets/libs/select2/select2.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    foreach (['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/api.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', ...ministriesScripts(), "assets/js/pages/ministries/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
