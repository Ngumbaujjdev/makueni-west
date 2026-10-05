<?php
/**
 * Monthly reports pages are shared by every level (docs/specs/monthly-reports-spec.md):
 * the bodies live in includes/monthly-reports/*.php and each level has thin wrappers -
 * {church,region,diocese}/monthly-reports/{index,report}.php. What the API allows is
 * decided server-side (App\Support\ReportsAccess); this only checks the page may open
 * and tells the scripts where they are and what they may offer.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

/**
 * @param string $level church | region | diocese
 * @param string $page index | report
 */
function reportsPageContext(string $level, string $page): array
{
    requirePermission("{$level}.reports.monthly.read");
    $role = getCurrentRole() ?? [];
    $can = fn (string $permission) => hasGlobalAccess() || hasPermission("{$level}.{$permission}");
    $reports = in_array($level, ['church', 'region'], true); // the diocese reads; it doesn't send one

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . "/{$level}/monthly-reports",
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'siteUrl' => SITE_URL,
        'place' => ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''],
        'reports' => $reports,
        'can' => [
            'write' => $reports && $can('reports.monthly.write'),
            'send' => $reports && $can('reports.monthly.send'),
            'below' => $level !== 'church' && ($can('reports.below.read') || $can('reports.below.review')),
            'review' => $level !== 'church' && $can('reports.below.review'),
        ],
    ];
}

function reportsPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function reportsPageScripts(string $page): void
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
    foreach (['assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/monthly-reports/api.js', 'assets/js/pages/monthly-reports/ui.js', "assets/js/pages/monthly-reports/{$page}.js"] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
