<?php
/**
 * Pastoral care (docs/specs/people-and-care-spec.md, P3). The bodies live in
 * includes/care/body-*.php; church/pastoral-care/*.php are thin wrappers,
 * and region/people/care.php and diocese/people/care.php are the totals
 * pages (counts, never a name or a note). What the API allows - including
 * who reads a confidential note - is decided server-side.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page index | log | case | hospital | prayer | totals
 */
function carePageContext(string $level, string $page): array
{
    $permission = [
        'totals' => "{$level}.pastoral.below.read",
        'log' => 'church.pastoral.log.read',
        'hospital' => 'church.pastoral.hospital.read',
        'prayer' => 'church.pastoral.prayer.read',
    ][$page] ?? 'church.pastoral.care.read';
    requirePermission($permission);
    $role = getCurrentRole() ?? [];
    $user = getAuthUser() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("{$level}.{$p}");

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . '/church/pastoral-care',
        'membersUrl' => SITE_URL . '/church/members',
        'visitorsUrl' => SITE_URL . '/church/visitors',
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'messagesUrl' => SITE_URL . "/{$level}/messages/new",
        'userId' => (int) ($user['id'] ?? 0),
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'manage' => $level === 'church' && $can('pastoral.care.manage'),
            'message' => $level === 'church' && $can('messages.messages.send'),
        ],
    ];
}

function carePageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

/** The Care API and look - also loaded on the member and visitor pages (their Care tab and Record care). */
function careScripts(): array
{
    return ['assets/js/pages/care/api.js', 'assets/js/pages/care/ui.js'];
}

function carePageScripts(string $page): void
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
    foreach (['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/api.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', ...careScripts(), "assets/js/pages/care/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
