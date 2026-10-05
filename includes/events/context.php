<?php
/**
 * Events pages are shared by every level (docs/specs/events-initiatives-spec.md):
 * the bodies live in includes/events/*.php and each level has thin wrappers
 * - {church,region,diocese}/events/{index,new,event}.php. What the API allows
 * is decided server-side (App\Support\EventsAccess); this only checks the
 * page may open and tells the scripts where they are and what they may offer.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';
require_once __DIR__ . '/../budget/context.php'; // BUDGET_LEVELS, for the Record money window

/**
 * @param string $level church | region | diocese
 * @param string $page list | form | event
 */
function eventsPageContext(string $level, string $page): array
{
    requirePermission("{$level}.events.events.read");
    $role = getCurrentRole() ?? [];
    $can = fn (string $permission) => hasGlobalAccess() || hasPermission("{$level}.{$permission}");
    $place = ['id' => (int) ($role['territory_id'] ?? 0), 'name' => $role['territory']['name'] ?? $role['territory_name'] ?? ''];

    return [
        'level' => $level,
        'page' => $page,
        'baseUrl' => SITE_URL . "/{$level}/events",
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'place' => $place,
        'can' => [
            'manage' => $can('events.events.manage'),
            'register' => $can('events.events.register'),
            'below' => $level !== 'church' && $can('events.below.read'),
        ],
        // What BudgetsUI expects (window.BUDGET_CTX) when the Record money window opens here.
        'budget' => [
            'level' => $level,
            'baseUrl' => SITE_URL . BUDGET_LEVELS[$level]['path'],
            'homeUrl' => SITE_URL . "/{$level}/dashboard",
            'place' => $place,
            'can' => [
                'read' => $can('budgets.budgets.read'),
                'prepare' => $can('budgets.budgets.prepare'),
                'export' => $can('budgets.budgets.export'),
                'below' => $level !== 'church' && $can('budgets.below.read'),
                'record' => $can('budgets.spending.record'),
                'settings' => $can('settings.budgetsettings.update'),
            ],
        ],
    ];
}

function eventsPageStyles(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach (['assets/libs/select2/select2.min.css', 'assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css', 'assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css'] as $css) {
        echo '<link rel="stylesheet" href="' . SITE_URL . "/{$css}\" />\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

function eventsPageScripts(string $page): void
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
    $scripts = ['assets/js/pages/demographics/api-handler.js', 'assets/js/pages/demographics/ui-helpers.js', 'assets/js/pages/events/api.js', 'assets/js/pages/events/ui.js'];
    if ($page === 'event') {
        // The Record money window (Budgets) and the attendance form (Attendance), opened for this event.
        $scripts = [...$scripts, 'assets/js/pages/budgets/api.js', 'assets/js/pages/budgets/ui.js', 'assets/js/pages/budgets/entry-modal.js', 'assets/js/pages/demographics/attendance-form-shared.js'];
    }
    $scripts[] = "assets/js/pages/events/{$page}.js";
    foreach ($scripts as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
