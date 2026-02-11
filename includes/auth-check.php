<?php
/**
 * Authentication Check
 * 
 * Include this file at the top of protected pages to ensure user is logged in
 * Redirects to login page if user is not authenticated
 */

// Ensure session manager is loaded
if (!function_exists('isSessionActive')) {
    require_once __DIR__ . '/session-manager.php';
}

// Check if user is authenticated
if (!isSessionActive()) {
    // Store the requested URL for redirect after login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    
    // Redirect to login page
    header('Location: /makueni-west/index.php');
    exit;
}

// Validate auth token exists
if (empty($_SESSION['auth_token'])) {
    destroyAuthSession();
    header('Location: /makueni-west/index.php');
    exit;
}

// Validate user data exists
if (empty($_SESSION['user']) || empty($_SESSION['user_id'])) {
    destroyAuthSession();
    header('Location: /makueni-west/index.php');
    exit;
}