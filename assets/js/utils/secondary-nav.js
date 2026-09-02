/**
 * Secondary nav (horizontal sub-tab bar)
 *
 * Renders the current module's submodules as tabs under the header,
 * reusing the same cached, permission-filtered module tree the sidebar
 * reads (localStorage "mwd_current_modules") - no new API calls.
 *
 * A standalone static file (unlike sidebar.php's inline PHP-templated
 * script) because header.php - and this script - load before sidebar.php
 * on the page, so it can't rely on anything sidebar.php defines. Base URL
 * is bridged in via window.mwdBaseUrl, set by header.php.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', renderSecondaryNav);

    function getCachedModules() {
        try {
            const cached = localStorage.getItem('mwd_current_modules');
            return cached ? JSON.parse(cached) : null;
        } catch (error) {
            console.error('Error parsing cached modules:', error);
            return null;
        }
    }

    function formatPath(path) {
        if (!path) return path;
        let cleanPath = path.replace(/\.php$/, '');
        if (!cleanPath.startsWith('/')) {
            cleanPath = '/' + cleanPath;
        }
        const baseUrl = window.mwdBaseUrl || '';
        if (baseUrl && !cleanPath.startsWith(baseUrl)) {
            cleanPath = baseUrl + cleanPath;
        }
        return cleanPath;
    }

    function pathsMatch(currentPath, href) {
        if (!href) return false;
        const normalizedHref = href.replace(/\.php$/, '');
        const normalizedCurrent = currentPath.replace(/\.php$/, '');
        // One-directional only: the current page may have extra suffix
        // segments beyond the stored path (e.g. "/edit-budget/5" while the
        // stored path is "/edit-budget"). Matching the *other* direction
        // too (href contains current) falsely matches every sibling
        // submodule at once whenever the current path is a short prefix
        // shared by all of them - e.g. a module's own bare landing page.
        return normalizedCurrent.includes(normalizedHref);
    }

    /**
     * Find the module whose submodule/sub-submodule tree contains the
     * current page, so its submodules can be rendered as tabs.
     */
    function findActiveModule(cachedModules) {
        if (!cachedModules || !cachedModules.module_groups) return null;
        const currentPath = window.location.pathname;

        for (const group of cachedModules.module_groups) {
            const modulesArray = Array.isArray(group.modules) ? group.modules : Object.values(group.modules || {});
            for (const module of modulesArray) {
                const submodules = module.submodules || [];
                for (const submodule of submodules) {
                    if (pathsMatch(currentPath, formatPath(submodule.path))) {
                        return module;
                    }
                    const subSubmodules = submodule.sub_submodules || [];
                    for (const subSubmodule of subSubmodules) {
                        if (pathsMatch(currentPath, formatPath(subSubmodule.path))) {
                            return module;
                        }
                    }
                }
            }
        }
        return null;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : text;
        return div.innerHTML;
    }

    /**
     * The app-header (and, on desktop, the sidebar) are position:fixed, so
     * a normal-flow #secondary-nav-bar placed right after </header> in
     * header.php renders at y=0, hidden behind the fixed header rather
     * than pushed below it. Position it like the sidebar's flyout panel:
     * computed at render-time against the actual header/sidebar rects,
     * so it stays correct if either one's size changes.
     */
    function positionSecondaryNav(container, visible) {
        const headerEl = document.querySelector('.app-header');
        const sidebarEl = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');

        if (!visible) {
            container.style.display = 'none';
            if (mainContent) mainContent.style.paddingBlockStart = '';
            return;
        }

        container.style.display = 'block';
        container.style.position = 'fixed';
        container.style.zIndex = '90'; // below the header's z-index 100

        const headerRect = headerEl ? headerEl.getBoundingClientRect() : { bottom: 0 };
        const sidebarRect = sidebarEl ? sidebarEl.getBoundingClientRect() : { right: 0 };
        container.style.top = headerRect.bottom + 'px';
        container.style.left = sidebarRect.right + 'px';
        container.style.right = '0';

        // Push page content down to make room for the bar, on top of
        // whatever clearance it already reserves for the fixed header -
        // NOT recomputed from headerRect.bottom, because how a page
        // clears the header varies by its data-vertical-style: some rely
        // on .main-content's own padding-block-start (e.g. the default
        // "overlay" is what most diocese pages use), others add a
        // separate margin-block-start on top of that (church pages using
        // data-vertical-style="overlay" get an extra 60px margin - using
        // headerRect.bottom here double-counted that, stacking an extra
        // header's worth of empty space above the page content). Capture
        // whatever the page's own CSS already set once, then only add the
        // bar's own height to it.
        if (mainContent) {
            if (mainContent.dataset.baseTopOffset === undefined) {
                mainContent.dataset.baseTopOffset = getComputedStyle(mainContent).paddingBlockStart;
            }
            const baseOffset = parseFloat(mainContent.dataset.baseTopOffset) || 0;
            const barHeight = container.getBoundingClientRect().height;
            mainContent.style.paddingBlockStart = (baseOffset + barHeight) + 'px';
        }
    }

    function renderSecondaryNav() {
        const container = document.getElementById('secondary-nav-bar');
        if (!container) return;

        const cachedModules = getCachedModules();
        const activeModule = findActiveModule(cachedModules);
        const submodules = activeModule && activeModule.submodules ? activeModule.submodules : [];

        if (!activeModule || submodules.length === 0) {
            container.innerHTML = '';
            positionSecondaryNav(container, false);
            return;
        }

        const currentPath = window.location.pathname;
        let html = '<ul class="nav nav-tabs">';

        submodules.forEach(submodule => {
            const hasChildren = submodule.sub_submodules && submodule.sub_submodules.length > 0;
            const ownHref = formatPath(submodule.path);

            if (hasChildren) {
                // A submodule with its own children isn't a direct leaf page
                // in the sidebar either (see sidebar.php's renderSubmodules) -
                // surface it as a dropdown of its sub-submodules instead of
                // linking straight to a path that may not resolve.
                const isActive = pathsMatch(currentPath, ownHref) ||
                    submodule.sub_submodules.some(sub => pathsMatch(currentPath, formatPath(sub.path)));

                html += `
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle${isActive ? ' active' : ''}" href="javascript:void(0);" data-bs-toggle="dropdown" aria-expanded="false">
                            ${escapeHtml(submodule.title)}
                        </a>
                        <ul class="dropdown-menu">
                            ${submodule.sub_submodules.map(sub => {
                                const subHref = formatPath(sub.path) || '#';
                                const subActive = pathsMatch(currentPath, subHref);
                                return `<li><a class="dropdown-item${subActive ? ' active' : ''}" href="${escapeHtml(subHref)}">${escapeHtml(sub.title)}</a></li>`;
                            }).join('')}
                        </ul>
                    </li>`;
            } else {
                const isActive = pathsMatch(currentPath, ownHref);
                html += `
                    <li class="nav-item">
                        <a class="nav-link${isActive ? ' active' : ''}" href="${escapeHtml(ownHref || '#')}">
                            ${escapeHtml(submodule.title)}
                        </a>
                    </li>`;
            }
        });

        html += '</ul>';
        container.innerHTML = html;
        positionSecondaryNav(container, true);
    }

    window.addEventListener('resize', function() {
        const container = document.getElementById('secondary-nav-bar');
        if (container && container.innerHTML.trim() !== '') {
            positionSecondaryNav(container, true);
        }
    });
})();
