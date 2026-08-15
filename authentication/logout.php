<?php
/**
 * Logout Handler
 *
 * Handles user logout by:
 * 1. Calling backend API to invalidate token
 * 2. Destroying PHP session
 * 3. Clearing cookies
 * 4. Redirecting to login page
 */
require_once __DIR__ . '/../includes/session-manager.php';

// Get auth token before destroying session
$authToken = $_SESSION['auth_token'] ?? null;

// Destroy PHP session
session_unset();
session_destroy();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// If we have a token, try to invalidate it on backend (optional - doesn't block logout)
if ($authToken) {
    try {
        $apiUrl = 'http://127.0.0.1:8004/api/auth/logout';
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
        
        curl_exec($ch);
        curl_close($ch);
        
        // We don't check response - logout happens regardless
    } catch (Exception $e) {
        // Silent fail - user is logged out locally anyway
        error_log('Logout API call failed: ' . $e->getMessage());
    }
}

// Redirect to login page with logout message
header('Location: ' . SITE_URL . '/login?logged_out=1');
exit;