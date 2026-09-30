<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$canWrite = hasPermission('attendancemanagement.ministryattendance.create')
    || hasPermission('attendancemanagement.ministryattendance.update');
$canDelete = hasPermission('attendancemanagement.ministryattendance.delete');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Ministry Gatherings';
$pageIcon = 'ri-group-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Ministry Gatherings' => null,
];
$gatheringPage = [
    'slug' => 'ministry_gathering',
    'noun' => 'Ministry',
    'pluralNoun' => 'ministries',
    'plural' => 'gatherings',
    'categoryLabel' => 'Ministry gathering',
    'icon' => 'ri-group-line',
];
$cardsTitle = 'Ministries';
$toolbarText = 'Fellowships, prayer meetings and other ministry gatherings at ' . $userTerritoryName;

include __DIR__ . '/../../includes/attendance-gatherings-page.php';
