/**
 * ============================================================================
 * BUDGET CATEGORIES MANAGEMENT
 * ============================================================================
 * Handles CRUD operations for budget categories
 * ============================================================================
 */

(function () {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    let currentCategories = [];
    let isInitialized = false;
    let editingCategoryId = null;
    let dataTable = null;

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    window.BudgetCategoriesManagement = {
        init: initializeBudgetCategories,
        loadCategories: loadCategories,
        openCreateModal: openCreateModal,
        openEditModal: openEditModal,
        openViewModal: openViewModal,
        saveCategory: saveCategory,
        deleteCategory: deleteCategory
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function initializeBudgetCategories() {
        if (isInitialized) {
            console.log('⏭️ Budget Categories Management already initialized, skipping...');
            loadCategories();
            return;
        }

        console.log('🔧 Initializing Budget Categories Management...');
        setupEventListeners();
        loadCategories();
        isInitialized = true;
    }

    function setupEventListeners() {
        // Create category button
        const createBtn = document.getElementById('createCategoryBtn');
        if (createBtn) {
            createBtn.addEventListener('click', openCreateModal);
        }

        // Save category button
        const saveBtn = document.getElementById('saveCategoryBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveCategory);
        }

        // Delete confirmation button
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', confirmDelete);
        }
    }

    // ========================================================================
    // DATA LOADING
    // ========================================================================

    async function loadCategories() {
        try {
            console.log('📡 Fetching budget categories...');

            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-categories`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                }
            });

            const result = await response.json();
            console.log('📦 API Response:', result);

            if (result.success && result.data) {
                console.log('✅ Categories loaded:', result.data.length);
                currentCategories = result.data;

                // Render all three tabs
                renderAllCategories(result.data);
                renderIncomeCategories(result.data);
                renderExpenseCategories(result.data);
            } else {
                throw new Error(result.message || 'Failed to load categories');
            }
        } catch (error) {
            console.error('❌ Error loading categories:', error);
            showErrorInAllContainers(error.message);
            Toast.error('Failed to load budget categories: ' + error.message);
        }
    }

    function showErrorInAllContainers(message) {
        const containers = ['allCategoriesContainer', 'incomeCategoriesContainer', 'expenseCategoriesContainer'];
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="ri-folder-3-line fs-48 text-danger mb-3 d-block"></i>
                        <p class="text-danger mb-0">Failed to load budget categories</p>
                        <small class="text-muted">${message}</small>
                    </div>`;
            }
        });
    }

    // ========================================================================
    // RENDERING
    // ========================================================================

    function renderAllCategories(categories) {
        renderCategoriesInContainer('allCategoriesContainer', categories, 'allCategoriesTable', 'all');
    }

    function renderIncomeCategories(categories) {
        const incomeCategories = categories.filter(cat => cat.slug && cat.slug.toLowerCase().includes('income'));
        renderCategoriesInContainer('incomeCategoriesContainer', incomeCategories, 'incomeCategoriesTable', 'income');
    }

    function renderExpenseCategories(categories) {
        const expenseCategories = categories.filter(cat => cat.slug && cat.slug.toLowerCase().includes('expense'));
        renderCategoriesInContainer('expenseCategoriesContainer', expenseCategories, 'expenseCategoriesTable', 'expense');
    }

    function renderCategoriesInContainer(containerId, categories, tableId, type) {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (!categories || categories.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-folder-3-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No ${type} categories found</p>
                </div>`;
            return;
        }

        // Destroy existing DataTable if it exists
        if (dataTable && dataTable.table) {
            const existingTable = document.getElementById(tableId);
            if (existingTable && $.fn.DataTable.isDataTable(`#${tableId}`)) {
                $(`#${tableId}`).DataTable().destroy();
            }
        }

        let html = `
            <div class="table-responsive">
                <table class="table table-hover align-middle table-striped" id="${tableId}">
                    <thead class="table-primary">
                        <tr>
                            <th class="fw-semibold">
                                <i class="ri-folder-3-line me-2 text-primary"></i>Name
                            </th>
                            <th class="fw-semibold">
                                <i class="ri-link me-2 text-warning"></i>Slug
                            </th>
                            <th class="fw-semibold">
                                <i class="ri-file-text-line me-2 text-info"></i>Description
                            </th>
                            <th class="fw-semibold text-center">
                                <i class="ri-list-check-2 me-2 text-success"></i>Budget Lines
                            </th>
                            <th class="fw-semibold text-center">
                                <i class="ri-checkbox-circle-line me-2 text-success"></i>Status
                            </th>
                            <th class="fw-semibold text-center">
                                <i class="ri-settings-3-line me-2 text-secondary"></i>Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>`;

        categories.forEach(category => {
            const statusBadge = category.is_active
                ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
                : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';

            const linesCount = category.budget_lines_count || 0;

            html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="avatar avatar-sm bg-primary-transparent me-3">
                                <i class="ri-folder-3-line fs-18"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">${escapeHtml(category.name)}</div>
                            </div>
                        </div>
                    </td>
                    <td><code class="badge bg-light text-dark"><i class="ri-hashtag me-1"></i>${escapeHtml(category.slug)}</code></td>
                    <td>
                        <span class="text-muted">${category.description ? escapeHtml(category.description) : '<em class="text-muted">No description</em>'}</span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info-transparent fs-12"><i class="ri-list-check me-1"></i>${linesCount} line${linesCount !== 1 ? 's' : ''}</span>
                    </td>
                    <td class="text-center">${statusBadge}</td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="ri-settings-3-line me-1"></i>Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetCategoriesManagement.openViewModal(${category.id})">
                                        <i class="ri-eye-line me-2 text-primary"></i>View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetCategoriesManagement.openEditModal(${category.id})">
                                        <i class="ri-edit-line me-2 text-info"></i>Edit
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="BudgetCategoriesManagement.deleteCategory(${category.id}, '${escapeHtml(category.name)}')">
                                        <i class="ri-delete-bin-line me-2"></i>Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>`;
        });

        html += `
                    </tbody>
                </table>
            </div>`;

        container.innerHTML = html;

        // Initialize DataTable with search functionality
        initializeDataTable(tableId);
    }

    // ========================================================================
    // DATATABLE INITIALIZATION
    // ========================================================================

    function initializeDataTable(tableId) {
        // Wait for DOM to be ready
        setTimeout(() => {
            const table = document.getElementById(tableId);
            if (!table) {
                console.error(`❌ Table ${tableId} not found`);
                return;
            }

            try {
                $(`#${tableId}`).DataTable({
                    responsive: true,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search categories...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ categories",
                        infoEmpty: "No categories available",
                        infoFiltered: "(filtered from _MAX_ total categories)",
                        zeroRecords: "No matching categories found",
                        paginate: {
                            first: '<i class="ri-skip-back-mini-line"></i>',
                            last: '<i class="ri-skip-forward-mini-line"></i>',
                            next: '<i class="ri-arrow-right-s-line"></i>',
                            previous: '<i class="ri-arrow-left-s-line"></i>'
                        }
                    },
                    dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                         '<"row"<"col-sm-12"tr>>' +
                         '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                    columnDefs: [
                        { orderable: false, targets: 5 } // Disable sorting on Actions column
                    ],
                    order: [[0, 'asc']], // Sort by Name column by default
                    initComplete: function() {
                        // Style the search input and length select after initialization
                        $(`#${tableId}_wrapper .dataTables_filter input`)
                            .addClass('form-control form-control-sm')
                            .attr('placeholder', 'Search categories...');
                        $(`#${tableId}_wrapper .dataTables_length select`).addClass('form-select form-select-sm');

                        // Add search icon
                        $(`#${tableId}_wrapper .dataTables_filter label`).prepend('<i class="ri-search-line me-2 text-primary"></i>');
                    },
                    drawCallback: function() {
                        // Ensure styling is applied after each draw
                        $(`#${tableId}_wrapper .dataTables_filter input`).addClass('form-control form-control-sm');
                        $(`#${tableId}_wrapper .dataTables_length select`).addClass('form-select form-select-sm');

                        // Style pagination buttons
                        $(`#${tableId}_wrapper .paginate_button`).addClass('btn btn-sm');
                        $(`#${tableId}_wrapper .paginate_button.current`).addClass('btn-primary');
                        $(`#${tableId}_wrapper .paginate_button:not(.current)`).addClass('btn-light border');
                    }
                });

                console.log(`✅ DataTable ${tableId} initialized successfully`);
            } catch (error) {
                console.error(`❌ Error initializing DataTable ${tableId}:`, error);
            }
        }, 100);
    }

    // ========================================================================
    // MODAL OPERATIONS
    // ========================================================================

    function openCreateModal() {
        editingCategoryId = null;

        document.getElementById('modalTitle').textContent = 'Create Budget Category';
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryName').value = '';
        document.getElementById('categorySlug').value = '';
        document.getElementById('categoryDescription').value = '';
        document.getElementById('categoryActive').checked = true;

        const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
        modal.show();
    }

    function openEditModal(categoryId) {
        const category = currentCategories.find(c => c.id === categoryId);

        if (!category) {
            Toast.error('Budget category not found');
            return;
        }

        editingCategoryId = categoryId;

        document.getElementById('modalTitle').textContent = 'Edit Budget Category';
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryName').value = category.name || '';
        document.getElementById('categorySlug').value = category.slug || '';
        document.getElementById('categoryDescription').value = category.description || '';
        document.getElementById('categoryActive').checked = category.is_active;

        const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
        modal.show();
    }

    // ========================================================================
    // CRUD OPERATIONS
    // ========================================================================

    async function saveCategory() {
        const saveBtn = document.getElementById('saveCategoryBtn');
        const originalBtnContent = saveBtn.innerHTML;

        try {
            const name = document.getElementById('categoryName').value.trim();
            const slug = document.getElementById('categorySlug').value.trim();
            const description = document.getElementById('categoryDescription').value.trim();
            const isActive = document.getElementById('categoryActive').checked;

            if (!name) {
                Toast.error('Please enter a category name');
                return;
            }

            // Disable button and show loading animation
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';

            const data = {
                name,
                description,
                is_active: isActive
            };

            if (slug) {
                data.slug = slug;
            }

            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const isEdit = editingCategoryId !== null;
            const url = isEdit
                ? `${API_BASE}/budget-categories/${editingCategoryId}`
                : `${API_BASE}/budget-categories`;

            const response = await fetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                Toast.success(isEdit ? 'Budget category updated successfully' : 'Budget category created successfully');

                const modal = bootstrap.Modal.getInstance(document.getElementById('categoryModal'));
                modal.hide();

                loadCategories();
            } else {
                Toast.error(result.message || 'Failed to save budget category');
            }
        } catch (error) {
            console.error('❌ Error saving category:', error);
            Toast.error('Failed to save budget category: ' + error.message);
        } finally {
            // Re-enable button and restore original content
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnContent;
        }
    }

    function deleteCategory(categoryId, categoryName) {
        editingCategoryId = categoryId;
        document.getElementById('deleteCategoryName').textContent = categoryName;

        const modal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
        modal.show();
    }

    async function confirmDelete() {
        try {
            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-categories/${editingCategoryId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                }
            });

            const result = await response.json();

            if (result.success) {
                Toast.success('Budget category deleted successfully');

                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteCategoryModal'));
                modal.hide();

                loadCategories();
            } else {
                Toast.error(result.message || 'Failed to delete budget category');
            }
        } catch (error) {
            console.error('❌ Error deleting category:', error);
            Toast.error('Failed to delete budget category: ' + error.message);
        }
    }

    // ========================================================================
    // VIEW MODAL & AUDIT TRAIL
    // ========================================================================

    async function openViewModal(categoryId) {
        const category = currentCategories.find(c => c.id === categoryId);

        if (!category) {
            Toast.error('Budget category not found');
            return;
        }

        // Populate details tab
        document.getElementById('view-cat-name').textContent = category.name || '-';
        document.getElementById('view-cat-slug').textContent = category.slug || '-';
        document.getElementById('view-cat-desc').textContent = category.description || 'No description provided';

        const linesCount = category.budget_lines_count || 0;
        document.getElementById('view-cat-lines').textContent = `${linesCount} line${linesCount !== 1 ? 's' : ''}`;

        const statusBadge = category.is_active
            ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
            : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';
        document.getElementById('view-cat-status').innerHTML = statusBadge;

        document.getElementById('view-cat-created').textContent = category.created_at
            ? new Date(category.created_at).toLocaleString()
            : '-';
        document.getElementById('view-cat-updated').textContent = category.updated_at
            ? new Date(category.updated_at).toLocaleString()
            : '-';
        document.getElementById('view-cat-id').textContent = category.id || '-';

        // Reset audit trail tab to loading state
        const auditContainer = document.getElementById('catAuditContainer');
        auditContainer.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2 mb-0">Loading audit trail...</p>
            </div>`;

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewCategoryModal'));
        modal.show();

        // Load audit trail in background
        loadCategoryAuditTrail(categoryId);
    }

    async function loadCategoryAuditTrail(categoryId) {
        try {
            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-categories/${categoryId}/audits`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                }
            });

            const result = await response.json();

            if (result.success && result.data) {
                renderCategoryAuditTrail(result.data);
            } else {
                throw new Error(result.message || 'Failed to load audit trail');
            }
        } catch (error) {
            console.error('❌ Error loading audit trail:', error);
            const auditContainer = document.getElementById('catAuditContainer');
            auditContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
                    <p class="text-danger mb-0">Failed to load audit trail</p>
                    <small class="text-muted">${error.message}</small>
                </div>`;
        }
    }

    function renderCategoryAuditTrail(audits) {
        const auditContainer = document.getElementById('catAuditContainer');

        if (!audits || audits.length === 0) {
            auditContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-history-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No audit trail available</p>
                </div>`;
            return;
        }

        let html = '<ul class="list-unstyled profile-timeline">';

        audits.forEach((audit, index) => {
            const eventColors = {
                'created': 'success',
                'updated': 'info',
                'deleted': 'danger'
            };
            const color = eventColors[audit.event] || 'secondary';

            const eventIcons = {
                'created': 'ri-add-circle-line',
                'updated': 'ri-edit-circle-line',
                'deleted': 'ri-delete-bin-line'
            };
            const icon = eventIcons[audit.event] || 'ri-information-line';

            html += `
                <li>
                    <div class="d-flex align-items-top">
                        <div class="me-3 flex-shrink-0">
                            <span class="avatar avatar-md bg-${color}-transparent text-${color} avatar-rounded">
                                <i class="${icon} fs-18"></i>
                            </span>
                        </div>
                        <div class="flex-fill">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-semibold mb-0 text-capitalize">
                                    ${audit.event}
                                    <span class="badge bg-${color}-transparent text-${color} ms-2">${audit.event}</span>
                                </h6>
                                <span class="text-muted fs-11">${audit.created_at_human}</span>
                            </div>
                            <p class="mb-2 text-muted fs-12">
                                <i class="ri-calendar-line me-1"></i>${audit.created_at}
                            </p>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <div class="p-2 bg-light border rounded">
                                        <small class="text-muted d-block mb-1">
                                            <i class="ri-user-line me-1"></i>Changed By
                                        </small>
                                        <span class="fw-semibold fs-13">
                                            ${audit.user ? `${audit.user.name}<br><small class="text-muted">${audit.user.email}</small>` : 'System'}
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 bg-light border rounded">
                                        <small class="text-muted d-block mb-1">
                                            <i class="ri-map-pin-line me-1"></i>IP Address
                                        </small>
                                        <code class="fs-12">${audit.ip_address || 'N/A'}</code>
                                    </div>
                                </div>
                            </div>`;

            // Show changes for update events
            if (audit.event === 'updated' && audit.old_values && audit.new_values) {
                html += `
                    <div class="accordion accordion-flush mt-2" id="catAccordion${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#catCollapse${index}">
                                    <i class="ri-file-list-line me-2 text-warning"></i>View Changes Made
                                </button>
                            </h2>
                            <div id="catCollapse${index}" class="accordion-collapse collapse" data-bs-parent="#catAccordion${index}">
                                <div class="accordion-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="fw-semibold fs-12">Field</th>
                                                    <th class="fw-semibold fs-12 text-danger">Old Value</th>
                                                    <th class="fw-semibold fs-12 text-success">New Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                Object.keys(audit.new_values).forEach(key => {
                    if (audit.old_values[key] !== audit.new_values[key]) {
                        html += `
                            <tr>
                                <td class="fw-medium fs-12">${formatFieldName(key)}</td>
                                <td><span class="badge bg-danger-transparent fs-11">${formatValue(audit.old_values[key])}</span></td>
                                <td><span class="badge bg-success-transparent fs-11">${formatValue(audit.new_values[key])}</span></td>
                            </tr>`;
                    }
                });

                html += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            }

            // Show values for create events
            if (audit.event === 'created' && audit.new_values) {
                html += `
                    <div class="accordion accordion-flush mt-2" id="catAccordionCreate${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#catCollapseCreate${index}">
                                    <i class="ri-file-list-line me-2 text-success"></i>View Initial Values
                                </button>
                            </h2>
                            <div id="catCollapseCreate${index}" class="accordion-collapse collapse" data-bs-parent="#catAccordionCreate${index}">
                                <div class="accordion-body bg-light p-3">`;

                Object.keys(audit.new_values).forEach(key => {
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="fw-semibold fs-12">${formatFieldName(key)}:</span>
                            <span class="badge bg-primary-transparent fs-11">${formatValue(audit.new_values[key])}</span>
                        </div>`;
                });

                html += `
                                </div>
                            </div>
                        </div>
                    </div>`;
            }

            html += `
                        </div>
                    </div>
                </li>`;
        });

        html += '</ul>';
        auditContainer.innerHTML = html;
    }

    function formatFieldName(fieldName) {
        return fieldName
            .replace(/_/g, ' ')
            .replace(/\b\w/g, char => char.toUpperCase());
    }

    function formatValue(value) {
        if (value === null || value === undefined) return '-';
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (typeof value === 'object') return JSON.stringify(value);
        return escapeHtml(value.toString());
    }

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

})();
