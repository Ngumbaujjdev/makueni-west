<?php
/**
 * Update the signed-in person's display details in the PHP session
 *
 * The header and sidebar are drawn from $_SESSION['user'], which sync-session
 * fills at sign-in. When someone edits their name or photo on My Profile,
 * this keeps those in step without signing in again. Only a fixed list of
 * display fields is accepted - never ids, roles or permissions.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['is_authenticated']) || !isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data received.']);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
foreach (['firstname', 'lastname', 'email', 'phone', 'position'] as $key) {
    if (array_key_exists($key, $data) && (is_string($data[$key]) || $data[$key] === null)) {
        $_SESSION['user'][$key] = $data[$key] === null ? null : mb_substr($data[$key], 0, 255);
    }
}
if (array_key_exists('photo_url', $data)) {
    $url = $data['photo_url'];
    // Only the API's own link to this person's photo.
    if ($url === null || (is_string($url) && preg_match('#^https?://[\w.:-]+/api/users/' . $userId . '/photo\?v=[\w-]+$#', $url))) {
        $_SESSION['user']['photo_url'] = $url;
    }
}
$_SESSION['user']['full_name'] = trim(($_SESSION['user']['firstname'] ?? '') . ' ' . ($_SESSION['user']['lastname'] ?? ''));
$_SESSION['user_name'] = $_SESSION['user']['full_name'];
$_SESSION['user_email'] = $_SESSION['user']['email'] ?? null;

echo json_encode(['success' => true]);
