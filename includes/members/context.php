<?php
/**
 * Members - the church's private register (docs/specs/people-and-care-spec.md,
 * P1). The bodies live in includes/members/body-*.php; church/members/*.php
 * are thin wrappers, and region/people/members.php and
 * diocese/people/members.php are the totals pages (counts, never names).
 * What the API allows is decided server-side (App\Support\PeopleAccess);
 * this only checks the page may open and tells the scripts where they are.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page list | form | member | transfers | insights | totals
 */
function membersPageContext(string $level, string $page): array
{
    requirePermission($page === 'totals' ? "{$level}.members.below.read" : 'church.members.members.read');
    $role = getCurrentRole() ?? [];
    $can = fn (string $permission) => hasGlobalAccess() || hasPermission("{$level}.{$permission}");

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . '/church/members',
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'messagesUrl' => SITE_URL . "/{$level}/messages/new",
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'can' => [
            'manage' => $level === 'church' && $can('members.members.manage'),
            'export' => $level === 'church' && $can('members.members.export'),
        ],
    ];
}

function membersPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function membersPageScripts(string $page): void
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
    foreach (['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/api.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', "assets/js/pages/members/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
