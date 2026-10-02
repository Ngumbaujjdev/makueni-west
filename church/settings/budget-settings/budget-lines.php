<?php
// Old page - Budget Settings is now one page for lines and deductions (includes/budget/settings.php).
require_once __DIR__ . '/../../../includes/session-manager.php';
header('Location: ' . SITE_URL . '/church/settings/budget-settings/index.php');
exit;
