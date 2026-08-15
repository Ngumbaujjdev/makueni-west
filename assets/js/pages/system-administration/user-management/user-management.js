/**
 * ============================================================================
 * USER MANAGEMENT - MAIN ORCHESTRATOR
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Coordinates all user management functionality across tabs
 * Dependencies: Must load AFTER all submodules
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // CONFIGURATION & STATE
  // ========================================================================

  const API_BASE =
    AppConfig.API_BASE_URL;

  const STATE = {
    currentTab: "users",
    currentPage: 1,
    perPage: 10,
    filters: {
      users: { search: "", territory: "", role: "", status: "" },
      roles: { search: "", territory_level: "", status: "" },
      permissions: { search: "", module: "", action: "", territory: "" },
    },
    cache: {
      users: null,
      roles: null,
      permissions: null,
      modules: null,
      territories: null,
      lastFetch: {
        users: null,
        roles: null,
        permissions: null,
        modules: null,
      },
    },
    currentUserId: null,
    currentRoleId: null,
    authToken: null,
  };

  const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

  // Expose STATE and CACHE_DURATION for other modules
  window.UserManagementState = STATE;
  window.CACHE_DURATION = CACHE_DURATION;

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  document.addEventListener("DOMContentLoaded", function () {
    initializeUserManagement();
  });

  /**
   * Main initialization function
   */
  function initializeUserManagement() {
    try {
      console.log("🚀 USER MANAGEMENT: Starting initialization...");

      // Get auth token
      STATE.authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);

      if (!STATE.authToken) {
        console.error("❌ No auth token found");
        Toast.error("Authentication required. Please login.");
        setTimeout(() => {
          window.location.href = "/makueni-west/authentication/login";
        }, 2000);
        return;
      }

      // Setup tab listeners
      setupTabListeners();

      // Setup event listeners (from table module)
      if (
        typeof UserManagementTable !== "undefined" &&
        typeof UserManagementTable.setupEventListeners === "function"
      ) {
        UserManagementTable.setupEventListeners();
      }

      // Load initial tab (Users)
      if (
        typeof UserManagementTable !== "undefined" &&
        typeof UserManagementTable.loadUsersTab === "function"
      ) {
        UserManagementTable.loadUsersTab();
      }

      console.log("✅ USER MANAGEMENT: Initialization complete");
    } catch (error) {
      console.error("❌ Failed to initialize user management:", error);
      Toast.error("Failed to initialize page. Please refresh.");
    }
  }

  // ========================================================================
  // TAB MANAGEMENT
  // ========================================================================

  /**
   * Setup tab click listeners
   */
  function setupTabListeners() {
    const tabs = ["users", "roles", "permissions", "modules"];

    tabs.forEach((tabName) => {
      const tabButton = document.getElementById(`${tabName}-tab`);
      if (tabButton) {
        tabButton.addEventListener("click", () => {
          STATE.currentTab = tabName;
          handleTabChange(tabName);
        });
      }
    });
  }

  /**
   * Handle tab change
   */
  function handleTabChange(tabName) {
    console.log(`📋 Switching to ${tabName} tab`);

    // Reset page to 1
    STATE.currentPage = 1;

    // Delegate to table module
    if (typeof UserManagementTable === "undefined") {
      console.error("UserManagementTable module not loaded");
      return;
    }

    switch (tabName) {
      case "users":
        UserManagementTable.loadUsersTab();
        break;
      case "roles":
        UserManagementTable.loadRolesTab();
        break;
      case "permissions":
        UserManagementTable.loadPermissionsTab();
        break;
      case "modules":
        UserManagementTable.loadModulesTab();
        break;
    }
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.UserManagement = {
    // Pagination
    goToPage: function (page) {
      STATE.currentPage = page;
      
      // Force refresh to load new page data (bypass cache)
      switch (STATE.currentTab) {
        case "users":
          UserManagementTable.loadUsersTab(true); // forceRefresh = true
          break;
        case "roles":
          UserManagementTable.loadRolesTab(true);
          break;
        case "permissions":
          UserManagementTable.loadPermissionsTab(true);
          break;
        case "modules":
          UserManagementTable.loadModulesTab(true);
          break;
      }
    },

    // Users (delegate to modals module)
    viewUser: function (userId) {
      if (typeof UserManagementModals !== "undefined") {
        UserManagementModals.viewUser(userId);
      }
    },
    editUser: function (userId) {
      console.log("📝 Redirecting to edit user page:", userId);
      window.location.href = `/makueni-west/diocese/settings/admin/edit-user?id=${userId}`;
    },
    activateUser: function (userId, userName) {
      if (typeof UserManagementActions !== "undefined") {
        UserManagementActions.activateUser(userId, userName);
      }
    },
    deactivateUser: function (userId, userName) {
      if (typeof UserManagementActions !== "undefined") {
        UserManagementActions.deactivateUser(userId, userName);
      }
    },
    resetPassword: function (userId) {
      if (typeof UserManagementModals !== "undefined") {
        UserManagementModals.resetPassword(userId);
      }
    },

    // View User Modal Actions (forwarding functions)
    addAssignment: function () {
      // Get current user ID from view modal
      const userId = STATE.currentUserId;
      if (!userId) {
        Toast.error("No user selected");
        return;
      }
      Toast.info("Add assignment functionality - Coming soon!");
      // TODO: Implement add assignment modal
    },
    editUserFromView: function () {
      // Get current user ID from STATE (set by viewUser function)
      const userId = STATE.currentUserId;
      if (!userId) {
        Toast.error("No user selected");
        return;
      }

      // Close view modal
      const viewModal = document.getElementById("viewUserModal");
      if (viewModal) {
        const modal = bootstrap.Modal.getInstance(viewModal);
        if (modal) modal.hide();
      }

      // Redirect to edit page
      window.location.href = `/makueni-west/diocese/settings/admin/edit-user?id=${userId}`;
    },
    resetPasswordFromView: function () {
      // Get current user ID from view modal
      const userId = STATE.currentUserId;
      if (!userId) {
        Toast.error("No user selected");
        return;
      }
      if (typeof UserManagementModals !== "undefined") {
        UserManagementModals.resetPassword(userId);
      }
    },
    manageAssignmentsFromView: function () {
      // Get current user ID from view modal
      const userId = STATE.currentUserId;
      if (!userId) {
        Toast.error("No user selected");
        return;
      }
      Toast.info("Manage assignments functionality - Coming soon!");
      // TODO: Implement manage assignments modal
    },

    // Roles
    viewRolePermissions: async function (roleId) {
      try {
        const response = await APIHandler.getRole(roleId);

        if (response.success) {
          const role = response.data;
          PermissionMatrix.openPermissionModal(
            role.id,
            role.name,
            role.territory_level
          );
        } else {
          Toast.error(response.message || "Failed to load role details");
        }
      } catch (error) {
        console.error("Error loading role:", error);
        Toast.error("Failed to open permission assignment");
      }
    },
    editRole: function (id) {
      if (typeof RoleModals !== "undefined") {
        RoleModals.openEditRoleModal(id);
      } else {
        Toast.error("Role modals not loaded");
      }
    },
    viewRoleUsers: function (id) {
      Toast.info("View role users: " + id);
    },
    deleteRole: function (id, name) {
      Toast.confirm(`Delete role "${name}"?`, async () => {
        try {
          const response = await APIHandler.deleteRole(id);
          if (response.success) {
            Toast.success("Role deleted successfully!");
            if (typeof UserManagementTable !== "undefined") {
              await UserManagementTable.loadRolesTab(true);
            }
          } else {
            Toast.error(response.message || "Failed to delete role");
          }
        } catch (error) {
          Toast.error("Failed to delete role");
        }
      });
    },

    // Permissions
    editPermission: function (id) {
      Toast.info("Edit permission: " + id);
    },
    deletePermission: function (id, name) {
      Toast.confirm(`Delete permission "${name}"?`, async () => {
        try {
          const response = await APIHandler.deletePermission(id);
          if (response.success) {
            Toast.success("Permission deleted successfully!");
            if (typeof UserManagementTable !== "undefined") {
              await UserManagementTable.loadPermissionsTab(true);
            }
          } else {
            Toast.error(response.message || "Failed to delete permission");
          }
        } catch (error) {
          Toast.error("Failed to delete permission");
        }
      });
    },

    // Modules
    editModule: function (id) {
      Toast.info("Edit module: " + id);
    },
    addSubmodule: function (moduleId) {
      Toast.info("Add submodule to module: " + moduleId);
    },
    editSubmodule: function (id) {
      Toast.info("Edit submodule: " + id);
    },

    // Expose for other modules
    handleTabChange: handleTabChange,
  };
})();
