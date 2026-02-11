/**
 * Edit Role Permissions - With Proper 3-Column Structure
 * Module -> Submodule -> Sub-Submodule hierarchy
 */

// ============================================================================
// GLOBAL STATE
// ============================================================================
// ROLE_ID is defined in the PHP file inline script
let roleData = null;
let assignedPermissionIds = new Set();

// ============================================================================
// INITIALIZATION
// ============================================================================
document.addEventListener('DOMContentLoaded', async function() {
  console.log('🚀 Edit Permissions Page Loaded');
  console.log('📋 Role ID:', ROLE_ID);

  if (!ROLE_ID) {
    Toast.error('No role ID provided');
    setTimeout(() => window.location.href = '../users#roles-tab', 1500);
    return;
  }

  await loadRoleAndPermissions();
  setupEventListeners();
});

// ============================================================================
// LOAD DATA
// ============================================================================
async function loadRoleAndPermissions() {
  try {
    showLoadingState();

    const roleResponse = await APIHandler.getRole(ROLE_ID, {
      territory_filter: 'role'
    });

    console.log('📥 API Response:', roleResponse);

    if (!roleResponse.success) {
      Toast.error(roleResponse.message || 'Failed to load role');
      hideLoadingState();
      return;
    }

    roleData = roleResponse.data;
    console.log('✅ Role Data:', roleData);

    // Extract assigned permissions first
    try {
      extractAssignedPermissions(roleData.modules);
    } catch (extractError) {
      console.error('⚠️ Error extracting permissions:', extractError);
      // Continue anyway
    }
    
    // Then display role info (so counters are accurate)
    displayRoleInfo();
    renderModuleList(roleData.modules);
    
    try {
      renderPermissionMatrix(roleData.modules);
      console.log('✅ Permission matrix rendered');
    } catch (renderError) {
      console.error('❌ Error rendering permission matrix:', renderError);
      Toast.error('Failed to render permissions table');
    }
    
    hideLoadingState();

  } catch (error) {
    console.error('❌ Error loading role:', error);
    Toast.error('Failed to load role data');
    hideLoadingState();
  }
}

// ============================================================================
// EXTRACT ASSIGNED PERMISSIONS
// ============================================================================
function extractAssignedPermissions(modules) {
  assignedPermissionIds.clear();

  console.log('🔍 Extracting permissions from modules:', modules);
  console.log('📊 Total modules:', modules?.length);

  if (!modules || !Array.isArray(modules)) {
    console.error('❌ Modules is not an array!', modules);
    return;
  }

  modules.forEach((module, moduleIndex) => {
    console.log(`📦 Module ${moduleIndex + 1}:`, module.name, '| Submodules:', module.submodules?.length);

    if (!module.submodules || !Array.isArray(module.submodules)) {
      console.warn(`⚠️ Module "${module.name}" has no submodules array`);
      return;
    }

    module.submodules.forEach((submodule, subIndex) => {
      console.log(`  📁 Submodule ${subIndex + 1}:`, submodule.title);

      if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
        console.log(`    ↳ Has ${submodule.sub_submodules.length} sub-submodules`);
        
        // Sub-submodules have the permissions
        submodule.sub_submodules.forEach((subSub, subSubIndex) => {
          console.log(`      📄 Sub-Submodule ${subSubIndex + 1}:`, subSub.title);
          console.log(`         Assigned IDs:`, subSub.assigned_permission_ids);
          
          // Use the assigned_permission_ids array from backend
          if (subSub.assigned_permission_ids && Array.isArray(subSub.assigned_permission_ids)) {
            subSub.assigned_permission_ids.forEach(permId => {
              console.log(`        ✅ Adding permission ID: ${permId}`);
              assignedPermissionIds.add(permId);
            });
          }
        });
      } else {
        console.log(`    ↳ No sub-submodules`);
        console.log(`       Assigned IDs:`, submodule.assigned_permission_ids);
        
        // Submodule has the permissions - use assigned_permission_ids array
        if (submodule.assigned_permission_ids && Array.isArray(submodule.assigned_permission_ids)) {
          submodule.assigned_permission_ids.forEach(permId => {
            console.log(`      ✅ Adding permission ID: ${permId}`);
            assignedPermissionIds.add(permId);
          });
        }
      }
    });
  });

  console.log('✅ Final Assigned Permission IDs:', Array.from(assignedPermissionIds));
  console.log('📊 Total assigned permissions:', assignedPermissionIds.size);
}

