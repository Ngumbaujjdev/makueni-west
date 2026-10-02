<?php
/**
 * Opens the Settings hub's two columns: the section rail on the left, the
 * page's own content on the right (docs/specs/settings-spec.md). Include
 * it after page-header.php with $settingsShell = ['level' => ..., 'active'
 * => section key, 'hubUrl' => ...], and close with shell-end.php.
 * assets/js/pages/settings/rail.js fills the rail.
 */
$settingsShell = $settingsShell ?? [];
?>
<script>window.SETTINGS_SHELL = <?= json_encode([
    'level' => $settingsShell['level'] ?? null,
    'active' => $settingsShell['active'] ?? 'overview',
    'hubUrl' => $settingsShell['hubUrl'] ?? null,
]) ?>; window.SECONDARY_NAV_OFF = true;</script>
<div class="settings-shell">
    <nav class="settings-rail" id="settingsRail" aria-label="Settings sections">
        <div class="settings-rail-skel" aria-hidden="true">
            <?php for ($i = 0; $i < 5; $i++): ?>
                <span class="settings-rail-skel-item"></span>
            <?php endfor; ?>
        </div>
    </nav>
    <section class="settings-panel" id="settingsPanelColumn">
