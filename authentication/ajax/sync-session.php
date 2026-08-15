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

    // Extract current territorial assignment details for quick access.
    // Two different shapes reach here depending on caller: AuthController's
    // switchRole() sends a nested { territory: { id, name, territory_type }, role: {...} },
    // but getDefaultRole() (used by login/user-info, and what login.js's
    // current_role actually is right after a fresh login) sends a FLAT
    // { territory_type, territory_name, role_name, ... } with no nested
    // 'territory' key at all. Reading only the nested shape silently left
    // current_territory_type null on every fresh login until the next
    // switch-role call corrected it - which is what sent users straight to
    // errors/no-dashboard.php depending on timing. Handle both.
    if (!empty($data['current_role'])) {
        $currentRole = $data['current_role'];
        $territory = $currentRole['territory'] ?? null;

        $_SESSION['current_territory_id'] = $territory['id'] ?? $currentRole['territory_id'] ?? null;
        $_SESSION['current_territory_name'] = $territory['name'] ?? $currentRole['territory_name'] ?? null;
        $_SESSION['current_territory_type'] = $territory['territory_type'] ?? $currentRole['territory_type'] ?? $currentRole['territory_scope'] ?? null;
        $_SESSION['current_role_name'] = $currentRole['role']['name'] ?? $currentRole['role_name'] ?? null;
        $_SESSION['current_assignment_id'] = $currentRole['assignment_id'] ?? null;
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