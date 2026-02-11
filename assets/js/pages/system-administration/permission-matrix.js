/**
 * ============================================================================
 * PERMISSION MATRIX - ROLE PERMISSION ASSIGNMENT
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Handles the large modal for assigning permissions to roles:
 * - LEFT PANEL: Collapsible module list (accordion)
 * - RIGHT PANEL: Permission matrix with checkboxes (Create, Read, Update, Delete)
 * - Toggle All / Clear All functionality
 * - Save permissions to backend
 *
 * Dependencies: Constants.js, Toast.js, api-handler.js
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  const STATE = {
    currentRoleId: null,
    currentRoleName: "",
    currentRoleTerritoryLevel: "",
    allModules: [],
    selectedModuleId: null,
    selectedSubmodules: [],
    permissionChanges: {}, // Track permission changes before saving
    isDirty: false, // Track if there are unsaved changes
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  /**
   * Open permission assignment modal for a role
   * @param {number} roleId - Role ID
   * @param {string} roleName - Role name (for display)
   * @param {string} territoryLevel - Role territory level
   */
  async function openPermissionModal(roleId, roleName, territoryLevel) {
    try {
      console.log(
        `📋 Opening permission modal for role: ${roleName} (ID: ${roleId})`
      );

      // Reset state
      STATE.currentRoleId = roleId;
      STATE.currentRoleName = roleName;
      STATE.currentRoleTerritoryLevel = territoryLevel;
      STATE.selectedModuleId = null;
      STATE.permissionChanges = {};
      STATE.isDirty = false;

      // Update modal title
      const modalTitle = document.getElementById("rolePermissionsModalTitle");
      if (modalTitle) {
        modalTitle.innerHTML = `
                    <i class="ri-shield-keyhole-line me-2"></i>
                    Edit Permissions: <span class="text-warning">${roleName}</span>
                `;
      }

      // Show modal
      const modalElement = document.getElementById("rolePermissionsModal");
      const modal = new bootstrap.Modal(modalElement);
      modal.show();

      // Load data
      await loadModulesAndPermissions(roleId, territoryLevel);
    } catch (error) {
      console.error("Error opening permission modal:", error);
      Toast.error("Failed to open permission assignment modal");
    }
  }

  /**
   * Load modules and current role permissions
   */
  async function loadModulesAndPermissions(roleId, territoryLevel) {
    try {
      // Show loading state
      showModuleListLoader();
      showPermissionMatrixPlaceholder();

      // Fetch role details with current permissions
      const roleResponse = await APIHandler.getRole(roleId);

      if (!roleResponse.success) {
        throw new Error(
          roleResponse.message || "Failed to load role permissions"
        );
      }

      // Fetch all modules
      const modulesResponse = await APIHandler.getModules();

      if (!modulesResponse.success) {
        throw new Error(modulesResponse.message || "Failed to load modules");
      }

      // Store data
      STATE.allModules = modulesResponse.data.module_groups || [];

      // Build permission changes map from existing permissions
      buildPermissionChangesFromRole(roleResponse.data);

      // Render module list
      renderModuleList(STATE.allModules);

      console.log("✅ Modules and permissions loaded successfully");
    } catch (error) {
      console.error("Error loading modules and permissions:", error);
      Toast.error(error.message || "Failed to load permission data");

      // Show error in UI
      showModuleListError("Failed to load modules");
    }
  }

  /**
   * Build permission changes map from existing role permissions
   */
  function buildPermissionChangesFromRole(roleData) {
    STATE.permissionChanges = {};

    if (!roleData.modules || roleData.modules.length === 0) {
      return;
    }

    roleData.modules.forEach((module) => {
      if (!module.submodules || module.submodules.length === 0) return;

      module.submodules.forEach((submodule) => {
        const key = `${module.id}_${submodule.id}_null`;

        // Check if has sub-submodules
        if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
          submodule.sub_submodules.forEach((subSub) => {
            const subKey = `${module.id}_${submodule.id}_${subSub.id}`;
            STATE.permissionChanges[subKey] = {
              module_id: module.id,
              submodule_id: submodule.id,
              sub_submodule_id: subSub.id,
              actions: subSub.permissions || [],
            };
          });
        } else {
          STATE.permissionChanges[key] = {
            module_id: module.id,
            submodule_id: submodule.id,
            sub_submodule_id: null,
            actions: submodule.permissions || [],
          };
        }
      });
    });

    console.log("📦 Built permission changes:", STATE.permissionChanges);
  }

  // ========================================================================
  // LEFT PANEL: MODULE LIST (COLLAPSIBLE)
  // ========================================================================

  /**
   * Render module list (collapsible accordion like Kusoya)
   */
  function renderModuleList(moduleGroups) {
    const container = document.getElementById("moduleListContainer");
    if (!container) return;

    if (!moduleGroups || moduleGroups.length === 0) {
      container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-folder-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No modules available</p>
                </div>`;
      return;
    }

    let html = "";

    moduleGroups.forEach((group) => {
      if (!group.modules || group.modules.length === 0) return;

      // Module Group Header (not clickable, just label)
      html += `
                <div class="mb-3">
                    <div class="bg-light p-2 rounded">
                        <i class="${
                          group.icon || "ri-folder-line"
                        } me-2 text-primary"></i>
                        <strong class="text-primary">${group.name}</strong>
                        <span class="badge bg-primary-transparent ms-2">${
                          group.modules.length
                        }</span>
                    </div>
                    <div class="ms-3 mt-2">
                        ${renderModules(group.modules)}
                    </div>
                </div>`;
    });

    container.innerHTML = html;
  }

  /**
   * Render modules within a group (clickable items)
   */
  function renderModules(modules) {
    let html = "";

    modules.forEach((module) => {
      const isSelected = STATE.selectedModuleId === module.id;
      const activeClass = isSelected
        ? "bg-primary-transparent border-primary"
        : "border";

      html += `
                <div class="card ${activeClass} mb-2 cursor-pointer" 
                     onclick="PermissionMatrix.selectModule(${module.id})"
                     style="cursor: pointer;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <i class="${
                                  module.icon || "ri-folder-line"
                                } me-2 ${
        isSelected ? "text-primary" : "text-muted"
      }"></i>
                                <strong class="${
                                  isSelected ? "text-primary" : ""
                                }">${module.name}</strong>
                            </div>
                            <div>
                                <span class="badge ${
                                  isSelected
                                    ? "bg-primary"
                                    : "bg-light text-muted"
                                }">
                                    ${module.submodules_count || 0} submodules
                                </span>
                                <i class="ri-arrow-right-s-line ms-2 ${
                                  isSelected ? "text-primary" : "text-muted"
                                }"></i>
                            </div>
                        </div>
                    </div>
                </div>`;
    });

    return html;
  }

  /**
   * Select a module and show its permissions
   */
  async function selectModule(moduleId) {
    try {
      console.log(`📂 Selected module: ${moduleId}`);

      STATE.selectedModuleId = moduleId;

      // Re-render module list to show selection
      renderModuleList(STATE.allModules);

      // Find selected module data
      let selectedModule = null;
      STATE.allModules.forEach((group) => {
        const found = group.modules.find((m) => m.id === moduleId);
        if (found) selectedModule = found;
      });

      if (!selectedModule) {
        Toast.error("Module not found");
        return;
      }

      // Update selected module name in right panel
      const moduleNameEl = document.getElementById("selectedModuleName");
      if (moduleNameEl) {
        moduleNameEl.innerHTML = `
                    <i class="${
                      selectedModule.icon || "ri-folder-line"
                    } me-1 text-success"></i>
                    ${selectedModule.name}
                `;
      }

      // Show permission matrix for this module's submodules
      renderPermissionMatrix(selectedModule);
    } catch (error) {
      console.error("Error selecting module:", error);
      Toast.error("Failed to load permissions for this module");
    }
  }

  // ========================================================================
  // RIGHT PANEL: PERMISSION MATRIX
  // ========================================================================

  /**
   * Render permission matrix (like Kusoya - table with checkboxes)
   */
  function renderPermissionMatrix(module) {
    const container = document.getElementById("permissionMatrixContainer");
    if (!container) return;

    if (!module.submodules || module.submodules.length === 0) {
      container.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="ri-checkbox-blank-circle-line fs-48 mb-3 d-block"></i>
                    <p class="mb-0">No submodules found for this module</p>
                </div>`;
      return;
    }

    let html = `
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="fw-semibold" style="width: 40%;">Submodule</th>
                            <th class="fw-semibold text-center" style="width: 15%;">
                                <i class="ri-add-circle-line me-1 text-success"></i>Create
                            </th>
                            <th class="fw-semibold text-center" style="width: 15%;">
                                <i class="ri-eye-line me-1 text-info"></i>Read
                            </th>
                            <th class="fw-semibold text-center" style="width: 15%;">
                                <i class="ri-edit-line me-1 text-warning"></i>Update
                            </th>
                            <th class="fw-semibold text-center" style="width: 15%;">
                                <i class="ri-delete-bin-line me-1 text-danger"></i>Delete
                            </th>
                        </tr>
                    </thead>
                    <tbody>`;

    module.submodules.forEach((submodule) => {
      // Check if has sub-submodules
      if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
        // Submodule header (not selectable)
        html += `
                    <tr class="bg-light">
                        <td colspan="5" class="fw-semibold">
                            <i class="ri-folder-2-line me-2 text-primary"></i>${submodule.title}
                        </td>
                    </tr>`;

        // Sub-submodules (selectable)
        submodule.sub_submodules.forEach((subSub) => {
          html += renderPermissionRow(
            module.id,
            submodule.id,
            subSub.id,
            subSub.title,
            true
          );
        });
      } else {
        // Regular submodule (no sub-submodules)
        html += renderPermissionRow(
          module.id,
          submodule.id,
          null,
          submodule.title,
          false
        );
      }
    });

    html += `
                    </tbody>
                </table>
            </div>`;

    container.innerHTML = html;
  }

  /**
   * Render a single permission row with checkboxes
   */
  function renderPermissionRow(
    moduleId,
    submoduleId,
    subSubmoduleId,
    title,
    isIndented
  ) {
    const key = `${moduleId}_${submoduleId}_${subSubmoduleId}`;
    const currentPermissions = STATE.permissionChanges[key]?.actions || [];

    const actions = ["create", "read", "update", "delete"];

    let html = `
            <tr>
                <td ${isIndented ? 'class="ps-4"' : ""}>
                    ${
                      isIndented
                        ? '<i class="ri-subtract-line me-2 text-muted"></i>'
                        : ""
                    }
                    ${title}
                </td>`;

    actions.forEach((action) => {
      const isChecked = currentPermissions.includes(action);
      const checkboxId = `perm_${key}_${action}`;

      html += `
                <td class="text-center">
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="checkbox" 
                               id="${checkboxId}"
                               ${isChecked ? "checked" : ""}
                               onchange="PermissionMatrix.togglePermission(${moduleId}, ${submoduleId}, ${subSubmoduleId}, '${action}', this.checked)">
                    </div>
                </td>`;
    });

    html += `</tr>`;
    return html;
  }

  /**
   * Toggle a single permission checkbox
   */
  function togglePermission(
    moduleId,
    submoduleId,
    subSubmoduleId,
    action,
    isChecked
  ) {
    const key = `${moduleId}_${submoduleId}_${subSubmoduleId}`;

    // Initialize if doesn't exist
    if (!STATE.permissionChanges[key]) {
      STATE.permissionChanges[key] = {
        module_id: moduleId,
        submodule_id: submoduleId,
        sub_submodule_id: subSubmoduleId,
        actions: [],
      };
    }

    // Update actions array
    if (isChecked) {
      if (!STATE.permissionChanges[key].actions.includes(action)) {
        STATE.permissionChanges[key].actions.push(action);
      }
    } else {
      STATE.permissionChanges[key].actions = STATE.permissionChanges[
        key
      ].actions.filter((a) => a !== action);
    }

    // Mark as dirty
    STATE.isDirty = true;

    console.log(`✏️ Permission toggled: ${key} - ${action} = ${isChecked}`);
  }

  // ========================================================================
  // TOGGLE ALL / CLEAR ALL
  // ========================================================================

  /**
   * Toggle all permissions for current module (Enable All)
   */
  function toggleAllPermissions() {
    if (!STATE.selectedModuleId) {
      Toast.warning("Please select a module first");
      return;
    }

    // Find current module
    let selectedModule = null;
    STATE.allModules.forEach((group) => {
      const found = group.modules.find((m) => m.id === STATE.selectedModuleId);
      if (found) selectedModule = found;
    });

    if (!selectedModule || !selectedModule.submodules) return;

    // Enable all actions for all submodules
    selectedModule.submodules.forEach((submodule) => {
      if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
        submodule.sub_submodules.forEach((subSub) => {
          const key = `${selectedModule.id}_${submodule.id}_${subSub.id}`;
          STATE.permissionChanges[key] = {
            module_id: selectedModule.id,
            submodule_id: submodule.id,
            sub_submodule_id: subSub.id,
            actions: ["create", "read", "update", "delete"],
          };
        });
      } else {
        const key = `${selectedModule.id}_${submodule.id}_null`;
        STATE.permissionChanges[key] = {
          module_id: selectedModule.id,
          submodule_id: submodule.id,
          sub_submodule_id: null,
          actions: ["create", "read", "update", "delete"],
        };
      }
    });

    STATE.isDirty = true;

    // Re-render matrix
    renderPermissionMatrix(selectedModule);

    Toast.success("All permissions enabled for this module");
  }

  /**
   * Clear all permissions for current module
   */
  function clearAllPermissions() {
    if (!STATE.selectedModuleId) {
      Toast.warning("Please select a module first");
      return;
    }

    Toast.confirm("Clear all permissions for this module?", () => {
      // Find current module
      let selectedModule = null;
      STATE.allModules.forEach((group) => {
        const found = group.modules.find(
          (m) => m.id === STATE.selectedModuleId
        );
        if (found) selectedModule = found;
      });

      if (!selectedModule || !selectedModule.submodules) return;

      // Clear all actions for all submodules
      selectedModule.submodules.forEach((submodule) => {
        if (submodule.sub_submodules && submodule.sub_submodules.length > 0) {
          submodule.sub_submodules.forEach((subSub) => {
            const key = `${selectedModule.id}_${submodule.id}_${subSub.id}`;
            if (STATE.permissionChanges[key]) {
              STATE.permissionChanges[key].actions = [];
            }
          });
        } else {
          const key = `${selectedModule.id}_${submodule.id}_null`;
          if (STATE.permissionChanges[key]) {
            STATE.permissionChanges[key].actions = [];
          }
        }
      });

      STATE.isDirty = true;

      // Re-render matrix
      renderPermissionMatrix(selectedModule);

      Toast.info("All permissions cleared for this module");
    });
  }

  // ========================================================================
  // SAVE PERMISSIONS
  // ========================================================================

  /**
   * Save permissions to backend
   */
  async function savePermissions() {
    if (!STATE.isDirty) {
      Toast.info("No changes to save");
      return;
    }

    try {
      const saveBtn = document.getElementById("saveRolePermissions");
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = `
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Saving...
                `;
      }

      // Convert permissionChanges to array format expected by backend
      const permissions = Object.values(STATE.permissionChanges).filter(
        (p) => p.actions.length > 0
      );

      console.log("💾 Saving permissions:", permissions);

      const response = await APIHandler.updateRolePermissions(
        STATE.currentRoleId,
        permissions
      );

      if (response.success) {
        Toast.success("Permissions saved successfully!");
        STATE.isDirty = false;

        // Close modal after short delay
        setTimeout(() => {
          const modalElement = document.getElementById("rolePermissionsModal");
          const modal = bootstrap.Modal.getInstance(modalElement);
          if (modal) modal.hide();

          // Reload roles tab if available
          if (
            window.UserManagement &&
            typeof window.UserManagement.loadRolesTab === "function"
          ) {
            // Give time for modal to close
            setTimeout(() => {
              if (STATE.currentTab === "roles") {
                window.UserManagement.handleTabChange("roles");
              }
            }, 300);
          }
        }, 1000);
      } else {
        throw new Error(response.message || "Failed to save permissions");
      }
    } catch (error) {
      console.error("Error saving permissions:", error);
      Toast.error(error.message || "Failed to save permissions");
    } finally {
      const saveBtn = document.getElementById("saveRolePermissions");
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = `
                    <i class="ri-save-line me-1"></i>Save Permissions
                `;
      }
    }
  }

  // ========================================================================
  // SEARCH FUNCTIONALITY
  // ========================================================================

  /**
   * Search modules in the left panel
   */
  function searchModules(searchTerm) {
    const modules = document.querySelectorAll("#moduleListContainer .card");
    const term = searchTerm.toLowerCase();

    modules.forEach((moduleCard) => {
      const moduleName = moduleCard.textContent.toLowerCase();
      if (moduleName.includes(term)) {
        moduleCard.style.display = "";
      } else {
        moduleCard.style.display = "none";
      }
    });
  }

  // Setup search input listener
  document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("moduleSearchInput");
    if (searchInput) {
      searchInput.addEventListener("input", function (e) {
        searchModules(e.target.value);
      });
    }

    // Setup button listeners
    const toggleAllBtn = document.getElementById("toggleAllPermissions");
    if (toggleAllBtn) {
      toggleAllBtn.addEventListener("click", toggleAllPermissions);
    }

    const clearAllBtn = document.getElementById("clearAllPermissions");
    if (clearAllBtn) {
      clearAllBtn.addEventListener("click", clearAllPermissions);
    }

    const saveBtn = document.getElementById("saveRolePermissions");
    if (saveBtn) {
      saveBtn.addEventListener("click", savePermissions);
    }
  });

  // ========================================================================
  // LOADING STATES
  // ========================================================================

  function showModuleListLoader() {
    const container = document.getElementById("moduleListContainer");
    if (container) {
      container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <p class="text-muted mt-2 mb-0 fs-13">Loading modules...</p>
                </div>`;
    }
  }

  function showModuleListError(message) {
    const container = document.getElementById("moduleListContainer");
    if (container) {
      container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
                    <p class="text-danger mb-0">${message}</p>
                </div>`;
    }
  }

  function showPermissionMatrixPlaceholder() {
    const container = document.getElementById("permissionMatrixContainer");
    if (container) {
      container.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="ri-arrow-left-line fs-48 mb-3 d-block"></i>
                    <p class="mb-0">Select a module from the left to view permissions</p>
                </div>`;
    }
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.PermissionMatrix = {
    openPermissionModal,
    selectModule,
    togglePermission,
    toggleAllPermissions,
    clearAllPermissions,
    savePermissions,
    searchModules,
  };
})();
