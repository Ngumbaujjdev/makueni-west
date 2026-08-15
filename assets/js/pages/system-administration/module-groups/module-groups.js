/**
 * ============================================================================
 * MODULE GROUPS MANAGEMENT
 * ============================================================================
 * Handles CRUD operations for module groups
 * ============================================================================
 */

(function () {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    let currentTerritoryLevel = 'diocese';
    let currentGroups = [];
    let isInitialized = false;

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    window.ModuleGroupsManagement = {
        init: initializeModuleGroups,
        loadGroups: loadGroups,
        openCreateModal: openCreateModal,
        openEditModal: openEditModal,
        saveGroup: saveGroup,
        deleteGroup: deleteGroup
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function initializeModuleGroups() {
        if (isInitialized) {
            console.log('⏭️ Module Groups Management already initialized, skipping...');
            loadGroups();
            return;
        }

        console.log('🔧 Initializing Module Groups Management...');
        setupEventListeners();
        loadGroups();
        isInitialized = true;
    }

    function setupEventListeners() {
        // Territory filter - REAL-TIME (on change)
        const territoryFilter = document.getElementById('groupTerritoryFilter');
        if (territoryFilter) {
            territoryFilter.addEventListener('change', () => {
                currentTerritoryLevel = territoryFilter.value;
                console.log('🔄 Territory changed to:', currentTerritoryLevel || 'All');
                loadGroups();
            });
        }

        // Apply filter button
        const applyFilterBtn = document.getElementById('applyGroupFilters');
        if (applyFilterBtn) {
            applyFilterBtn.addEventListener('click', () => {
                currentTerritoryLevel = territoryFilter ? territoryFilter.value : 'diocese';
                console.log('🔄 Manually applying filter:', currentTerritoryLevel);
                loadGroups();
            });
        }

        // Create group button
        const createGroupBtn = document.getElementById('createGroupBtn');
        if (createGroupBtn) {
            createGroupBtn.addEventListener('click', openCreateModal);
        }

        // Save group button
        const saveGroupBtn = document.getElementById('saveGroupBtn');
        if (saveGroupBtn) {
            saveGroupBtn.addEventListener('click', saveGroup);
        }
    }

    // ========================================================================
    // DATA LOADING
    // ========================================================================

    async function loadGroups() {
        try {
            const container = document.getElementById('groupsListContainer');
            if (!container) {
                console.error('❌ Groups list container not found!');
                return;
            }

            // Show loading state
            container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading module groups...</p>
                </div>`;

            console.log('📡 Fetching module groups for territory:', currentTerritoryLevel || 'All');

            // Use the proper API handler with correct auth token
            const API_BASE = AppConfig.API_BASE_URL;
            const queryParams = currentTerritoryLevel ? `territory_scope=${currentTerritoryLevel}` : '';
            const url = `${API_BASE}/module-groups${queryParams ? '?' + queryParams : ''}`;

            // Get auth token using Constants
            const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

            const response = await fetch(url, {
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
                // Backend returns { module_groups: [...] }
                const groups = result.data.module_groups || [];
                console.log('✅ Groups loaded:', groups.length);
                currentGroups = groups;
                renderGroups(groups);
            } else {
                console.error('❌ Response not successful:', result);
                throw new Error(result.message || 'Failed to load groups');
            }
        } catch (error) {
            console.error('❌ Error loading groups:', error);
            const container = document.getElementById('groupsListContainer');
            if (container) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="ri-stack-line fs-48 text-danger mb-3 d-block"></i>
                        <p class="text-danger mb-0">Failed to load module groups</p>
                        <small class="text-muted">${error.message}</small>
                    </div>`;
            }
            Toast.error('Failed to load module groups: ' + error.message);
        }
    }

    // ========================================================================
    // RENDERING
    // ========================================================================

    function renderGroups(groups) {
        const container = document.getElementById('groupsListContainer');
        if (!container) return;

        if (!groups || groups.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-stack-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No module groups found for this territory</p>
                </div>`;
            return;
        }

        let html = '<div class="table-responsive">';
        html += '<table class="table table-hover table-bordered">';
        html += `
            <thead class="table-primary">
                <tr>
                    <th width="50">#</th>
                    <th width="60">Icon</th>
                    <th>Group Name</th>
                    <th>Territory</th>
                    <th>Description</th>
                    <th width="100" class="text-center">Modules</th>
                    <th width="100" class="text-center">Status</th>
                    <th width="150" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>`;

        groups.forEach((group, index) => {
            html += `
                <tr>
                    <td class="text-center fw-semibold">${group.order_number || (index + 1)}</td>
                    <td class="text-center">
                        <i class="${group.icon || 'ri-stack-line'} fs-24 text-primary"></i>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark">${group.name}</div>
                    </td>
                    <td>
                        <span class="badge bg-info-transparent">
                            ${capitalizeFirst(group.territory_scope)}
                        </span>
                    </td>
                    <td>
                        <small class="text-muted">${group.description || 'No description'}</small>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary-transparent text-primary fs-14">
                            ${group.modules_count || 0}
                        </span>
                    </td>
                    <td class="text-center">
                        ${group.is_active ?
                            '<span class="badge bg-success">Active</span>' :
                            '<span class="badge bg-secondary">Inactive</span>'}
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-success-light btn-sm"
                                    onclick="ModuleGroupsManagement.openEditModal(${group.id})"
                                    title="Edit Group">
                                <i class="ri-edit-line"></i>
                            </button>
                            <button class="btn btn-danger-light btn-sm"
                                    onclick="ModuleGroupsManagement.deleteGroup(${group.id}, '${group.name.replace(/'/g, "\\'")}')"
                                    title="Delete Group">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    function capitalizeFirst(str) {
        return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
    }

    /**
     * Auto-generate icon based on group name
     */
    function generateIconFromName(name) {
        const iconMap = {
            // Common group names
            'dashboard': 'ri-dashboard-line',
            'overview': 'ri-dashboard-line',
            'home': 'ri-home-line',

            // Finance & Money
            'finance': 'ri-money-dollar-circle-line',
            'accounting': 'ri-calculator-line',
            'budget': 'ri-wallet-line',
            'payment': 'ri-bank-card-line',
            'treasury': 'ri-safe-line',

            // HR & People
            'hr': 'ri-team-line',
            'human': 'ri-team-line',
            'staff': 'ri-user-line',
            'employee': 'ri-user-line',
            'personnel': 'ri-contacts-line',

            // Church & Ministry
            'church': 'ri-community-line',
            'ministry': 'ri-church-line',
            'worship': 'ri-music-line',
            'prayer': 'ri-hand-heart-line',
            'spiritual': 'ri-lightbulb-line',

            // Communication
            'communication': 'ri-message-3-line',
            'message': 'ri-chat-3-line',
            'email': 'ri-mail-line',
            'notification': 'ri-notification-line',

            // Documents & Reports
            'document': 'ri-file-text-line',
            'report': 'ri-bar-chart-line',
            'analytics': 'ri-pie-chart-line',
            'statistics': 'ri-line-chart-line',

            // Settings & Admin
            'settings': 'ri-settings-3-line',
            'admin': 'ri-admin-line',
            'configuration': 'ri-tools-line',
            'system': 'ri-computer-line',

            // Assets & Resources
            'asset': 'ri-building-line',
            'property': 'ri-home-gear-line',
            'inventory': 'ri-stack-line',
            'resource': 'ri-archive-line',

            // Events & Calendar
            'event': 'ri-calendar-event-line',
            'calendar': 'ri-calendar-line',
            'schedule': 'ri-time-line',

            // Education & Training
            'education': 'ri-book-line',
            'training': 'ri-graduation-cap-line',
            'learning': 'ri-booklet-line',

            // Compliance
            'compliance': 'ri-shield-check-line',
            'security': 'ri-shield-star-line',
            'audit': 'ri-file-shield-line'
        };

        const nameLower = name.toLowerCase();

        // Check for exact or partial matches
        for (const [key, icon] of Object.entries(iconMap)) {
            if (nameLower.includes(key)) {
                return icon;
            }
        }

        // Default icon if no match
        return 'ri-stack-line';
    }

    // ========================================================================
    // MODAL OPERATIONS
    // ========================================================================

    function openCreateModal() {
        console.log('➕ Opening create module group modal');

        // Reset form
        document.getElementById('groupModalTitle').textContent = 'Create Module Group';
        document.getElementById('moduleGroupForm').reset();
        document.getElementById('groupId').value = '';
        document.getElementById('groupActive').checked = true;

        // Set default territory scope to current filter
        if (currentTerritoryLevel) {
            document.getElementById('groupTerritoryScope').value = currentTerritoryLevel;
        }

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('moduleGroupModal'));
        modal.show();
    }

    async function openEditModal(groupId) {
        console.log('✏️ Opening edit module group modal for ID:', groupId);

        try {
            // Fetch group details
            const response = await APIHandler.getModuleGroup(groupId);

            if (response.success && response.data) {
                const group = response.data;

                // Populate modal fields
                document.getElementById('groupId').value = group.id;
                document.getElementById('groupName').value = group.name;
                document.getElementById('groupIcon').value = group.icon || '';
                document.getElementById('groupTerritoryScope').value = group.territory_scope;
                document.getElementById('groupOrderNumber').value = group.order || ''; // Backend uses 'order' not 'order_number'
                document.getElementById('groupDescription').value = group.description || '';
                document.getElementById('groupActive').checked = group.is_active;

                // Set modal title
                document.getElementById('groupModalTitle').textContent = 'Edit Module Group';

                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('moduleGroupModal'));
                modal.show();
            } else {
                Toast.error('Failed to load module group details');
            }
        } catch (error) {
            console.error('❌ Error loading module group:', error);
            Toast.error('Failed to load module group');
        }
    }

    async function saveGroup() {
        const saveBtn = document.getElementById('saveGroupBtn');
        const originalBtnText = saveBtn.innerHTML;

        try {
            // Disable button and show loading state
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';

            const groupId = document.getElementById('groupId').value;
            const groupName = document.getElementById('groupName').value;
            const orderNumber = document.getElementById('groupOrderNumber').value;
            const territoryScope = document.getElementById('groupTerritoryScope').value;
            let iconValue = document.getElementById('groupIcon').value.trim();

            // Auto-generate icon based on group name if empty
            if (!iconValue && groupName) {
                iconValue = generateIconFromName(groupName);
                console.log('🎨 Auto-generated icon:', iconValue);
            }

            const data = {
                name: groupName,
                icon: iconValue,
                territory_scope: territoryScope,
                order: parseInt(orderNumber), // Backend expects 'order' not 'order_number'
                description: document.getElementById('groupDescription').value,
                is_active: document.getElementById('groupActive').checked
            };

            console.log('📋 Group save data:', data);

            // Validate required fields
            if (!data.name || !data.territory_scope || !data.order) {
                Toast.error('Please fill in all required fields (Name, Territory, Order Number)');
                return;
            }

            console.log('💾 Saving group:', data);

            const response = groupId
                ? await APIHandler.updateModuleGroup(groupId, data)
                : await APIHandler.createModuleGroup(data);

            console.log('📦 API Response:', response);

            if (response.success) {
                Toast.success(groupId ? 'Module group updated successfully' : 'Module group created successfully');
                console.log('✅ Group saved successfully!', response.data);

                // Close modal
                bootstrap.Modal.getInstance(document.getElementById('moduleGroupModal')).hide();

                // Reload groups list
                loadGroups();
            } else {
                // Handle validation errors
                if (response.errors) {
                    const errorKeys = Object.keys(response.errors);
                    if (errorKeys.length > 0) {
                        const firstKey = errorKeys[0];
                        const firstError = response.errors[firstKey][0];
                        Toast.error(firstError);
                        console.log(`❌ Validation error (${firstKey}):`, firstError);
                    }
                } else {
                    Toast.error(response.message || 'Failed to save group');
                }
                throw new Error(response.message || 'Validation failed');
            }
        } catch (error) {
            console.error('❌ Error saving group:', error);
            if (!error.message.includes('Validation failed')) {
                Toast.error(error.message || 'Failed to save group');
            }
        } finally {
            // Re-enable button and restore text
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnText;
        }
    }

    async function deleteGroup(groupId, groupName) {
        // Show delete confirmation modal
        document.getElementById('deleteGroupName').textContent = groupName;

        const deleteModal = new bootstrap.Modal(document.getElementById('deleteGroupModal'));
        deleteModal.show();

        // Handle confirmation
        const confirmBtn = document.getElementById('confirmDeleteGroupBtn');

        // Remove old event listeners
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

        // Add new event listener
        newConfirmBtn.addEventListener('click', async function() {
            try {
                console.log('🗑️ Deleting group:', groupId);
                const response = await APIHandler.deleteModuleGroup(groupId);

                if (response.success) {
                    Toast.success('Module group deleted successfully');
                    deleteModal.hide();
                    loadGroups();
                } else {
                    Toast.error(response.message || 'Failed to delete group');
                }
            } catch (error) {
                console.error('❌ Error deleting group:', error);
                Toast.error('Failed to delete group');
            }
        });
    }

    console.log('✅ Module Groups Management module loaded');

})();
