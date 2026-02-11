<?php
/**
 * Sync Session Handler
 * 
 * Receives authentication data from JavaScript after successful login
 * and stores it in PHP $_SESSION for server-side access control
 * 
 * This bridges the gap between JavaScript (localStorage) and PHP (session)
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Validate input
    if (!$data) {
        throw new Exception('Invalid JSON data received');
    }

    // Validate required fields
    $requiredFields = ['token', 'user', 'permissions', 'territorial_roles', 'current_role'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Store authentication data in session
    $_SESSION['auth_token'] = $data['token'];
    $_SESSION['user'] = $data['user'];
    $_SESSION['permissions'] = $data['permissions'];
    $_SESSION['territorial_roles'] = $data['territorial_roles'];
    $_SESSION['current_role'] = $data['current_role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    $_SESSION['is_authenticated'] = true;

    // Extract commonly used user data for easy access
    $_SESSION['user_id'] = $data['user']['id'] ?? null;
    $_SESSION['user_name'] = $data['user']['full_name'] ?? $data['user']['firstname'] . ' ' . $data['user']['lastname'];
    $_SESSION['user_email'] = $data['user']['email'] ?? null;
    $_SESSION['user_role_id'] = $data['user']['role_id'] ?? null;
    $_SESSION['employee_code'] = $data['user']['employee_code'] ?? null;

    // Extract current territorial assignment details for quick access
    if (!empty($data['current_role'])) {
        $_SESSION['current_territory_id'] = $data['current_role']['territory']['id'] ?? null;
        $_SESSION['current_territory_name'] = $data['current_role']['territory']['name'] ?? null;
        $_SESSION['current_territory_type'] = $data['current_role']['territory']['territory_type'] ?? null;
        $_SESSION['current_role_name'] = $data['current_role']['role']['name'] ?? null;
        $_SESSION['current_assignment_id'] = $data['current_role']['assignment_id'] ?? null;
    }

    // Store password warning if present
    if (isset($data['password_warning'])) {
        $_SESSION['password_warning'] = $data['password_warning'];
    }

    // Log successful session sync (optional - for debugging)
    error_log(sprintf(
        '[Session Sync] User %s (ID: %s) logged in successfully at %s',
        $_SESSION['user_name'],
        $_SESSION['user_id'],
        date('Y-m-d H:i:s')
    ));

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Session synchronized successfully',
        'data' => [
            'user_id' => $_SESSION['user_id'],
            'user_name' => $_SESSION['user_name'],
            'session_id' => session_id(),
            'synced_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Log error
    error_log('[Session Sync Error] ' . $e->getMessage());

    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to sync session: ' . $e->getMessage()
    ]);
}