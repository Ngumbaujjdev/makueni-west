/**
 * Global command-palette search (#searchModal, markup in
 * includes/global-search.php, included from header.php so it's on every
 * page - previously this modal only existed, hardcoded and non-functional,
 * on 3 dashboard pages).
 *
 * Flattens the same cached, permission-filtered module tree the sidebar
 * itself reads (localStorage "mwd_current_modules") into a searchable list
 * of real pages - no new API call, and results are automatically scoped to
 * what the signed-in user can already see, the same way the sidebar/
 * secondary-nav already are. A standalone static file (mirrors
 * secondary-nav.js's own reasoning) because header.php - and this script -
 * load before sidebar.php on the page, so it can't rely on anything
 * sidebar.php defines. Base URL is bridged in via window.mwdBaseUrl, set
 * by header.php.
 */
(function () {
    'use strict';

    let allItems = [];
    let filteredItems = [];
    let selectedIndex = -1;
    let modalInstance = null;

    function getCachedModules() {
        try {
            const cached = localStorage.getItem('mwd_current_modules');
            return cached ? JSON.parse(cached) : null;
        } catch (error) {
            console.error('Error parsing cached modules for search:', error);
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : text;
        return div.innerHTML;
    }

    /**
     * Only submodules/sub-submodules carry a navigable path in this app's
     * data model - a bare top-level module never does (confirmed against
     * the live /api/modules response: no "path" key on a module at all,
     * and sidebar.php's own rendering never links one directly either, it
     * only ever opens that module's flyout/accordion of submodules).
     */
    function flattenModules(cachedModules) {
        if (!cachedModules || !cachedModules.module_groups) return [];
        const items = [];

        cachedModules.module_groups.forEach((group) => {
            const modulesArray = Array.isArray(group.modules) ? group.modules : Object.values(group.modules || {});

            modulesArray.forEach((module) => {
                (module.submodules || []).forEach((submodule) => {
                    const subSubmodules = submodule.sub_submodules || [];

                    if (subSubmodules.length > 0) {
                        subSubmodules.forEach((subSubmodule) => {
                            if (!subSubmodule.path) return;
                            items.push({
                                title: subSubmodule.title,
                                path: formatPath(subSubmodule.path),
                                icon: module.icon,
                                groupName: group.name,
                                moduleName: module.name,
                            });
                        });
                        return;
                    }

                    if (!submodule.path) return;
                    items.push({
                        title: submodule.title,
                        path: formatPath(submodule.path),
                        icon: module.icon,
                        groupName: group.name,
                        moduleName: module.name,
                    });
                });
            });
        });

        return items;
    }

    function getIconClass(icon) {
        if (icon && icon.startsWith('ri-')) return icon;
        return 'ri-file-list-3-line';
    }

    function renderResults() {
        const container = document.getElementById('globalSearchResults');
        if (!container) return;

        if (filteredItems.length === 0) {
            container.innerHTML = `<div class="text-center text-body py-4 fs-13">${
                allItems.length === 0 ? 'No pages available.' : 'No matching pages.'
            }</div>`;
            return;
        }

        let html = '';
        let currentGroup = null;

        filteredItems.forEach((item, index) => {
            if (item.groupName !== currentGroup) {
                currentGroup = item.groupName;
                html += `<div class="fw-semibold text-dark text-uppercase fs-11 px-4 pt-3 pb-1">${escapeHtml(currentGroup)}</div>`;
            }

            html += `
            <a href="${item.path}" class="global-search-result${index === selectedIndex ? ' active' : ''}" data-index="${index}">
                <i class="${getIconClass(item.icon)} me-2"></i>
                <span>${escapeHtml(item.title)}</span>
                <span class="fs-11 text-body ms-auto">${escapeHtml(item.moduleName)}</span>
            </a>`;
        });

        container.innerHTML = html;
    }

    function filterItems(query) {
        const trimmed = query.trim().toLowerCase();
        filteredItems = trimmed
            ? allItems.filter(
                  (item) =>
                      item.title.toLowerCase().includes(trimmed) ||
                      item.moduleName.toLowerCase().includes(trimmed) ||
                      item.groupName.toLowerCase().includes(trimmed)
              )
            : allItems;
        selectedIndex = filteredItems.length > 0 ? 0 : -1;
        renderResults();
    }

    function moveSelection(delta) {
        if (filteredItems.length === 0) return;
        selectedIndex = (selectedIndex + delta + filteredItems.length) % filteredItems.length;
        renderResults();
        const active = document.querySelector('.global-search-result.active');
        if (active) active.scrollIntoView({ block: 'nearest' });
    }

    function openSelected() {
        const item = filteredItems[selectedIndex];
        if (item) window.location.href = item.path;
    }

    function init() {
        const modalEl = document.getElementById('searchModal');
        const input = document.getElementById('globalSearchInput');
        const results = document.getElementById('globalSearchResults');
        if (!modalEl || !input || !results) return;

        modalEl.addEventListener('shown.bs.modal', () => {
            allItems = flattenModules(getCachedModules());
            input.value = '';
            filterItems('');
            input.focus();
        });

        input.addEventListener('input', () => filterItems(input.value));

        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveSelection(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveSelection(-1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                openSelected();
            }
        });

        results.addEventListener('click', (e) => {
            const resultEl = e.target.closest('.global-search-result');
            if (!resultEl) return;
            e.preventDefault();
            selectedIndex = parseInt(resultEl.dataset.index, 10);
            openSelected();
        });

        // Cmd/Ctrl+K opens the palette from anywhere on the page.
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                modalInstance = modalInstance || bootstrap.Modal.getOrCreateInstance(modalEl);
                modalInstance.show();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
