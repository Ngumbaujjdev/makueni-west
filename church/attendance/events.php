<?php
require_once __DIR__ . '/../../includes/session-manager.php';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once __DIR__ . '/../../includes/permission-check.php';

requirePermission('attendancemanagement.overview.read');

$canWrite = hasPermission('attendancemanagement.specialeventsattendance.create')
    || hasPermission('attendancemanagement.specialeventsattendance.update');

$user = getAuthUser();
$currentRole = getCurrentRole();

$userTerritoryId = $currentRole['territory_id'] ?? null;
$userTerritoryName = $currentRole['territory']['name'] ?? 'Your Church';

$pageTitle = 'Special Events';
$pageIcon = 'ri-star-line';
$breadcrumbs = [
    'Home' => SITE_URL . '/church/dashboard',
    'Attendance' => SITE_URL . '/church/attendance',
    'Special Events' => null,
];
$gatheringPage = [
    'slug' => 'special_event',
    'noun' => 'Event',
    'pluralNoun' => 'events',
    'plural' => 'events',
    'categoryLabel' => 'Special event',
    'icon' => 'ri-star-line',
];
$cardsTitle = 'Events';
$toolbarText = 'Crusades, baptism services, dedications and other special events at ' . $userTerritoryName;

include __DIR__ . '/../../includes/attendance-gatherings-page.php';
