<?php
/**
 * Reusable page-header/breadcrumb block.
 *
 * Expects, set by the including page before this file is required:
 *   $pageTitle    string  e.g. "Demographics Tracking"
 *   $pageIcon     string  Remix Icon class, e.g. "ri-line-chart-line"
 *   $breadcrumbs  array   ['Label' => 'url or null for the active/current crumb'],
 *                         e.g. ['Home' => SITE_URL . '/church/dashboard', 'Demographics & Growth' => null]
 *
 * Introduced to remove the page-header markup duplicated across every page
 * in this app - no existing partial-include convention for it before this.
 */

$pageTitle = $pageTitle ?? 'Page';
$pageIcon = $pageIcon ?? 'ri-file-list-3-line';
$breadcrumbs = $breadcrumbs ?? [];
?>
<div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
    <h1 class="page-title fw-semibold fs-18 mb-0">
        <i class="<?= htmlspecialchars($pageIcon) ?> me-2"></i><?= htmlspecialchars($pageTitle) ?>
    </h1>
    <div class="ms-md-1 ms-0">
        <nav>
            <ol class="breadcrumb mb-0">
                <?php $i = 0; $count = count($breadcrumbs); foreach ($breadcrumbs as $label => $url): $i++; ?>
                <?php if ($url && $i < $count): ?>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($url) ?>"><?= $i === 1 ? '<i class="ri-home-4-line"></i> ' : '' ?><?= htmlspecialchars($label) ?></a></li>
                <?php elseif ($i < $count): ?>
                <li class="breadcrumb-item"><a href="javascript:void(0);"><?= htmlspecialchars($label) ?></a></li>
                <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($label) ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
