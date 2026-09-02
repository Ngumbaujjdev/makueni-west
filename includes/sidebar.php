<?php
/**
 * Dynamic Sidebar - Universal for all territory levels
 * - Supports module groups with category headers
 * - Loads modules from localStorage cache
 * - Uses Font Awesome icons
 * - Click handlers for expand/collapse
 * - No .php extensions (htaccess handles it)
 */

// Get user data from session
$authToken = $_SESSION['auth_token'] ?? null;
$currentUser = $_SESSION['user'] ?? [];
$currentRole = $_SESSION['current_role'] ?? [];
$territoryType = $currentRole['territory_type'] ?? 'church';

// Base URL configuration
$baseUrl = '/makueni-west';

/**
 * Get territory-specific dashboard URL
 */
function getDashboardUrl($territoryType, $baseUrl) {
    $dashboardMap = [
        'global' => '/diocese/dashboard',
        'diocese' => '/diocese/dashboard',
        'region' => '/region/oversight',
        'subregion' => '/region/oversight',
        'church' => '/church/member-management'
    ];
    return $baseUrl . ($dashboardMap[$territoryType] ?? '/diocese/dashboard');
}

$dashboardUrl = getDashboardUrl($territoryType, $baseUrl);
$userName = $currentUser['firstname'] ?? 'User';
$userRole = $currentRole['role_name'] ?? 'Unknown Role';
?>

<!-- Font Awesome CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- START: Sidebar -->
<aside class="app-sidebar sticky" id="sidebar">
    <!-- START: Sidebar Header -->
    <div class="main-sidebar-header">
        <a href="<?= $dashboardUrl ?>" class="header-logo">
            <img src="<?= $baseUrl ?>/assets/images/brand-logos/desktop-logo.png" alt="logo" class="desktop-logo" />
            <img src="<?= $baseUrl ?>/assets/images/brand-logos/toggle-logo.png" alt="logo" class="toggle-logo" />
            <img src="<?= $baseUrl ?>/assets/images/brand-logos/desktop-dark.png" alt="logo" class="desktop-dark" />
            <img src="<?= $baseUrl ?>/assets/images/brand-logos/toggle-dark.png" alt="logo" class="toggle-dark" />
        </a>
    </div>
    <!-- END: Sidebar Header -->

    <!-- START: Main Sidebar -->
    <div class="main-sidebar" id="sidebar-scroll">
        <nav class="main-menu-container nav nav-pills flex-column sub-open">
            <div class="slide-left" id="slide-left">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24">
                    <path d="M13.293 6.293 7.586 12l5.707 5.707 1.414-1.414L10.414 12l4.293-4.293z"></path>
                </svg>
            </div>

            <ul class="main-menu" id="main-sidebar-menu">
                <!-- Loading Placeholder -->
                <li class="slide" id="modules-loading">
                    <a href="javascript:void(0);" class="side-menu__item">
                        <i class="fa fa-spinner fa-spin side-menu__icon"></i>
                        <span class="side-menu__label">Loading modules...</span>
                    </a>
                </li>

                <!-- Dynamic Modules Container -->
                <div id="dynamic-modules-container"></div>

                <!-- Account Section -->
                <li class="slide__category">
                    <span class="category-name">Account</span>
                </li>

                <li class="slide">
                    <a href="<?= $baseUrl ?>/profile" class="side-menu__item">
                        <i class="fa fa-user side-menu__icon"></i>
                        <span class="side-menu__label">My Profile</span>
                    </a>
                </li>

                <li class="slide">
                    <a href="<?= $baseUrl ?>/support" class="side-menu__item">
                        <i class="fa fa-headset side-menu__icon"></i>
                        <span class="side-menu__label">Help & Support</span>
                    </a>
                </li>

                <li class="slide">
                    <a href="javascript:void(0);" onclick="handleLogout()" class="side-menu__item">
                        <i class="fa fa-right-from-bracket side-menu__icon"></i>
                        <span class="side-menu__label">Logout</span>
                    </a>
                </li>
            </ul>

            <div class="slide-right" id="slide-right">
                <svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24" height="24" viewBox="0 0 24 24">
                    <path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z"></path>
                </svg>
            </div>
        </nav>
    </div>
    <!-- END: Main Sidebar -->
