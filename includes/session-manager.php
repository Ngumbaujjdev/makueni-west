<?php
/**
 * Session Manager
 *
 * Handles session initialization, validation, and management
 * Include this file at the top of every page that needs session handling
 */

// Site base URL — single source of truth for the frontend's own root-relative
// path, mirroring assets/js/config/app.js's FRONTEND_BASE_URL. If this app ever
// moves to a different subfolder or a domain root, change it here only.
if (!defined('SITE_URL')) {
    define('SITE_URL', '/makueni-west');
}

// Cache-busting for static assets — appends the file's last-modified time as
// a `?v=` query string so a plain browser refresh always picks up the latest
// JS/CSS instead of silently serving a stale disk-cached copy (Apache here
// sends no Cache-Control header, so browsers fall back to their own
// heuristic caching, which can hide edits across a plain refresh). Use this
// for every <script src>/<link href> under assets/ instead of a bare
// SITE_URL concatenation.
if (!function_exists('asset_url')) {
    function asset_url(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $fullPath = dirname(__DIR__) . '/' . $relativePath;
        $version = file_exists($fullPath) ? filemtime($fullPath) : time();
        return SITE_URL . '/' . $relativePath . '?v=' . $version;
    }
}

// Backend API base URL — PHP-side mirror of assets/js/config/app.js's
// AppConfig.API_BASE_URL. The handful of PHP files that call the backend
// directly (not via the frontend JS) should use this instead of hardcoding
// the URL — that's exactly what broke when the backend moved from port 8000
// to 8004 and several files needed hand-fixing instead of a one-line change.
if (!defined('BACKEND_API_URL')) {
    define('BACKEND_API_URL', 'http://127.0.0.1:8004/api');
}

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 in production with HTTPS
ini_set('session.cookie_samesite', 'Lax');

// Session timeout (8 hours = 28800 seconds)
ini_set('session.gc_maxlifetime', 28800);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if session is active and valid
 */
function isSessionActive() {
    return isset($_SESSION['is_authenticated']) && $_SESSION['is_authenticated'] === true;
}

/**
 * Check if session has expired
 */
function isSessionExpired() {
    $timeout = 28800; // 8 hours in seconds
    
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        
        if ($elapsed > $timeout) {
            return true;
        }
    }
    
    return false;
}

/**
 * Update last activity timestamp
 */
function updateLastActivity() {
    $_SESSION['last_activity'] = time();
}

/**
 * Get authenticated user data
 */
function getAuthUser() {
    if (!isSessionActive()) {
        return null;
    }
    
    return $_SESSION['user'] ?? null;
}

/**
 * Get user permissions
 */
function getUserPermissions() {
    if (!isSessionActive()) {
        return [];
    }
    
    return $_SESSION['permissions'] ?? [];
}

/**
 * Get current territorial role
 */
function getCurrentRole() {
    if (!isSessionActive()) {
        return null;
    }
    
    return $_SESSION['current_role'] ?? null;
}

/**
 * Get all territorial roles
 */
function getTerritorialRoles() {
    if (!isSessionActive()) {
        return [];
    }
    
    return $_SESSION['territorial_roles'] ?? [];
}

/**
 * Destroy session and clean up
 */
function destroyAuthSession() {
    // Unset all session variables
    $_SESSION = array();
    
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
}

/**
 * Check session validity and update activity
 */
if (isSessionActive()) {
    if (isSessionExpired()) {
        destroyAuthSession();
        
        // Redirect to login with expiry message
        header('Location: /makueni-west/index?session_expired=1');
        exit;
    } else {
        updateLastActivity();
    }
}