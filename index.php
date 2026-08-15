<?php
/**
 * App entry point / redirect gate.
 *
 * includes/auth-check.php already sends unauthenticated users here when they
 * hit a protected page — this used to be a dead end (the raw YNEX demo
 * homepage). Now it actually gates: logged-in users go to their territory's
 * dashboard (same mapping as assets/js/pages/authentication/login.js's
 * redirectToDashboard()), everyone else goes to /login.
 */
require_once __DIR__ . '/includes/session-manager.php';

if (isSessionActive() && !empty($_SESSION['auth_token']) && !empty($_SESSION['user'])) {
    $territoryType = $_SESSION['current_territory_type'] ?? null;

    switch ($territoryType) {
        case 'diocese':
        case 'global':
            $destination = '/diocese/dashboard/';
            break;
        case 'region':
        case 'subregion':
            $destination = '/region/dashboard/';
            break;
        case 'church':
            $destination = '/church/dashboard/';
            break;
        default:
            $destination = '/errors/no-dashboard.php';
    }

    header('Location: ' . SITE_URL . $destination);
    exit;
}

header('Location: ' . SITE_URL . '/login');
exit;
