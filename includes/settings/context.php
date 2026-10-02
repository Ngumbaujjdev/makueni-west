<?php
/**
 * The Settings hub is shared by every level (docs/specs/settings-spec.md):
 * the page body lives in includes/settings/hub.php and each level has a
 * thin wrapper - church/settings/index.php, region/settings/index.php,
 * diocese/settings/index.php - that calls settingsPageContext() and
 * includes it. Existing full-page settings screens show the same rail by
 * including shell-start.php / shell-end.php around their content.
 *
 * What the API allows is decided server-side (App\Support\SettingsAccess);
 * this only checks the page may open and tells the scripts where they are.
 */
require_once __DIR__ . '/../session-manager.php';
require_once __DIR__ . '/../auth-check.php';
require_once __DIR__ . '/../permission-check.php';

const SETTINGS_LEVELS = ['church', 'region', 'diocese'];

/**
 * Check the page's permission and build its context.
 * @param string $level church | region | diocese
 * @param string $active the section the page opens on (overview, profile, ...)
 */
function settingsPageContext(string $level, string $active = 'overview'): array
{
    requirePermission("{$level}.settings.hub.overview.read");

    $role = getCurrentRole() ?? [];
    $requested = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['section'] ?? '')));

    return [
        'level' => $level,
        'hubUrl' => SITE_URL . "/{$level}/settings/",
        'homeUrl' => SITE_URL . "/{$level}/dashboard",
        'active' => $requested !== '' ? $requested : $active,
        'place' => [
            'id' => (int) ($role['territory_id'] ?? 0),
            'name' => $role['territory']['name'] ?? $role['territory_name'] ?? '',
        ],
    ];
}

/** The <head> styles the hub loads (Select2, Leaflet for the map pin, the app styles). */
function settingsPageStyles(bool $withMap = true): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/libs/select2/select2.min.css" />' . "\n";
    echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/data-tables/1.12.1/css/dataTables.bootstrap5.min.css" />' . "\n";
    echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/data-tables/responsive/2.3.0/css/responsive.bootstrap.min.css" />' . "\n";
    if ($withMap) {
        echo '<link rel="stylesheet" href="' . SITE_URL . '/assets/libs/leaflet/leaflet.css" />' . "\n";
    }
    echo '<link href="' . $v('assets/css/styles.min.css') . '" rel="stylesheet" />' . "\n";
}

/** The scripts the hub loads: the template's, the shared helpers, then the settings scripts. */
function settingsPageScripts(bool $withMap = true): void
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
    echo '<script src="' . SITE_URL . '/assets/libs/select2/select2.min.js"></script>' . "\n";
    foreach (['assets/data-tables/1.12.1/js/jquery.dataTables.min.js', 'assets/data-tables/1.12.1/js/dataTables.bootstrap5.min.js', 'assets/data-tables/responsive/2.3.0/js/dataTables.responsive.min.js'] as $src) {
        echo '<script src="' . SITE_URL . "/{$src}\"></script>\n";
    }
    if ($withMap) {
        echo '<script src="' . SITE_URL . '/assets/libs/leaflet/leaflet.js"></script>' . "\n";
    }
    echo '<script src="' . $v('assets/js/pages/demographics/ui-helpers.js') . '"></script>' . "\n";
    settingsRailScripts();
    foreach ([
        'assets/js/pages/settings/fields.js',
        'assets/js/pages/settings/sections/overview.js',
        'assets/js/pages/settings/sections/profile.js',
        'assets/js/pages/settings/sections/service-times.js',
        'assets/js/pages/settings/sections/team.js',
        'assets/js/pages/settings/sections/health.js',
        'assets/js/pages/settings/hub.js',
    ] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}

/**
 * The shell for an existing settings page shown inside the hub (S3): pass
 * it as $settingsShell before including shell-start.php.
 */
function settingsShellFor(string $level, string $active): array
{
    return ['level' => $level, 'active' => $active, 'hubUrl' => SITE_URL . "/{$level}/settings/"];
}

/**
 * Just what the rail needs - for existing settings pages that wrap their
 * content in the shell. They already load jQuery, Bootstrap, constants and
 * ui-helpers (which mustn't load twice), so only the rail's own scripts.
 */
function settingsRailScripts(): void
{
    $v = fn ($path) => SITE_URL . "/{$path}" . assetVersion($path);
    foreach ([
        'assets/js/pages/settings/api.js',
        'assets/js/pages/settings/rail.js',
    ] as $src) {
        echo '<script src="' . $v($src) . '"></script>' . "\n";
    }
}
