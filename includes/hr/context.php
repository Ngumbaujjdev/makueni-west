<?php
/**
 * Staff (docs/specs/hr-spec.md) - the same two pages at the church, region and
 * diocese: the bodies live in includes/hr/body-*.php and
 * {church,region,diocese}/hr/*.php are thin wrappers. What the API allows is
 * decided server-side (App\Support\HrAccess); this only checks the page may
 * open and tells the scripts where they are.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page  staff | positions | person | item
 */
function hrPageContext(string $level, string $page): array
{
    $any = ["{$level}.hr.staff.read", "{$level}.hr.staff.manage", "{$level}.hr.setup.manage"];
    if (! hasGlobalAccess() && ! hasAnyPermission($any)) {
        requirePermission("{$level}.hr.staff.read");
    }
    $role = getCurrentRole() ?? [];
    $can = fn (string $p) => hasGlobalAccess() || hasPermission("{$level}.hr.{$p}");
    $place = ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''];

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . "/{$level}/hr",
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'payrollUrl' => SITE_URL . "/{$level}/accounting/payroll.php",
        'membersUrl' => SITE_URL . '/church/members',
        'place' => $place,
        'can' => [
            'manage' => $can('staff.manage'),
            'setup' => $can('setup.manage'),
            'below' => $level !== 'church' && $can('below.read'),
        ],
    ];
}

function hrPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/libs/flatpickr/flatpickr.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function hrPageScripts(string $page): void
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
    foreach (['assets/libs/select2/select2.min.js', 'assets/libs/flatpickr/flatpickr.min.js', 'assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js'] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    // The Accounting kit (tables, money, how-to-pay fields) and the People kit (windows in parts) are reused as they are.
    foreach (['assets/js/utils/date-field.js', 'assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/members/ui.js', 'assets/js/pages/members/list-kit.js', 'assets/js/pages/accounting/api.js', 'assets/js/pages/accounting/ui.js', 'assets/js/pages/accounting/windows.js', 'assets/js/pages/hr/api.js', 'assets/js/pages/hr/windows.js', "assets/js/pages/hr/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
