/**
 * ============================================================================
 * ROLE MANAGEMENT - MODAL HANDLERS
 * ============================================================================
 * Handles Create Role and Edit Role modal functionality
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // MODAL REFERENCES
  // ========================================================================

  let createRoleModal = null;
  let editRoleModal = null;

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  document.addEventListener("DOMContentLoaded", function () {
    initializeRoleModals();
  });

  /**
   * Initialize role modals
   */
  function initializeRoleModals() {
    try {
      console.log("🎭 ROLE MODALS: Initializing...");

      // Get modal instances
      const createModalEl = document.getElementById("createRoleModal");
      const editModalEl = document.getElementById("editRoleModal");

      if (createModalEl) {
        createRoleModal = new bootstrap.Modal(createModalEl);
      }

      if (editModalEl) {
        editRoleModal = new bootstrap.Modal(editModalEl);
      }

      // Setup event listeners
      setupCreateRoleModal();
      setupEditRoleModal();
      setupStatusToggles();

      console.log("✅ ROLE MODALS: Initialization complete");
    } catch (error) {
      console.error("❌ Failed to initialize role modals:", error);
    }
  }

  // ========================================================================
  // CREATE ROLE MODAL
  // ========================================================================

  /**
   * Setup Create Role Modal
   */
  function setupCreateRoleModal() {
    const form = document.getElementById("createRoleForm");
    const btn = document.getElementById("createRoleBtn");

    if (btn) {
      btn.addEventListener("click", openCreateRoleModal);
    }

    if (form) {
      form.addEventListener("submit", handleCreateRoleSubmit);
    }

    // Reset form when modal is hidden
    const modalEl = document.getElementById("createRoleModal");
    if (modalEl) {
      modalEl.addEventListener("hidden.bs.modal", function () {
        resetCreateRoleForm();
      });
    }
  }

  /**
   * Open Create Role Modal
   */
  function openCreateRoleModal() {
    console.log("📝 Opening Create Role Modal");
    resetCreateRoleForm();
    if (createRoleModal) {
      createRoleModal.show();
    }
  }

  /**
   * Handle Create Role Form Submit
   */
  async function handleCreateRoleSubmit(e) {
    e.preventDefault();

    const name = document.getElementById("createRoleName").value.trim();
    const territoryLevel = document.getElementById("createRoleTerritoryLevel").value;
    const description = document.getElementById("createRoleDescription").value.trim();
    const isActive = document.getElementById("createRoleStatus").checked;

    // Validation
    if (!name) {
      Toast.error("Please enter a role name");
      return;
    }

    if (!territoryLevel) {
      Toast.error("Please select a territory level");
      return;
    }

    // Prepare data
    const roleData = {
      name: name,
      territory_level: territoryLevel,
      description: description || null,
      is_active: isActive ? 1 : 0,
    };

    console.log("📤 Creating role:", roleData);

    try {
      // Disable submit button
      const submitBtn = e.target.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="ri-loader-4-line spinner-border spinner-border-sm me-1"></i>Creating...';

      // Call API
      const response = await APIHandler.createRole(roleData);

      if (response.success) {
        Toast.success("Role created successfully!");
        
        // Close modal
        if (createRoleModal) {
          createRoleModal.hide();
        }

        // Reload roles table
        if (typeof UserManagementTable !== "undefined") {
          await UserManagementTable.loadRolesTab(true);
        }
      } else {
        Toast.error(response.message || "Failed to create role");
      }
    } catch (error) {
      console.error("Error creating role:", error);
      Toast.error("Failed to create role. Please try again.");
    } finally {
      // Re-enable submit button
      const submitBtn = e.target.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ri-add-line me-1"></i>Create Role';
      }
    }
  }

  /**
   * Reset Create Role Form
   */
  function resetCreateRoleForm() {
    const form = document.getElementById("createRoleForm");
    if (form) {
      form.reset();
    }

    // Reset status toggle to active
    const statusToggle = document.getElementById("createRoleStatus");
    if (statusToggle) {
      statusToggle.checked = true;
    }

    // Update status label
    updateStatusLabel("createRoleStatusLabel", true);
  }

  // ========================================================================
  // EDIT ROLE MODAL
  // ========================================================================

  /**
   * Setup Edit Role Modal
   */
  function setupEditRoleModal() {
    const form = document.getElementById("editRoleForm");

    if (form) {
      form.addEventListener("submit", handleEditRoleSubmit);
    }

    // Reset form when modal is hidden
    const modalEl = document.getElementById("editRoleModal");
    if (modalEl) {
      modalEl.addEventListener("hidden.bs.modal", function () {
        resetEditRoleForm();
      });
    }
  }

  /**
   * Open Edit Role Modal
   */
  async function openEditRoleModal(roleId) {
    console.log("📝 Opening Edit Role Modal for role:", roleId);

    try {
      // Show modal with loading state
      if (editRoleModal) {
        editRoleModal.show();
      }

      // Show loading in form
      showEditRoleLoading();

      // Fetch role data
      const response = await APIHandler.getRole(roleId);

      if (response.success) {
        const role = response.data;
        populateEditRoleForm(role);
      } else {
        Toast.error(response.message || "Failed to load role details");
        if (editRoleModal) {
          editRoleModal.hide();
        }
      }
    } catch (error) {
      console.error("Error loading role:", error);
      Toast.error("Failed to load role details");
      if (editRoleModal) {
        editRoleModal.hide();
      }
    }
  }

  /**
   * Populate Edit Role Form
   */
  function populateEditRoleForm(role) {
    // Basic Information
    document.getElementById("editRoleId").value = role.id;
    document.getElementById("editRoleName").value = role.name;
    document.getElementById("editRoleTerritoryLevel").value = role.territory_level;
    document.getElementById("editRoleDescription").value = role.description || "";

    // Statistics
    document.getElementById("editRoleUsersCount").textContent = role.users_count || 0;
    document.getElementById("editRolePermissionsCount").textContent = role.permissions_count || 0;
    document.getElementById("editRoleModulesCount").textContent = role.modules_count || 0;

    // Dates
    document.getElementById("editRoleCreatedAt").textContent = formatDate(role.created_at);
    document.getElementById("editRoleUpdatedAt").textContent = formatDate(role.updated_at);

    // Status
    const isActive = role.is_active === 1 || role.is_active === true;
    document.getElementById("editRoleStatus").checked = isActive;
    updateStatusLabel("editRoleStatusLabel", isActive);

    // Territory level warning
    const warningEl = document.getElementById("editTerritoryLevelWarning");
    const territorySelect = document.getElementById("editRoleTerritoryLevel");
    
    if (role.users_count > 0) {
      warningEl.innerHTML = '<i class="ri-error-warning-line me-1 text-warning"></i>Cannot change territory level - role has assigned users';
      territorySelect.disabled = true;
    } else {
      warningEl.innerHTML = '';
      territorySelect.disabled = false;
    }
  }

  /**
   * Show loading state in edit form
   */
  function showEditRoleLoading() {
    document.getElementById("editRoleName").value = "Loading...";
    document.getElementById("editRoleDescription").value = "";
    document.getElementById("editRoleUsersCount").textContent = "-";
    document.getElementById("editRolePermissionsCount").textContent = "-";
    document.getElementById("editRoleModulesCount").textContent = "-";
    document.getElementById("editRoleCreatedAt").textContent = "-";
    document.getElementById("editRoleUpdatedAt").textContent = "-";
  }

  /**
   * Handle Edit Role Form Submit
   */
  async function handleEditRoleSubmit(e) {
    e.preventDefault();

    const roleId = document.getElementById("editRoleId").value;
    const name = document.getElementById("editRoleName").value.trim();
    const territoryLevel = document.getElementById("editRoleTerritoryLevel").value;
    const description = document.getElementById("editRoleDescription").value.trim();
    const isActive = document.getElementById("editRoleStatus").checked;

    // Validation
    if (!name) {
      Toast.error("Please enter a role name");
      return;
    }

    if (!territoryLevel) {
      Toast.error("Please select a territory level");
      return;
    }

    // Prepare data
    const roleData = {
      name: name,
      territory_level: territoryLevel,
      description: description || null,
      is_active: isActive ? 1 : 0,
    };

    console.log("📤 Updating role:", roleId, roleData);

    try {
      // Disable submit button
      const submitBtn = e.target.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="ri-loader-4-line spinner-border spinner-border-sm me-1"></i>Saving...';

      // Call API
      const response = await APIHandler.updateRole(roleId, roleData);

      if (response.success) {
        Toast.success("Role updated successfully!");
        
        // Close modal
        if (editRoleModal) {
          editRoleModal.hide();
        }

        // Reload roles table
        if (typeof UserManagementTable !== "undefined") {
          await UserManagementTable.loadRolesTab(true);
        }
      } else {
        Toast.error(response.message || "Failed to update role");
      }
    } catch (error) {
      console.error("Error updating role:", error);
      Toast.error("Failed to update role. Please try again.");
    } finally {
      // Re-enable submit button
      const submitBtn = e.target.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ri-save-line me-1"></i>Save Changes';
      }
    }
  }

  /**
   * Reset Edit Role Form
   */
  function resetEditRoleForm() {
    const form = document.getElementById("editRoleForm");
    if (form) {
      form.reset();
    }

    // Clear warning
    const warningEl = document.getElementById("editTerritoryLevelWarning");
    if (warningEl) {
      warningEl.innerHTML = '';
    }

    // Re-enable territory select
    const territorySelect = document.getElementById("editRoleTerritoryLevel");
    if (territorySelect) {
      territorySelect.disabled = false;
    }
  }

  // ========================================================================
  // STATUS TOGGLES
  // ========================================================================

  /**
   * Setup status toggle listeners
   */
  function setupStatusToggles() {
    // Create Role Status Toggle
    const createStatusToggle = document.getElementById("createRoleStatus");
    if (createStatusToggle) {
      createStatusToggle.addEventListener("change", function () {
        updateStatusLabel("createRoleStatusLabel", this.checked);
      });
    }

    // Edit Role Status Toggle
    const editStatusToggle = document.getElementById("editRoleStatus");
    if (editStatusToggle) {
      editStatusToggle.addEventListener("change", function () {
        updateStatusLabel("editRoleStatusLabel", this.checked);
      });
    }
  }

  /**
   * Update status label
   */
  function updateStatusLabel(labelId, isActive) {
    const label = document.getElementById(labelId);
    if (label) {
      label.textContent = isActive ? "Active" : "Inactive";
    }
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================

  /**
   * Format date for display
   */
  function formatDate(dateString) {
    if (!dateString) return "-";
    
    try {
      const date = new Date(dateString);
      return date.toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch (error) {
      return dateString;
    }
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.RoleModals = {
    openCreateRoleModal: openCreateRoleModal,
    openEditRoleModal: openEditRoleModal,
  };
})();
