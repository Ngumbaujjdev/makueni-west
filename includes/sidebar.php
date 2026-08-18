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

            // Auto-expand first module
            setTimeout(() => autoExpandFirstModule(), 100);

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
        let firstModuleRendered = false;

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
                const isFirst = !firstModuleRendered;

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

                    // Render submodules
                    html += renderSubmodules(module.submodules);

                    html += `
                        </ul>
                    </li>`;

                    if (isFirst) firstModuleRendered = true;
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

    /**
     * Setup click handlers
     */
    function setupClickHandlers() {
        document.querySelectorAll('[data-toggle-submenu]').forEach(toggle => {
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
     * Auto-expand first module
     */
    function autoExpandFirstModule() {
        const firstModule = document.querySelector('[data-module-id]');
        if (firstModule && !firstModule.classList.contains('open')) {
            firstModule.classList.add('open');
            const slideMenu = firstModule.querySelector('.slide-menu');
            if (slideMenu) slideMenu.style.display = 'block';
        }
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

                    if (normalizedCurrent.includes(normalizedHref) || normalizedHref.includes(
                            normalizedCurrent)) {
                        link.classList.add('active');

                        let parent = link.closest('.slide-menu');
                        while (parent) {
                            parent.style.display = 'block';
                            const parentSlide = parent.closest('.slide');
                            if (parentSlide) parentSlide.classList.add('open');
                            parent = parent.parentElement.closest('.slide-menu');
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

<style>
/* Sidebar Active State */
.side-menu__item.active {
    background-color: rgba(44, 164, 191, 0.1);
    color: #2CA4BF !important;
    font-weight: 600;
}

.side-menu__item.active .side-menu__icon {
    color: #2CA4BF !important;
}

/* Open state */
.slide.open>.slide-menu {
    display: block !important;
}

/* Smooth transitions */
.slide-menu {
    transition: all 0.3s ease;
}

/* Loading animation */
.fa-spin {
    animation: fa-spin 1s linear infinite;
}

@keyframes fa-spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}

/* Error states */
.text-warning .side-menu__icon,
.text-warning .side-menu__label {
    color: #ffc107 !important;
}

.text-danger .side-menu__icon,
.text-danger .side-menu__label {
    color: #dc3545 !important;
}

/* Icon styling */
.side-menu__icon {
    width: 20px;
    text-align: center;
    margin-right: 10px;
}

/* Hover effects - Main modules */
.side-menu__item:hover {
    background-color: rgba(255, 255, 255, 0.05);
}

/* Hover effects - Submodules (lighter blue) */
.side-menu__item.submodule-item:hover {
    background-color: rgba(44, 164, 191, 0.15);
    color: #2CA4BF;
}

/* Hover effects - Sub-submodules (brighter blue) */
.side-menu__item.sub-submodule-item:hover {
    background-color: rgba(44, 164, 191, 0.25);
    color: #1a7a8f;
}


/* Chevron rotation */
.slide.open>.side-menu__item>.side-menu__angle {
    transform: rotate(90deg);
    transition: transform 0.3s ease;
}

.side-menu__angle {
    transition: transform 0.3s ease;
}
</style>