// ============================================================================
// DISPLAY ROLE INFO
// ============================================================================
function displayRoleInfo() {
  document.getElementById('roleName').textContent = roleData.name || 'N/A';
  document.getElementById('territoryLevel').textContent = roleData.territory_level_name || 'N/A';
  
  // Count total and assigned permissions
  let totalPermissions = 0;
  roleData.modules.forEach(module => {
    module.submodules.forEach(submodule => {
      if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
        submodule.sub_submodules.forEach(subSub => {
          totalPermissions += subSub.permissions?.length || 0;
        });
      } else {
        totalPermissions += submodule.permissions?.length || 0;
      }
    });
  });
  
  document.getElementById('totalPermissions').textContent = totalPermissions;
  document.getElementById('assignedPermissions').textContent = assignedPermissionIds.size;
  
  console.log(`📊 Total permissions: ${totalPermissions}, Assigned: ${assignedPermissionIds.size}`);
}

// ============================================================================
// RENDER MODULE LIST (LEFT SIDEBAR)
// ============================================================================
let currentFilterModule = null; // Track currently selected module
const INITIAL_MODULE_COUNT = 5; // Show first 5 modules initially

function renderModuleList(modules) {
  const container = document.getElementById('moduleList');
  if (!container) {
    console.warn('⚠️ moduleList element not found');
    return;
  }

  let html = '';
  const showAll = modules.length <= INITIAL_MODULE_COUNT;
  
  modules.forEach((module, index) => {
    const submoduleCount = module.submodules?.length || 0;
    const isHidden = !showAll && index >= INITIAL_MODULE_COUNT;
    
    html += `
      <div class="list-group-item list-group-item-action module-filter-item ${isHidden ? 'd-none module-hidden' : ''}" 
           data-module-id="${module.id}" 
           style="cursor: pointer;">
        <div class="d-flex align-items-center">
          <i class="ri-folder-3-line fs-18 text-primary me-2"></i>
          <div class="flex-grow-1">
            <h6 class="mb-0 fw-semibold">${module.name}</h6>
            <small class="text-muted">${submoduleCount} submodule${submoduleCount !== 1 ? 's' : ''}</small>
          </div>
        </div>
      </div>
    `;
  });
  
  // Add "Show More" button if there are hidden modules
  if (!showAll) {
    const hiddenCount = modules.length - INITIAL_MODULE_COUNT;
    html += `
      <div class="list-group-item text-center" id="showMoreModules" style="cursor: pointer; background: #f8f9fa;">
        <small class="text-primary fw-semibold">
          <i class="ri-arrow-down-s-line"></i> Show ${hiddenCount} More
        </small>
      </div>
      <div class="list-group-item text-center d-none" id="showLessModules" style="cursor: pointer; background: #f8f9fa;">
        <small class="text-primary fw-semibold">
          <i class="ri-arrow-up-s-line"></i> Show Less
        </small>
      </div>
    `;
  }

  container.innerHTML = html;
  
  // Add click handlers for filtering
  container.querySelectorAll('.module-filter-item').forEach(item => {
    item.addEventListener('click', function() {
      const moduleId = parseInt(this.dataset.moduleId);
      filterByModule(moduleId);
      
      // Highlight selected module
      container.querySelectorAll('.module-filter-item').forEach(i => i.classList.remove('active'));
      this.classList.add('active');
    });
  });
  
  // Show More/Less handlers
  const showMoreBtn = document.getElementById('showMoreModules');
  const showLessBtn = document.getElementById('showLessModules');
  
  if (showMoreBtn) {
    showMoreBtn.addEventListener('click', function() {
      container.querySelectorAll('.module-hidden').forEach(item => {
        item.classList.remove('d-none');
      });
      showMoreBtn.classList.add('d-none');
      if (showLessBtn) showLessBtn.classList.remove('d-none');
    });
  }
  
  if (showLessBtn) {
    showLessBtn.addEventListener('click', function() {
      container.querySelectorAll('.module-hidden').forEach(item => {
        item.classList.add('d-none');
      });
      showLessBtn.classList.add('d-none');
      if (showMoreBtn) showMoreBtn.classList.remove('d-none');
    });
  }
  
  // Populate filter dropdown
  const filterDropdown = document.getElementById('filterModule');
  if (filterDropdown) {
    let options = '<option value="">All Modules</option>';
    modules.forEach(module => {
      options += `<option value="${module.id}">${module.name}</option>`;
    });
    filterDropdown.innerHTML = options;
  }
  
  console.log('✅ Module list rendered with', modules.length, 'modules');
}

