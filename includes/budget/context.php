<?php
/**
 * Budget pages are shared by every level (docs/specs/budgets-spec.md): the
 * page bodies live in includes/budget/*.php, and each level has thin
 * wrappers - church/budget/*, region/budgets/*, diocese/budgets/* - that
 * call budgetPageContext() and include the body.
 *
 * The body reads links, the place and what to offer from $budgetCtx, which
 * the scripts also get as window.BUDGET_CTX. What the API allows is decided
 * server-side (BudgetAccess); these flags only decide which buttons show.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

const BUDGET_LEVELS = [
    'church' => ['path' => '/church/budget', 'label' => 'church'],
    'region' => ['path' => '/region/budgets', 'label' => 'region'],
    'diocese' => ['path' => '/diocese/budgets', 'label' => 'diocese'],
];

/**
 * Check the page's permission and build its context.
 * @param string $level church | region | diocese
 * @param string $needs read | prepare (budgets), overview, spending, export (reports), below, contributions, settings
 */
function budgetPageContext(string $level, string $needs = 'read'): array
{
    $permission = [
        'read' => 'budgets.budgets.read',
        'prepare' => 'budgets.budgets.prepare',
        'overview' => 'budgets.overview.read',
        'spending' => 'budgets.spending.read',
        'export' => 'budgets.budgets.export',
        'below' => 'budgets.below.read',
        'contributions' => 'budgets.contributions.read',
        'settings' => 'settings.budgetsettings.read',
    ][$needs];
    requirePermission("{$level}.{$permission}");

    $role = getCurrentRole() ?? [];
    $can = fn (string $permission) => hasGlobalAccess() || hasPermission("{$level}.{$permission}");

    return [
        'level' => $level,
        'baseUrl' => SITE_URL . BUDGET_LEVELS[$level]['path'],
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'place' => [
            'id' => (int) ($role['territory_id'] ?? 0),
            'name' => $role['territory']['name'] ?? $role['territory_name'] ?? '',
        ],
        'can' => [
            'read' => $can('budgets.budgets.read'),
            'prepare' => $can('budgets.budgets.prepare'),
            'export' => $can('budgets.budgets.export'),
            'below' => $level !== 'church' && $can('budgets.below.read'),
            'record' => $can('budgets.spending.record'),
            'settings' => $can('settings.budgetsettings.update'),
        ],
    ];
}

/** The <head> styles every budget page loads. */
function budgetPageStyles(bool $withTables = false): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/libs/select2/select2.min.css" />' . "\n";
    if ($withTables) {
        echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />' . "\n";
        echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />' . "\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

/** The scripts every budget page loads, then the page's own (one path, or several). */
function budgetPageScripts(string|array $pageScript, bool $withTables = false): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    $base = [
        'assets/libs/@popperjs/core/umd/popper.min.js',
        'assets/libs/bootstrap/js/bootstrap.bundle.min.js',
        'assets/js/defaultmenu.min.js',
        'assets/libs/node-waves/waves.min.js',
        'assets/js/sticky.js',
        'assets/libs/simplebar/simplebar.min.js',
        'assets/js/simplebar.js',
        'assets/js/custom-switcher.min.js',
        'assets/js/custom.js',
        'assets/libs/apexcharts/apexcharts.min.js',
    ];
    foreach ($base as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    echo '<script src="' . $v('assets/js/utils/toast.js') . '"></script>' . "\n";
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>' . "\n";
    echo '<script src="' . SITE_URL . '/assets/libs/select2/select2.min.js"></script>' . "\n";
    if ($withTables) {
        foreach (['assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'] as $src) {
            echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
        }
    }
    foreach (['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/budgets/api.js', 'assets/js/pages/budgets/ui.js', ...(array) $pageScript] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
