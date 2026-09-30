<?php
// Moved: budgets were redesigned (docs/specs/budgets-spec.md). Kept so old links still work.
require_once __DIR__ . '/../../../includes/session-manager.php';
$query = isset($_GET['id']) ? '?id=' . (int) $_GET['id'] : '';
header('Location: ' . SITE_URL . '/diocese/budgets/form.php' . $query, true, 301);
exit;