</aside>
<!-- END: Sidebar -->

<!-- auth-helpers.js defines window.autoRefreshAssignments/forceRefreshModules,
     which the DOMContentLoaded listener below calls to pick up newly granted
     modules without requiring a fresh login - most pages that include this
     sidebar don't separately load auth-helpers.js, so it's loaded here to
     guarantee it's always available. Safe to load twice on pages that do
     load it themselves - no top-level const/let, plain function
     declarations only, so redeclaring is a no-op, not a SyntaxError (unlike
     config/constants.js's `const Constants = {...}`, a real bug fixed
     earlier). -->
<script src="<?= $baseUrl ?>/assets/js/utils/auth-helpers.js"></script>

<script>
/**
 * Sidebar Module Loader with Groups Support
 */
(function() {
    'use strict';

    // Icon mapping for modules (Font Awesome)
    const ICON_MAP = {
        'dashboard': 'fa-gauge-high',
        'users-chart': 'fa-chart-line',
        'chart-line': 'fa-chart-area',
        'church-building': 'fa-church',
        'church-detail': 'fa-building-columns',
        'shield-check': 'fa-shield-halved',
        'hand-holding-dollar': 'fa-hand-holding-dollar',
        'calculator': 'fa-calculator',
        'share-alt': 'fa-share-nodes',
        'coins': 'fa-coins',
        'receipt': 'fa-receipt',
        'file-invoice-dollar': 'fa-file-invoice-dollar',
        'graduation-cap': 'fa-graduation-cap',
        'calendar-star': 'fa-calendar-days',
        'calendar-alt': 'fa-calendar',
        'megaphone': 'fa-bullhorn',
        'cogs': 'fa-gears',
        'package': 'fa-box',
        'calendar': 'fa-calendar',
        'scale': 'fa-scale-balanced',
        'file-bar-chart': 'fa-chart-bar',
        'folder': 'fa-folder',
        'default': 'fa-circle'
    };

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            loadSidebarModules();
            
            // ✅ NEW: Trigger auto-refresh to check for permission/module updates
            // This will refresh if 30+ seconds have passed since last refresh
            if (typeof window.autoRefreshAssignments === 'function') {
                window.autoRefreshAssignments().then(refreshed => {
                    if (refreshed) {
                        console.log('🔄 Auto-refresh triggered, reloading sidebar modules...');
                        loadSidebarModules(); // Reload sidebar with fresh modules
                    }
                });
            }
        }, 200);
    });

    /**
     * Get Font Awesome icon class
     */
    function getIconClass(iconName) {
        if (!iconName) return 'fa ' + (ICON_MAP['default'] || 'fa-circle');
        if (iconName.startsWith('fa-')) return 'fa ' + iconName;

        // Handle remixicon format (ri-xxx-line -> xxx)
        if (iconName.startsWith('ri-')) {
            const cleanIcon = iconName.replace('ri-', '').replace('-line', '').replace('-fill', '');
            const mappedIcon = ICON_MAP[cleanIcon] || ICON_MAP['folder'];
            return 'fa ' + mappedIcon;
        }

        const mappedIcon = ICON_MAP[iconName] || ICON_MAP['folder'];
        return 'fa ' + mappedIcon;
    }

    /**
     * Load modules from localStorage
     */
    function loadSidebarModules() {
        try {
            const cachedModules = getCachedModules();

            // Check for grouped structure (new) or flat structure (old)
            const hasGroups = cachedModules && cachedModules.module_groups;
            const hasModules = cachedModules && cachedModules.modules;

            if (!hasGroups && !hasModules) {
                showNoModulesError();
                return;
            }

            console.log('✅ Loading sidebar modules:', {
                total: cachedModules.total_modules || 0,
                territory: cachedModules.territory_scope,
                grouped: hasGroups
            });

            // Render based on structure
            if (hasGroups) {
                renderModuleGroups(cachedModules.module_groups);
            } else {
                renderModules(cachedModules.modules);
            }

            // Remove loading indicator
            const loadingEl = document.getElementById('modules-loading');
            if (loadingEl) loadingEl.remove();

            // Setup click handlers
            setupClickHandlers();

            // Highlight active page
            highlightActivePage();

        } catch (error) {
            console.error('Error loading sidebar modules:', error);
            showModulesError('Error loading navigation');
        }
    }

    /**
     * Get cached modules
     */
    function getCachedModules() {
        try {
            const cachedData = localStorage.getItem('mwd_current_modules');
            if (!cachedData) {
                console.warn('No cached modules found');
                return null;
            }
            return JSON.parse(cachedData);
        } catch (error) {
            console.error('Error parsing cached modules:', error);
            return null;
        }
    }

    /**
     * ✅ NEW: Render module groups with category headers
     */
    function renderModuleGroups(moduleGroups) {
        const container = document.getElementById('dynamic-modules-container');
        if (!container) {
            console.error('Container not found');
            return;
        }

        let html = '';

        // Loop through each group
        moduleGroups.forEach((group, groupIndex) => {
            // Add group header
            html += `
            <li class="slide__category">
                <span class="category-name">
                    <i class="${getIconClass(group.icon)} me-2"></i>
                    ${escapeHtml(group.name)}
                </span>
            </li>`;

            // Render modules in this group
            // Convert modules object to array if needed
            const modulesArray = Array.isArray(group.modules)
                ? group.modules
                : Object.values(group.modules);

            modulesArray.forEach((module, moduleIndex) => {
                const hasSubmodules = module.submodules && module.submodules.length > 0;
                const iconClass = getIconClass(module.icon);

                if (hasSubmodules) {
                    html += `
                    <li class="slide has-sub" data-module-id="${module.id}">
                        <a href="javascript:void(0);" class="side-menu__item" data-toggle-submenu>
                            <i class="${iconClass} side-menu__icon"></i>
                            <span class="side-menu__label">${escapeHtml(module.name)}</span>
                            <i class="fe fe-chevron-right side-menu__angle"></i>
                        </a>
                        <ul class="slide-menu child1">
                            <li class="slide side-menu__label1">
                                <a href="javascript:void(0)">${escapeHtml(module.name)}</a>
                            </li>`;

                    // Render submodules
                    html += renderSubmodules(module.submodules);

                    html += `
                        </ul>
                    </li>`;
                } else {
                    html += `
                    <li class="slide">
                        <a href="javascript:void(0);" class="side-menu__item">
                            <i class="${iconClass} side-menu__icon"></i>
                            <span class="side-menu__label">${escapeHtml(module.name)}</span>
                        </a>
                    </li>`;
                }
            });
        });

        container.innerHTML = html;
        console.log('✅ Sidebar rendered with groups');
    }

    /**
     * Render modules HTML (legacy support)
     */
    function renderModules(modules) {
        const container = document.getElementById('dynamic-modules-container');
        if (!container) {
            console.error('Container not found');
            return;
        }

        let html = '';

        modules.forEach((module, index) => {
            const hasSubmodules = module.submodules && module.submodules.length > 0;
            const iconClass = getIconClass(module.icon);
            const isFirst = index === 0;

            if (hasSubmodules) {
                html += `
                <li class="slide has-sub ${isFirst ? 'open' : ''}" data-module-id="${module.id}">
                    <a href="javascript:void(0);" class="side-menu__item" data-toggle-submenu>
                        <i class="${iconClass} side-menu__icon"></i>
                        <span class="side-menu__label">${escapeHtml(module.name)}</span>
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child1" style="${isFirst ? 'display: block;' : 'display: none;'}">
                        <li class="slide side-menu__label1">
                            <a href="javascript:void(0)">${escapeHtml(module.name)}</a>
                        </li>`;

                html += renderSubmodules(module.submodules);

                html += `
                    </ul>
                </li>`;
            } else {
                html += `
                <li class="slide">
                    <a href="javascript:void(0);" class="side-menu__item">
                        <i class="${iconClass} side-menu__icon"></i>
                        <span class="side-menu__label">${escapeHtml(module.name)}</span>
                    </a>
                </li>`;
            }
        });

        container.innerHTML = html;
        console.log('✅ Sidebar rendered successfully');
    }

    /**
     * ✅ NEW: Helper to render submodules (reusable)
     */
    function renderSubmodules(submodules) {
        let html = '';

        submodules.forEach(submodule => {
            const hasSubSubmodules = submodule.sub_submodules && submodule.sub_submodules.length > 0;
            const submodulePath = formatPath(submodule.path);

            if (hasSubSubmodules) {
                html += `
                <li class="slide has-sub">
                    <a href="javascript:void(0);" class="side-menu__item submodule-item" data-toggle-submenu>
                        ${escapeHtml(submodule.title)}
                        <i class="fe fe-chevron-right side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu child2" style="display: none;">`;

                submodule.sub_submodules.forEach(subSubmodule => {
                    const subSubmodulePath = formatPath(subSubmodule.path);
                    html += `
                        <li class="slide">
                            <a href="${escapeHtml(subSubmodulePath || '#')}" class="side-menu__item sub-submodule-item">
                                ${escapeHtml(subSubmodule.title)}
                            </a>
                        </li>`;
                });

                html += `
                    </ul>
                </li>`;
            } else {
                html += `
                <li class="slide">
                    <a href="${escapeHtml(submodulePath || '#')}" class="side-menu__item submodule-item">
                        ${escapeHtml(submodule.title)}
                    </a>
                </li>`;
            }
        });

        return html;
    }

    /**
     * Remove .php extension and prepend base URL
     */
    function formatPath(path) {
        if (!path) return path;
        let cleanPath = path.replace(/\.php$/, '');
        // Safety net: a path stored without a leading slash used to
        // concatenate straight onto baseUrl with no separator (e.g.
        // "/makueni-westchurch/attendance/services") - the source data is
        // fixed (see FixDemographicsSubmodulePathSlashesSeeder), but this
        // keeps any future same mistake from breaking the sidebar again.
        if (!cleanPath.startsWith('/')) {
            cleanPath = '/' + cleanPath;
        }
        if (!cleanPath.startsWith('<?= $baseUrl ?>')) {
            cleanPath = '<?= $baseUrl ?>' + cleanPath;
        }
        return cleanPath;
    }

    // Flyout is a desktop-only pattern (hover doesn't exist on touch, and
    // the fixed-position math below is built around the desktop sidebar).
    // Below this, the same 992px breakpoint the rest of the app already
    // uses for its own layout switches (see styles.css's
    // "@media (min-width: 992px)" blocks), modules fall back to the
    // original inline click-to-expand accordion instead.
    function isDesktopFlyout() {
        return window.matchMedia('(min-width: 992px)').matches;
    }

    /**
     * Position a flyout panel flush against the sidebar's actual right edge,
     * vertically aligned with the hovered/clicked trigger's own row rather
     * than always pinned to the top - clamped so it never renders above the
     * header/tab bar, and never runs past the bottom of the viewport for a
     * trigger low in a long sidebar. Computed at open-time (not hardcoded)
     * so it stays correct if the sidebar's own collapse toggle changes its
     * width.
     */
    function positionFlyout(panel, trigger) {
        const sidebarEl = document.getElementById('sidebar');
        if (!sidebarEl) return;
        const sidebarRect = sidebarEl.getBoundingClientRect();

        let minTop = sidebarRect.top;
        const headerEl = document.querySelector('.app-header');
        if (headerEl) minTop = Math.max(minTop, headerEl.getBoundingClientRect().bottom);
        const secondaryNav = document.getElementById('secondary-nav-bar');
        if (secondaryNav && getComputedStyle(secondaryNav).display !== 'none') {
            minTop = Math.max(minTop, secondaryNav.getBoundingClientRect().bottom);
        }

        panel.style.left = sidebarRect.right + 'px';
        // Auto-size to content instead of forcing full remaining viewport
        // height - a short submodule list should read as a compact,
        // proportioned card, not stretch into a mostly-empty tall box.
        // max-height (with a small bottom margin) still caps it so a long
        // list scrolls within the available space instead of overflowing
        // past the viewport.
        panel.style.height = 'auto';
        panel.style.maxHeight = (window.innerHeight - minTop - 12) + 'px';

        const triggerRect = trigger ? trigger.getBoundingClientRect() : { top: minTop };
        let top = Math.max(minTop, triggerRect.top);
        // Panel is already visible (display set by the caller) by this
        // point, so its real rendered height is available to clamp against.
        const panelHeight = panel.getBoundingClientRect().height;
        const maxTop = Math.max(minTop, window.innerHeight - panelHeight - 12);
        top = Math.min(top, maxTop);

        panel.style.top = top + 'px';
    }

    function closeAllFlyouts() {
        document.querySelectorAll('#dynamic-modules-container > li[data-module-id] > .slide-menu')
            .forEach(panel => panel.classList.remove('flyout-active'));
    }

    function openModuleFlyout(trigger) {
        const panel = trigger.nextElementSibling;
        if (!panel || !panel.classList.contains('slide-menu')) return;
        if (panel.classList.contains('flyout-active')) return;

        closeAllFlyouts();
        // Make it visible first so positionFlyout() can read its real
        // rendered height to clamp against.
        panel.classList.add('flyout-active');
        positionFlyout(panel, trigger);
    }

    /**
     * Same accordion toggle child2/child3 rows already use - reused here
     * for module-level (child1) triggers on narrow screens, where the
     * flyout is replaced by a plain inline expand/collapse.
     */
    function toggleInlineAccordion(trigger) {
        const parentLi = trigger.closest('.slide');
        const submenu = parentLi.querySelector('.slide-menu');
        if (!submenu) return;
        parentLi.classList.toggle('open');
        submenu.style.display = parentLi.classList.contains('open') ? 'block' : 'none';
    }

    /**
     * Setup click handlers
     */
    function setupClickHandlers() {
        let flyoutCloseTimer = null;
        const cancelFlyoutClose = () => clearTimeout(flyoutCloseTimer);
        const scheduleFlyoutClose = () => {
            clearTimeout(flyoutCloseTimer);
            flyoutCloseTimer = setTimeout(closeAllFlyouts, 250);
        };

        // Module-level triggers open a flyout panel beside the sidebar on
        // desktop, or expand inline (like the original accordion) below
        // the 992px breakpoint (only the top-level triggers - nested
        // submodule triggers are handled separately below).
        document.querySelectorAll('#dynamic-modules-container > li[data-module-id] > [data-toggle-submenu]')
            .forEach(trigger => {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (isDesktopFlyout()) {
                        openModuleFlyout(trigger);
                    } else {
                        toggleInlineAccordion(trigger);
                    }
                });
                trigger.addEventListener('mouseenter', function() {
                    if (!isDesktopFlyout()) return; // no hover-open on touch/narrow screens
                    cancelFlyoutClose();
                    openModuleFlyout(trigger);
                });
            });

        // Sidebar and open flyout panels are visually contiguous - keep the
        // panel open while the pointer is over either, close shortly after
        // it leaves both (so moving sidebar -> panel doesn't flicker-close).
        const sidebarEl = document.getElementById('sidebar');
        if (sidebarEl) {
            sidebarEl.addEventListener('mouseleave', scheduleFlyoutClose);
            sidebarEl.addEventListener('mouseenter', cancelFlyoutClose);
        }
        document.querySelectorAll('#dynamic-modules-container > li[data-module-id] > .slide-menu')
            .forEach(panel => {
                panel.addEventListener('mouseleave', scheduleFlyoutClose);
                panel.addEventListener('mouseenter', cancelFlyoutClose);
            });

        // Click outside the sidebar/flyout closes whatever's open
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.app-sidebar') && !e.target.closest('.slide-menu.child1')) {
                closeAllFlyouts();
            }
        });

        // Reposition the open panel on resize (position is computed, not CSS-fixed)
        window.addEventListener('resize', function() {
            const activePanel = document.querySelector(
                '#dynamic-modules-container > li[data-module-id] > .slide-menu.flyout-active'
            );
            if (!activePanel) return;
            if (!isDesktopFlyout()) {
                // Crossed below the breakpoint with a flyout open - drop
                // out of flyout mode entirely rather than leaving a
                // fixed-position panel stranded.
                closeAllFlyouts();
                return;
            }
            positionFlyout(activePanel, activePanel.previousElementSibling);
        });

        // Sub-submodule rows inside an already-open flyout keep the
        // original inline expand/collapse accordion (unrelated to the
        // flyout open/close mechanic above).
        document.querySelectorAll('.slide-menu .slide.has-sub > [data-toggle-submenu]').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const parentLi = this.closest('.slide');
                const submenu = parentLi.querySelector('.slide-menu');
                if (!submenu) return;

                parentLi.classList.toggle('open');
                submenu.style.display = parentLi.classList.contains('open') ? 'block' : 'none';
            });
        });

        console.log('✅ Click handlers setup');
    }

    /**
     * Highlight active page
     */
    function highlightActivePage() {
        try {
            const currentPath = window.location.pathname;
            const links = document.querySelectorAll('.side-menu__item[href]');

            links.forEach(link => {
                const href = link.getAttribute('href');
                if (href && href !== 'javascript:void(0);' && href !== '#') {
                    const normalizedHref = href.replace(/\.php$/, '');
                    const normalizedCurrent = currentPath.replace(/\.php$/, '');

                    // One-directional only: matching the other way too
                    // (href contains current) falsely matches every
                    // sibling submodule at once when the current path is a
                    // short prefix shared by all of them (e.g. a module's
                    // own bare landing page).
                    if (normalizedCurrent.includes(normalizedHref)) {
                        link.classList.add('active');

                        // Expand any nested (child2+) sub-submodule accordion
                        // ancestor inline, same as before - but stop at the
                        // top-level flyout panel (.child1) rather than
                        // forcing it open on page load.
                        let parent = link.closest('.slide-menu');
                        while (parent && !parent.classList.contains('child1')) {
                            parent.style.display = 'block';
                            const parentSlide = parent.closest('.slide');
                            if (parentSlide) parentSlide.classList.add('open');
                            parent = parent.parentElement.closest('.slide-menu');
                        }

                        // Mark the owning top-level module as "current" so
                        // its icon/label reads as active without forcing
                        // the flyout open.
                        const moduleLi = link.closest('li[data-module-id]');
                        if (moduleLi) {
                            const moduleTrigger = moduleLi.querySelector(':scope > .side-menu__item');
                            if (moduleTrigger) moduleTrigger.classList.add('active');
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Error highlighting active page:', error);
        }
    }

    function showNoModulesError() {
        const loadingEl = document.getElementById('modules-loading');
        if (loadingEl) {
            loadingEl.innerHTML = `
                <a href="javascript:void(0);" class="side-menu__item text-warning">
                    <i class="fa fa-triangle-exclamation side-menu__icon"></i>
                    <span class="side-menu__label">No modules available</span>
                </a>`;
        }
    }

    function showModulesError(message) {
        const loadingEl = document.getElementById('modules-loading');
        if (loadingEl) {
            loadingEl.innerHTML = `
                <a href="javascript:void(0);" class="side-menu__item text-danger">
                    <i class="fa fa-circle-xmark side-menu__icon"></i>
                    <span class="side-menu__label">${message}</span>
                </a>`;
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    window.refreshSidebarModules = function() {
        if (typeof forceRefreshModules === 'function') {
            forceRefreshModules().then(success => {
                if (success) loadSidebarModules();
            });
        } else {
            loadSidebarModules();
        }
    };

    window.handleLogout = function() {
        if (confirm('Are you sure you want to logout?')) {
            if (typeof clearAuthData === 'function') clearAuthData();
            window.location.href = '<?= $baseUrl ?>/authentication/logout';
        }
    };

})();
</script>