function filterByModule(moduleId) {
  currentFilterModule = moduleId;
  const rows = document.querySelectorAll('#permissionsTableBody tr');
  
  if (!moduleId) {
    // Show all rows
    rows.forEach(row => row.style.display = '');
    console.log('🔄 Showing all rows');
    return;
  }
  
  // Show/hide rows based on data-module-id attribute
  rows.forEach(row => {
    const rowModuleId = parseInt(row.dataset.moduleId);
    if (rowModuleId === moduleId) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
  
  const module = roleData.modules.find(m => m.id === moduleId);
  console.log('🔍 Filtered to module:', module?.name, 'ID:', moduleId);
}


// ============================================================================
// RENDER PERMISSION MATRIX
// ============================================================================
function renderPermissionMatrix(modules) {
  console.log('🎨 renderPermissionMatrix called with modules:', modules);
  
  const container = document.getElementById('permissionsTableBody');
  console.log('📦 Container element:', container);
  console.log('📦 Container exists:', !!container);
  console.log('📦 Container parent:', container?.parentElement);
  
  if (!container) {
    console.error('❌ permissionsTableBody element not found!');
    return;
  }

  let html = '';
  const allActions = ['create', 'read', 'update', 'delete', 'approve', 'export'];

  modules.forEach(module => {
    // Collect all items (submodules and sub-submodules flattened)
    const items = [];
    
    console.log(`📦 Processing module: ${module.name}, submodules:`, module.submodules?.length);
    
    module.submodules.forEach(submodule => {
      console.log(`  📁 Submodule: ${submodule.title}, has sub_submodules:`, !!submodule.sub_submodules, 'count:', submodule.sub_submodules?.length);
      
      if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
        // Add each sub-submodule as a separate item
        submodule.sub_submodules.forEach(subSub => {
          console.log(`    📄 Adding sub-submodule: ${submodule.title} → ${subSub.title}`);
          items.push({
            name: `${submodule.title} → ${subSub.title}`,
            permissions: subSub.permissions || [],
            assigned_ids: subSub.assigned_permission_ids || []
          });
        });
      } else {
        // Add submodule as an item
        console.log(`    ✅ Adding submodule: ${submodule.title}, permissions:`, submodule.permissions?.length);
        items.push({
          name: submodule.title,
          permissions: submodule.permissions || [],
          assigned_ids: submodule.assigned_permission_ids || []
        });
      }
    });

    console.log(`✅ Module "${module.name}" has ${items.length} items to display`);

    let firstRow = true;

    items.forEach(item => {
      html += `<tr data-module-id="${module.id}">`;

      // Module column (only on first row with rowspan)
      if (firstRow) {
        html += `
          <td rowspan="${items.length}" class="align-middle">
            <div class="d-flex align-items-center gap-2">
              <i class="ri-${module.icon || 'folder'}-line fs-18 text-primary"></i>
              <span class="fw-semibold">${module.name}</span>
            </div>
          </td>
        `;
        firstRow = false;
      }

      // Submodule/Item name column
      html += `<td class="fw-medium">${item.name}</td>`;

      // Permission checkboxes
      html += renderPermissionCheckboxes(item.permissions, allActions);

      html += '</tr>';
    });
  });

  console.log('🎨 Generated HTML length:', html.length);
  console.log('🎨 HTML preview:', html.substring(0, 200));
  
  container.innerHTML = html;
  console.log('✅ Table HTML updated, row count:', container.querySelectorAll('tr').length);
}

// ============================================================================
// RENDER PERMISSION CHECKBOXES
// ============================================================================
function renderPermissionCheckboxes(permissions, allActions) {
  let html = '';

  // Create a map of permissions by action
  const permissionMap = {};
  if (permissions && Array.isArray(permissions)) {
    permissions.forEach(perm => {
      permissionMap[perm.action] = perm.id;
    });
  }

  // Render checkbox for each action
  allActions.forEach(action => {
    const permissionId = permissionMap[action];
    const isAssigned = permissionId && assignedPermissionIds.has(permissionId);
    const isAvailable = !!permissionId;

    html += `
      <td class="text-center">
        <div class="form-check form-switch d-flex justify-content-center">
          <input class="form-check-input permission-checkbox"
                 type="checkbox"
                 data-permission-id="${permissionId || ''}"
                 data-action="${action}"
                 ${isAssigned ? 'checked' : ''}
                 ${!isAvailable ? 'disabled' : ''}>
        </div>
      </td>
    `;
  });

  return html;
}

// ============================================================================
// EVENT LISTENERS
// ============================================================================
function setupEventListeners() {
  document.getElementById('savePermissions')?.addEventListener('click', savePermissions);
  document.getElementById('selectAllBtn')?.addEventListener('click', () => toggleAllPermissions(true));
  document.getElementById('deselectAllBtn')?.addEventListener('click', () => toggleAllPermissions(false));
}

// ============================================================================
// TOGGLE ALL PERMISSIONS
// ============================================================================
function toggleAllPermissions(checked) {
  document.querySelectorAll('.permission-checkbox:not(:disabled)').forEach(checkbox => {
    checkbox.checked = checked;
  });
  Toast.info(checked ? 'All permissions selected' : 'All permissions deselected');
}

// ============================================================================
// SAVE PERMISSIONS
// ============================================================================
async function savePermissions() {
  const saveBtn = document.getElementById('savePermissions');
  const originalText = saveBtn.innerHTML;
  
  try {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    // Collect all checked permission IDs
    const selectedPermissionIds = [];
    document.querySelectorAll('.permission-checkbox:checked:not(:disabled)').forEach(checkbox => {
      const permId = parseInt(checkbox.dataset.permissionId);
      if (permId && !isNaN(permId)) {
        selectedPermissionIds.push(permId);
      }
    });

    console.log('📤 Saving permissions:', selectedPermissionIds);

    const response = await APIHandler.updateRolePermissions(ROLE_ID, selectedPermissionIds);

    console.log('📥 Save response:', response);

    if (response.success) {
      Toast.success('Permissions updated successfully!');
      
      // Update local state
      assignedPermissionIds.clear();
      selectedPermissionIds.forEach(id => assignedPermissionIds.add(id));

      // Redirect
      setTimeout(() => {
        window.location.href = '../users#roles-tab';
      }, 1500);
    } else {
      Toast.error(response.message || 'Failed to save permissions');
    }

  } catch (error) {
    console.error('❌ Save error:', error);
    Toast.error('An error occurred while saving');
  } finally {
    saveBtn.disabled = false;
    saveBtn.innerHTML = originalText;
  }
}

// ============================================================================
// LOADING STATES
// ============================================================================
function showLoadingState() {
  const container = document.getElementById('permissionsTableBody');
  if (container) {
    container.innerHTML = `
      <tr>
        <td colspan="9" class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-3 text-muted">Loading permissions...</p>
        </td>
      </tr>
    `;
  }
}

function hideLoadingState() {
  // Replaced by actual content
}

// ============================================================================
// SAVE PERMISSIONS
// ============================================================================
async function savePermissions() {
  const saveBtn = document.getElementById('savePermissions');
  const originalText = saveBtn.innerHTML;
  
  try {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    // Collect all checked permission IDs
    const selectedPermissionIds = [];
    document.querySelectorAll('.permission-checkbox:checked:not(:disabled)').forEach(checkbox => {
      const permId = parseInt(checkbox.dataset.permissionId);
      if (permId && !isNaN(permId)) {
        selectedPermissionIds.push(permId);
      }
    });

    console.log('📤 Saving permissions:', selectedPermissionIds);

    const response = await APIHandler.updateRolePermissions(ROLE_ID, selectedPermissionIds);

    console.log('📥 Save response:', response);

    if (response.success) {
      Toast.success('Permissions updated successfully!');
      
      // Update local state
      assignedPermissionIds.clear();
      selectedPermissionIds.forEach(id => assignedPermissionIds.add(id));
      
      // Update counter
      document.getElementById('assignedPermissions').textContent = assignedPermissionIds.size;
    } else {
      Toast.error(response.message || 'Failed to save permissions');
    }

  } catch (error) {
    console.error('❌ Error saving permissions:', error);
    Toast.error('An error occurred while saving permissions');
  } finally {
    saveBtn.disabled = false;
    saveBtn.innerHTML = originalText;
  }
}

// ============================================================================
// SEARCH AND FILTER
// ============================================================================
function filterTable(searchTerm, moduleId) {
  const rows = document.querySelectorAll('#permissionsTableBody tr');
  
  rows.forEach(row => {
    const submoduleCell = row.querySelector('td:nth-child(2)');
    
    if (!submoduleCell) return;
    
    const submoduleText = submoduleCell.textContent.toLowerCase();
    
    const matchesSearch = !searchTerm || submoduleText.includes(searchTerm);
    
    // If module filter is active, check if row belongs to that module
    let matchesModule = true;
    if (moduleId) {
      const rowModuleId = parseInt(row.dataset.moduleId);
      matchesModule = rowModuleId === moduleId;
    }
    
    row.style.display = (matchesSearch && matchesModule) ? '' : 'none';
  });
}

// ============================================================================
// EVENT LISTENERS SETUP
// ============================================================================
function setupEventListeners() {
  // Save button
  const saveBtn = document.getElementById('savePermissions');
  if (saveBtn) {
    saveBtn.addEventListener('click', savePermissions);
    console.log('✅ Save button handler attached');
  } else {
    console.warn('⚠️ Save button not found');
  }
  
  // Search input
  const searchInput = document.getElementById('searchSubmodules');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase();
      filterTable(searchTerm, currentFilterModule);
    });
  }
  
  // Filter dropdown
  const filterDropdown = document.getElementById('filterModule');
  if (filterDropdown) {
    filterDropdown.addEventListener('change', function() {
      const moduleId = this.value ? parseInt(this.value) : null;
      filterByModule(moduleId);
      
      // Update sidebar selection
      const moduleItems = document.querySelectorAll('.module-filter-item');
      moduleItems.forEach(item => {
        if (moduleId && parseInt(item.dataset.moduleId) === moduleId) {
          item.classList.add('active');
        } else {
          item.classList.remove('active');
        }
      });
    });
  }
  
  // Clear filters button
  const clearBtn = document.getElementById('clearFilters');
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      // Reset search
      if (searchInput) searchInput.value = '';
      
      // Reset filter dropdown
      if (filterDropdown) filterDropdown.value = '';
      
      // Reset module filter
      currentFilterModule = null;
      
      // Remove active class from sidebar
      document.querySelectorAll('.module-filter-item').forEach(item => {
        item.classList.remove('active');
      });
      
      // Show all rows
      document.querySelectorAll('#permissionsTableBody tr').forEach(row => {
        row.style.display = '';
      });
      
      console.log('🔄 Filters cleared');
    });
  }
  
  // Select All button
  const selectAllBtn = document.getElementById('selectAllBtn');
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function() {
      const visibleCheckboxes = Array.from(document.querySelectorAll('#permissionsTableBody input[type="checkbox"]'))
        .filter(cb => cb.closest('tr').style.display !== 'none');
      
      visibleCheckboxes.forEach(cb => cb.checked = true);
      Toast.success(`Selected ${visibleCheckboxes.length} permissions`);
    });
  }
  
  // Deselect All button
  const deselectAllBtn = document.getElementById('deselectAllBtn');
  if (deselectAllBtn) {
    deselectAllBtn.addEventListener('click', function() {
      const visibleCheckboxes = Array.from(document.querySelectorAll('#permissionsTableBody input[type="checkbox"]'))
        .filter(cb => cb.closest('tr').style.display !== 'none');
      
      visibleCheckboxes.forEach(cb => cb.checked = false);
      Toast.success(`Deselected ${visibleCheckboxes.length} permissions`);
    });
  }
}

// ============================================================================
// INITIALIZATION
// ============================================================================
document.addEventListener('DOMContentLoaded', () => {
  console.log('📄 Edit Permissions Page Loaded');
  console.log('🔑 Role ID:', ROLE_ID);

  if (!ROLE_ID) {
    Toast.error('No role ID provided');
    return;
  }

  loadRoleAndPermissions();
  
  // Setup all event listeners
  setTimeout(() => {
    setupEventListeners();
  }, 500); // Small delay to ensure DOM is ready
});
