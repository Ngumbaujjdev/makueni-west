/**
 * ============================================================================
 * BUDGET TYPES MANAGEMENT
 * ============================================================================
 * Handles CRUD operations for budget types
 * ============================================================================
 */

(function () {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    let currentBudgetTypes = [];
    let isInitialized = false;
    let editingTypeId = null;
    let dataTable = null;

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    window.BudgetTypesManagement = {
        init: initializeBudgetTypes,
        loadBudgetTypes: loadBudgetTypes,
        openCreateModal: openCreateModal,
        openEditModal: openEditModal,
        openViewModal: openViewModal,
        saveBudgetType: saveBudgetType,
        deleteBudgetType: deleteBudgetType
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function initializeBudgetTypes() {
        if (isInitialized) {
            console.log('⏭️ Budget Types Management already initialized, skipping...');
            loadBudgetTypes();
            return;
        }

        console.log('🔧 Initializing Budget Types Management...');
        setupEventListeners();
        loadBudgetTypes();
        isInitialized = true;
    }

    function setupEventListeners() {
        // Create budget type button
        const createBtn = document.getElementById('createBudgetTypeBtn');
        if (createBtn) {
            createBtn.addEventListener('click', openCreateModal);
        }

        // Save budget type button
        const saveBtn = document.getElementById('saveBudgetTypeBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveBudgetType);
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

    async function loadBudgetTypes() {
        try {
            const container = document.getElementById('budgetTypesContainer');
            if (!container) {
                console.error('❌ Budget types container not found!');
                return;
            }

            // Show loading state
            container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading budget types...</p>
                </div>`;

            console.log('📡 Fetching budget types...');

            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-types`, {
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
                console.log('✅ Budget types loaded:', result.data.length);
                currentBudgetTypes = result.data;
                renderBudgetTypes(result.data);
            } else {
                throw new Error(result.message || 'Failed to load budget types');
            }
        } catch (error) {
            console.error('❌ Error loading budget types:', error);
            const container = document.getElementById('budgetTypesContainer');
            if (container) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="ri-calendar-2-line fs-48 text-danger mb-3 d-block"></i>
                        <p class="text-danger mb-0">Failed to load budget types</p>
                        <small class="text-muted">${error.message}</small>
                    </div>`;
            }
            Toast.error('Failed to load budget types: ' + error.message);
        }
    }

    // ========================================================================
    // RENDERING
    // ========================================================================

    function renderBudgetTypes(types) {
        const container = document.getElementById('budgetTypesContainer');
        if (!container) return;

        if (!types || types.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-calendar-2-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No budget types found</p>
                </div>`;
            return;
        }

        // Destroy existing DataTable if it exists
        if (dataTable) {
            const existingTable = document.getElementById('budgetTypesTable');
            if (existingTable && $.fn.DataTable.isDataTable('#budgetTypesTable')) {
                $('#budgetTypesTable').DataTable().destroy();
            }
        }

        let html = `
            <div class="table-responsive">
                <table class="table table-hover align-middle table-striped" id="budgetTypesTable">
                    <thead class="table-primary">
                        <tr>
                            <th class="fw-semibold">
                                <i class="ri-calendar-2-line me-2 text-primary"></i>Name
                            </th>
                            <th class="fw-semibold">
                                <i class="ri-link me-2 text-warning"></i>Slug
                            </th>
                            <th class="fw-semibold text-center">
                                <i class="ri-time-line me-2 text-info"></i>Duration
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

        types.forEach(type => {
            const statusBadge = type.is_active
                ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
                : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';

            const duration = type.duration_months
                ? `${type.duration_months} month${type.duration_months !== 1 ? 's' : ''}`
                : 'Custom';

            html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="avatar avatar-sm bg-primary-transparent me-3">
                                <i class="ri-calendar-2-line fs-18"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">${escapeHtml(type.name)}</div>
                            </div>
                        </div>
                    </td>
                    <td><code class="badge bg-light text-dark"><i class="ri-hashtag me-1"></i>${escapeHtml(type.slug)}</code></td>
                    <td class="text-center">
                        <span class="badge bg-info-transparent fs-12"><i class="ri-time-line me-1"></i>${duration}</span>
                    </td>
                    <td class="text-center">${statusBadge}</td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="ri-settings-3-line me-1"></i>Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetTypesManagement.openViewModal(${type.id})">
                                        <i class="ri-eye-line me-2 text-primary"></i>View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0);" onclick="BudgetTypesManagement.openEditModal(${type.id})">
                                        <i class="ri-edit-line me-2 text-info"></i>Edit
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="javascript:void(0);" onclick="BudgetTypesManagement.deleteBudgetType(${type.id}, '${escapeHtml(type.name)}')">
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
        initializeDataTable();
    }

    // ========================================================================
    // DATATABLE INITIALIZATION
    // ========================================================================

    function initializeDataTable() {
        // Wait for DOM to be ready
        setTimeout(() => {
            const table = document.getElementById('budgetTypesTable');
            if (!table) {
                console.error('❌ Table budgetTypesTable not found');
                return;
            }

            try {
                dataTable = $('#budgetTypesTable').DataTable({
                    responsive: true,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search budget types...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ budget types",
                        infoEmpty: "No budget types available",
                        infoFiltered: "(filtered from _MAX_ total budget types)",
                        zeroRecords: "No matching budget types found",
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
                        { orderable: false, targets: 4 } // Disable sorting on Actions column
                    ],
                    order: [[0, 'asc']], // Sort by Name column by default
                    initComplete: function() {
                        // Style the search input and length select after initialization
                        $('#budgetTypesTable_wrapper .dataTables_filter input')
                            .addClass('form-control form-control-sm')
                            .attr('placeholder', 'Search budget types...');
                        $('#budgetTypesTable_wrapper .dataTables_length select').addClass('form-select form-select-sm');

                        // Add search icon
                        $('#budgetTypesTable_wrapper .dataTables_filter label').prepend('<i class="ri-search-line me-2 text-primary"></i>');
                    },
                    drawCallback: function() {
                        // Ensure styling is applied after each draw
                        $('#budgetTypesTable_wrapper .dataTables_filter input').addClass('form-control form-control-sm');
                        $('#budgetTypesTable_wrapper .dataTables_length select').addClass('form-select form-select-sm');

                        // Style pagination buttons
                        $('#budgetTypesTable_wrapper .paginate_button').addClass('btn btn-sm');
                        $('#budgetTypesTable_wrapper .paginate_button.current').addClass('btn-primary');
                        $('#budgetTypesTable_wrapper .paginate_button:not(.current)').addClass('btn-light border');
                    }
                });

                console.log('✅ DataTable budgetTypesTable initialized successfully');
            } catch (error) {
                console.error('❌ Error initializing DataTable budgetTypesTable:', error);
            }
        }, 100);
    }

    // ========================================================================
    // MODAL OPERATIONS
    // ========================================================================

    function openCreateModal() {
        editingTypeId = null;

        document.getElementById('modalTitle').textContent = 'Create Budget Type';
        document.getElementById('budgetTypeId').value = '';
        document.getElementById('typeName').value = '';
        document.getElementById('typeSlug').value = '';
        document.getElementById('durationMonths').value = '';
        document.getElementById('typeActive').checked = true;

        const modal = new bootstrap.Modal(document.getElementById('budgetTypeModal'));
        modal.show();
    }

    function openEditModal(typeId) {
        const type = currentBudgetTypes.find(t => t.id === typeId);

        if (!type) {
            Toast.error('Budget type not found');
            return;
        }

        editingTypeId = typeId;

        document.getElementById('modalTitle').textContent = 'Edit Budget Type';
        document.getElementById('budgetTypeId').value = type.id;
        document.getElementById('typeName').value = type.name || '';
        document.getElementById('typeSlug').value = type.slug || '';
        document.getElementById('durationMonths').value = type.duration_months || '';
        document.getElementById('typeActive').checked = type.is_active;

        const modal = new bootstrap.Modal(document.getElementById('budgetTypeModal'));
        modal.show();
    }

    // ========================================================================
    // CRUD OPERATIONS
    // ========================================================================

    async function saveBudgetType() {
        const saveBtn = document.getElementById('saveBudgetTypeBtn');
        const originalBtnContent = saveBtn.innerHTML;

        try {
            const name = document.getElementById('typeName').value.trim();
            const slug = document.getElementById('typeSlug').value.trim();
            const durationMonths = document.getElementById('durationMonths').value;
            const isActive = document.getElementById('typeActive').checked;

            if (!name) {
                Toast.error('Please enter a type name');
                return;
            }

            // Disable button and show loading animation
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';

            const data = {
                name,
                is_active: isActive
            };

            if (slug) {
                data.slug = slug;
            }

            if (durationMonths) {
                data.duration_months = parseInt(durationMonths);
            }

            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const isEdit = editingTypeId !== null;
            const url = isEdit
                ? `${API_BASE}/budget-types/${editingTypeId}`
                : `${API_BASE}/budget-types`;

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
                Toast.success(isEdit ? 'Budget type updated successfully' : 'Budget type created successfully');

                const modal = bootstrap.Modal.getInstance(document.getElementById('budgetTypeModal'));
                modal.hide();

                loadBudgetTypes();
            } else {
                Toast.error(result.message || 'Failed to save budget type');
            }
        } catch (error) {
            console.error('❌ Error saving budget type:', error);
            Toast.error('Failed to save budget type: ' + error.message);
        } finally {
            // Re-enable button and restore original content
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnContent;
        }
    }

    function deleteBudgetType(typeId, typeName) {
        editingTypeId = typeId;
        document.getElementById('deleteBudgetTypeName').textContent = typeName;

        const modal = new bootstrap.Modal(document.getElementById('deleteBudgetTypeModal'));
        modal.show();
    }

    async function confirmDelete() {
        const deleteBtn = document.getElementById('confirmDeleteBtn');
        const originalBtnContent = deleteBtn.innerHTML;

        try {
            // Disable button and show loading animation
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Deleting...';

            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-types/${editingTypeId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                }
            });

            const result = await response.json();

            if (result.success) {
                Toast.success('Budget type deleted successfully');

                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteBudgetTypeModal'));
                modal.hide();

                loadBudgetTypes();
            } else {
                Toast.error(result.message || 'Failed to delete budget type');
            }
        } catch (error) {
            console.error('❌ Error deleting budget type:', error);
            Toast.error('Failed to delete budget type: ' + error.message);
        } finally {
            // Re-enable button and restore original content
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = originalBtnContent;
        }
    }

    // ========================================================================
    // VIEW MODAL & AUDIT TRAIL
    // ========================================================================

    async function openViewModal(typeId) {
        const type = currentBudgetTypes.find(t => t.id === typeId);

        if (!type) {
            Toast.error('Budget type not found');
            return;
        }

        // Populate details tab
        document.getElementById('view-name').textContent = type.name || '-';
        document.getElementById('view-slug').textContent = type.slug || '-';
        document.getElementById('view-duration').textContent = type.duration_months
            ? `${type.duration_months} month${type.duration_months !== 1 ? 's' : ''}`
            : 'Custom';

        const statusBadge = type.is_active
            ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
            : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';
        document.getElementById('view-status').innerHTML = statusBadge;

        document.getElementById('view-created-at').textContent = type.created_at
            ? new Date(type.created_at).toLocaleString()
            : '-';
        document.getElementById('view-updated-at').textContent = type.updated_at
            ? new Date(type.updated_at).toLocaleString()
            : '-';
        document.getElementById('view-id').textContent = type.id || '-';

        // Reset audit trail tab to loading state
        const auditContainer = document.getElementById('auditTrailContainer');
        auditContainer.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-2 mb-0">Loading audit trail...</p>
            </div>`;

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewBudgetTypeModal'));
        modal.show();

        // Load audit trail in background
        loadAuditTrail(typeId);
    }

    async function loadAuditTrail(typeId) {
        try {
            const API_BASE = window.AppConfig?.API_BASE_URL || 'http://localhost:8000/api';
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(`${API_BASE}/budget-types/${typeId}/audits`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${authToken}`
                }
            });

            const result = await response.json();

            if (result.success && result.data) {
                renderAuditTrail(result.data);
            } else {
                throw new Error(result.message || 'Failed to load audit trail');
            }
        } catch (error) {
            console.error('❌ Error loading audit trail:', error);
            const auditContainer = document.getElementById('auditTrailContainer');
            auditContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
                    <p class="text-danger mb-0">Failed to load audit trail</p>
                    <small class="text-muted">${error.message}</small>
                </div>`;
        }
    }

    function renderAuditTrail(audits) {
        const auditContainer = document.getElementById('auditTrailContainer');

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
                    <div class="accordion accordion-flush mt-2" id="accordion${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${index}">
                                    <i class="ri-file-list-line me-2 text-warning"></i>View Changes Made
                                </button>
                            </h2>
                            <div id="collapse${index}" class="accordion-collapse collapse" data-bs-parent="#accordion${index}">
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
                    <div class="accordion accordion-flush mt-2" id="accordionCreate${index}">
                        <div class="accordion-item border">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 fs-13 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCreate${index}">
                                    <i class="ri-file-list-line me-2 text-success"></i>View Initial Values
                                </button>
                            </h2>
                            <div id="collapseCreate${index}" class="accordion-collapse collapse" data-bs-parent="#accordionCreate${index}">
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
