/**
 * ============================================================================
 * API HANDLER - USER MANAGEMENT
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Centralized API calls for:
 * - Users (GET, POST, PUT, DELETE)
 * - Roles (GET, POST, PUT, DELETE, assign permissions)
 * - Permissions (GET, POST, PUT, DELETE)
 * - Modules (GET, POST, PUT, DELETE - modules, submodules, sub-submodules)
 * - Territories (GET - for filters)
 *
 * All functions return standardized response format
 *
 * Dependencies: Constants.js
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // CONFIGURATION
  // ========================================================================

  const API_BASE =
    window.AppConfig?.API_BASE_URL || "http://localhost:8004/api";

  /**
   * Get auth token from localStorage
   */
  function getAuthToken() {
    return localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
  }

  /**
   * Get default headers for API requests
   */
  function getHeaders() {
    return {
      "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
      Accept: Constants.HEADERS.ACCEPT_JSON,
      Authorization: `Bearer ${getAuthToken()}`,
    };
  }

  /**
   * Handle API response
   */
  async function handleResponse(response) {
    try {
      const data = await response.json();
      
      // Determine success: HTTP range 200-299 AND body.success is not explicitly false
      const isSuccess = response.ok && data.success !== false;
      
      console.log(`📡 [API Debug] ${response.url} | Status: ${response.status} | Body Success: ${data.success} | Final Success: ${isSuccess}`);

      if (isSuccess) {
        return {
          success: true,
          data: data.data !== undefined ? data.data : data,
          message: data.message || "Success",
        };
      } else {
        return {
          success: false,
          message: data.message || "Request failed",
          errors: data.errors || (data.success === false ? data.errors : null),
          status: response.status,
          body: data
        };
      }
    } catch (error) {
      console.error("Response parse error:", error);
      return {
        success: false,
        message: "Failed to parse response",
        errors: error,
      };
    }
  }

  /**
   * Handle network/fetch errors
   */
  function handleError(error) {
    console.error("API Error:", error);
    return {
      success: false,
      message: Constants.MESSAGES.NETWORK_ERROR,
      errors: error,
    };
  }

  // ========================================================================
  // USERS API
  // ========================================================================

  /**
   * Get list of users with filters and pagination
   * @param {string} queryParams - URL query parameters (e.g., "page=1&per_page=15&search=john")
   */
  async function getUsers(queryParams = "") {
    try {
      const url = `${API_BASE}/users${queryParams ? "?" + queryParams : ""}`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single user by ID
   * @param {number} userId - User ID
   * @param {object} options - Optional query parameters
   * Example: { include_audits: true, include_assignments: true, include_permissions: true }
   */
  async function getUser(userId, options = {}) {
    try {
      // Build query string from options
      const queryParams = new URLSearchParams();
      
      if (options.include_audits) {
        queryParams.append('include_audits', 'true');
      }
      if (options.include_assignments) {
        queryParams.append('include_assignments', 'true');
      }
      if (options.include_permissions) {
        queryParams.append('include_permissions', 'true');
      }
      
      const queryString = queryParams.toString();
      const url = `${API_BASE}/users/${userId}${queryString ? '?' + queryString : ''}`;
      
      console.log('📡 Fetching user:', url); // Debug log

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new user
   * @param {object} userData - User data object
   */
  async function createUser(userData) {
    try {
      const response = await fetch(`${API_BASE}/users`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(userData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing user
   * @param {number} userId - User ID
   * @param {object} userData - User data object
   */
  async function updateUser(userId, userData) {
    try {
      const response = await fetch(`${API_BASE}/users/${userId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(userData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Quick status update (activate/deactivate)
   * @param {number} userId - User ID
   * @param {string} status - 'active' or 'inactive'
   */
  async function updateUserStatus(userId, status) {
    try {
      const response = await fetch(`${API_BASE}/users/${userId}/status`, {
        method: 'PATCH',
        headers: getHeaders(),
        body: JSON.stringify({ status }),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete user
   * @param {number} userId - User ID
   */
  async function deleteUser(userId) {
    try {
      const response = await fetch(`${API_BASE}/users/${userId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Reset user password
   * @param {number} userId - User ID
   * @param {object} passwordData - Password data {password, must_change_password}
   */
  async function resetUserPassword(userId, passwordData) {
    try {
      const response = await fetch(
        `${API_BASE}/users/${userId}/reset-password`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(passwordData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get user assignment history
   * @param {number} userId - User ID
   */
  async function getUserAssignmentHistory(userId) {
    try {
      const response = await fetch(
        `${API_BASE}/users/${userId}/assignment-history`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // ROLES API
  // ========================================================================

  /**
   * Get list of roles with filters and pagination
   * @param {string} queryParams - URL query parameters
   */
  async function getRoles(queryParams = "") {
    try {
      const url = `${API_BASE}/roles${queryParams ? "?" + queryParams : ""}`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single role by ID with permissions
   * @param {number} roleId - Role ID
   * @param {object} options - Optional query parameters
   * Example: { territory_filter: 'church' } - fetch modules for specific territory instead of user's territory
   */
  async function getRole(roleId, options = {}) {
    try {
      // Build query string from options
      const queryParams = new URLSearchParams();

      if (options.territory_filter) {
        queryParams.append('territory_filter', options.territory_filter);
      }

      const queryString = queryParams.toString();
      const url = `${API_BASE}/roles/${roleId}${queryString ? '?' + queryString : ''}`;

      console.log('📡 Fetching role with options:', url, options); // Debug log

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new role
   * @param {object} roleData - Role data object
   * Example: { name: 'Region Finance Officer', territory_level: 'region', description: '...', is_active: true }
   */
  async function createRole(roleData) {
    try {
      const response = await fetch(`${API_BASE}/roles`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(roleData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing role
   * @param {number} roleId - Role ID
   * @param {object} roleData - Role data object
   */
  async function updateRole(roleId, roleData) {
    try {
      const response = await fetch(`${API_BASE}/roles/${roleId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(roleData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete role
   * @param {number} roleId - Role ID
   */
  async function deleteRole(roleId) {
    try {
      const response = await fetch(`${API_BASE}/roles/${roleId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update role permissions (MAIN PERMISSION ASSIGNMENT)
   * @param {number} roleId - Role ID
   * @param {array} permissions - Array of permission objects
   * Format: [
   *   {
   *     module_id: 1,
   *     submodule_id: 2,
   *     sub_submodule_id: null,
   *     actions: ['create', 'read', 'update', 'delete']
   *   }
   * ]
   */
  async function updateRolePermissions(roleId, permissions) {
    try {
      const response = await fetch(`${API_BASE}/roles/${roleId}/permissions`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify({ permissions }),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get users assigned to a specific role
   * @param {number} roleId - Role ID
   */
  async function getRoleUsers(roleId) {
    try {
      const response = await fetch(`${API_BASE}/roles/${roleId}/users`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // PERMISSIONS API
  // ========================================================================

  /**
   * Get list of permissions with filters and pagination
   * @param {string} queryParams - URL query parameters
   * Example: "module_id=1&action=create&territory_scope=diocese&page=1&per_page=15"
   */
  async function getPermissions(queryParams = "") {
    try {
      const url = `${API_BASE}/permissions${
        queryParams ? "?" + queryParams : ""
      }`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single permission by ID
   * @param {number} permissionId - Permission ID
   */
  async function getPermission(permissionId) {
    try {
      const response = await fetch(`${API_BASE}/permissions/${permissionId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new permission
   * @param {object} permissionData - Permission data object
   * Example: {
   *   module_id: 1,
   *   submodule_id: 2,
   *   sub_submodule_id: null,
   *   action: 'create',
   *   territory_scope: 'diocese'
   * }
   */
  async function createPermission(permissionData) {
    try {
      const response = await fetch(`${API_BASE}/permissions`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(permissionData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing permission
   * @param {number} permissionId - Permission ID
   * @param {object} permissionData - Permission data object
   */
  async function updatePermission(permissionId, permissionData) {
    try {
      const response = await fetch(`${API_BASE}/permissions/${permissionId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(permissionData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete permission
   * @param {number} permissionId - Permission ID
   */
  async function deletePermission(permissionId) {
    try {
      const response = await fetch(`${API_BASE}/permissions/${permissionId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get permissions for role management (with permission IDs)
   * @param {string} territoryScope - Required territory scope filter
   */
  async function getGroupedPermissions(territoryScope) {
    try {
      if (!territoryScope) {
        throw new Error('Territory scope is required');
      }

      const url = `${API_BASE}/permissions/for-role-management?territory_scope=${territoryScope}`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // MODULES API
  // ========================================================================

  /**
   * Get list of modules with full hierarchy (module_groups with nested modules)
   * @param {string} territoryLevel - Optional territory level filter (diocese, region, subregion, church)
   */
  async function getModules(territoryLevel = null) {
    try {
      const url = territoryLevel 
        ? `${API_BASE}/modules?territory_level=${territoryLevel}`
        : `${API_BASE}/modules`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single module by ID with hierarchy
   * @param {number} moduleId - Module ID
   */
  async function getModule(moduleId) {
    try {
      const response = await fetch(`${API_BASE}/modules/${moduleId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new module
   * @param {object} moduleData - Module data object
   * Example: { name: 'Finance', icon: 'ri-money-dollar-circle-line', number: 5, description: '...', is_active: true }
   */
  async function createModule(moduleData) {
    try {
      const response = await fetch(`${API_BASE}/modules`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(moduleData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing module
   * @param {number} moduleId - Module ID
   * @param {object} moduleData - Module data object
   */
  async function updateModule(moduleId, moduleData) {
    try {
      const response = await fetch(`${API_BASE}/modules/${moduleId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(moduleData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete module
   * @param {number} moduleId - Module ID
   */
  async function deleteModule(moduleId) {
    try {
      const response = await fetch(`${API_BASE}/modules/${moduleId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get list of module groups
   * @param {string} territoryLevel - Optional territory filter (diocese, region, subregion, church)
   */
  async function getModuleGroups(territoryLevel = null) {
    try {
      // Build URL with territory filter if provided
      let url = `${API_BASE}/modules/groups`;
      if (territoryLevel) {
        url += `?territory_level=${territoryLevel}`;
      }

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single module group by ID
   * @param {number} groupId - Module Group ID
   */
  async function getModuleGroup(groupId) {
    try {
      const response = await fetch(`${API_BASE}/module-groups/${groupId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new module group
   * @param {object} groupData - Module group data object
   * Example: { name: 'Dashboard', icon: 'ri-dashboard-line', territory_scope: 'diocese', order: 1, description: '...', is_active: true }
   */
  async function createModuleGroup(groupData) {
    try {
      const response = await fetch(`${API_BASE}/module-groups`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(groupData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing module group
   * @param {number} groupId - Module Group ID
   * @param {object} groupData - Module group data object
   */
  async function updateModuleGroup(groupId, groupData) {
    try {
      const response = await fetch(`${API_BASE}/module-groups/${groupId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(groupData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete module group
   * @param {number} groupId - Module Group ID
   */
  async function deleteModuleGroup(groupId) {
    try {
      const response = await fetch(`${API_BASE}/module-groups/${groupId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // SUBMODULES API
  // ========================================================================

  /**
   * Get submodules for a specific module
   * @param {number} moduleId - Module ID
   */
  async function getSubmodules(moduleId) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single submodule details
   * @param {number} submoduleId - Submodule ID
   */
  async function getSubmodule(submoduleId) {
    try {
      const response = await fetch(`${API_BASE}/submodules/${submoduleId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new submodule under a module
   * @param {number} moduleId - Module ID
   * @param {object} submoduleData - Submodule data object
   * Example: { title: 'Budget Management', path: '/finance/budget', description: '...', is_active: true }
   */
  async function createSubmodule(moduleId, submoduleData) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(submoduleData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   * @param {object} submoduleData - Submodule data object
   */
  async function updateSubmodule(moduleId, submoduleId, submoduleData) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}`,
        {
          method: Constants.HTTP_METHODS.PUT,
          headers: getHeaders(),
          body: JSON.stringify(submoduleData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   */
  async function deleteSubmodule(moduleId, submoduleId) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}`,
        {
          method: Constants.HTTP_METHODS.DELETE,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // SUB-SUBMODULES API
  // ========================================================================

  /**
   * Get sub-submodules for a specific submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   */
  async function getSubSubmodules(moduleId, submoduleId) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}/sub-submodules`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get a single sub-submodule by ID
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   * @param {number} subSubmoduleId - Sub-submodule ID
   */
  async function getSubSubmodule(moduleId, submoduleId, subSubmoduleId) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}/sub-submodules/${subSubmoduleId}`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new sub-submodule under a submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   * @param {object} subSubmoduleData - Sub-submodule data object
   */
  async function createSubSubmodule(moduleId, submoduleId, subSubmoduleData) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}/sub-submodules`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify(subSubmoduleData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing sub-submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   * @param {number} subSubmoduleId - Sub-submodule ID
   * @param {object} subSubmoduleData - Sub-submodule data object
   */
  async function updateSubSubmodule(
    moduleId,
    submoduleId,
    subSubmoduleId,
    subSubmoduleData
  ) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}/sub-submodules/${subSubmoduleId}`,
        {
          method: Constants.HTTP_METHODS.PUT,
          headers: getHeaders(),
          body: JSON.stringify(subSubmoduleData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete sub-submodule
   * @param {number} moduleId - Module ID
   * @param {number} submoduleId - Submodule ID
   * @param {number} subSubmoduleId - Sub-submodule ID
   */
  async function deleteSubSubmodule(moduleId, submoduleId, subSubmoduleId) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/submodules/${submoduleId}/sub-submodules/${subSubmoduleId}`,
        {
          method: Constants.HTTP_METHODS.DELETE,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // MODULE DRAG-AND-DROP API
  // ========================================================================

  /**
   * Update module order number (for drag-and-drop reordering)
   * @param {number} moduleId - Module ID
   * @param {number} newNumber - New order number
   */
  async function updateModuleOrder(moduleId, newNumber) {
    try {
      const response = await fetch(
        `${API_BASE}/modules/${moduleId}/order`,
        {
          method: 'PATCH',
          headers: getHeaders(),
          body: JSON.stringify({ number: newNumber }),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Move submodule to different module (for drag-and-drop between modules)
   * @param {number} submoduleId - Submodule ID
   * @param {number} newModuleId - New parent module ID
   */
  async function moveSubmodule(submoduleId, newModuleId) {
    try {
      const response = await fetch(
        `${API_BASE}/submodules/${submoduleId}/move`,
        {
          method: 'PATCH',
          headers: getHeaders(),
          body: JSON.stringify({ new_module_id: newModuleId }),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // TERRITORIES API
  // ========================================================================

  /**
   * Get list of territories with filters
   * @param {string} queryParams - URL query parameters
   * Example: "territory_type=region&parent_id=1"
   */
  async function getTerritories(queryParams = "") {
    try {
      const url = `${API_BASE}/territories${
        queryParams ? "?" + queryParams : ""
      }`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get single territory by ID
   * @param {number} territoryId - Territory ID
   */
  async function getTerritory(territoryId) {
    try {
      const response = await fetch(`${API_BASE}/territories/${territoryId}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get complete territory hierarchy tree
   */
  async function getTerritoryHierarchy() {
    try {
      const response = await fetch(`${API_BASE}/territories/hierarchy/tree`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get territories by type
   * @param {string} type - Territory type (diocese, region, subregion, church)
   */
  async function getTerritoriesByType(type) {
    try {
      const response = await fetch(
        `${API_BASE}/territories/by-type/list?type=${type}`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new territory
   * @param {object} territoryData - Territory data object
   */
  async function createTerritory(territoryData) {
    try {
      const response = await fetch(`${API_BASE}/territories`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(territoryData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing territory
   * @param {number} territoryId - Territory ID
   * @param {object} territoryData - Territory data object
   */
  async function updateTerritory(territoryId, territoryData) {
    try {
      const response = await fetch(`${API_BASE}/territories/${territoryId}`, {
        method: Constants.HTTP_METHODS.PUT,
        headers: getHeaders(),
        body: JSON.stringify(territoryData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete territory
   * @param {number} territoryId - Territory ID
   */
  async function deleteTerritory(territoryId) {
    try {
      const response = await fetch(`${API_BASE}/territories/${territoryId}`, {
        method: Constants.HTTP_METHODS.DELETE,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // USER TERRITORIAL ASSIGNMENTS API
  // ========================================================================

  /**
   * Get list of user assignments with filters
   * @param {string} queryParams - URL query parameters
   */
  async function getUserAssignments(queryParams = "") {
    try {
      const url = `${API_BASE}/user-assignments${
        queryParams ? "?" + queryParams : ""
      }`;

      const response = await fetch(url, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Create new user territorial assignment
   * @param {object} assignmentData - Assignment data object
   * Example: {
   *   user_id: 1,
   *   territory_id: 5,
   *   role_id: 2,
   *   assignment_type: 'primary',
   *   is_primary: true
   * }
   */
  async function createUserAssignment(assignmentData) {
    try {
      const response = await fetch(`${API_BASE}/user-assignments`, {
        method: Constants.HTTP_METHODS.POST,
        headers: getHeaders(),
        body: JSON.stringify(assignmentData),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get assignments for a specific user
   * @param {number} userId - User ID
   */
  async function getUserAssignmentsByUser(userId) {
    try {
      const response = await fetch(
        `${API_BASE}/user-assignments/user/${userId}`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get assignments for a specific territory
   * @param {number} territoryId - Territory ID
   */
  async function getTerritoryAssignments(territoryId) {
    try {
      const response = await fetch(
        `${API_BASE}/user-assignments/territory/${territoryId}`,
        {
          method: Constants.HTTP_METHODS.GET,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Switch user's primary assignment
   * @param {number} userId - User ID
   * @param {number} assignmentId - Assignment ID to make primary
   */
  async function switchPrimaryAssignment(userId, assignmentId) {
    try {
      const response = await fetch(
        `${API_BASE}/user-assignments/user/${userId}/switch-primary`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: getHeaders(),
          body: JSON.stringify({ new_primary_assignment_id: assignmentId }),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Update existing assignment
   * @param {number} assignmentId - Assignment ID
   * @param {object} assignmentData - Assignment data object
   */
  async function updateUserAssignment(assignmentId, assignmentData) {
    try {
      const response = await fetch(
        `${API_BASE}/user-assignments/${assignmentId}`,
        {
          method: Constants.HTTP_METHODS.PUT,
          headers: getHeaders(),
          body: JSON.stringify(assignmentData),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Delete user assignment
   * @param {number} assignmentId - Assignment ID
   */
  async function deleteUserAssignment(assignmentId) {
    try {
      const response = await fetch(
        `${API_BASE}/user-assignments/${assignmentId}`,
        {
          method: Constants.HTTP_METHODS.DELETE,
          headers: getHeaders(),
        }
      );

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // DIOCESE/REGION/CHURCH API
  // ========================================================================

  /**
   * Get all dioceses
   */
  async function getDioceses(queryParams = '') {
    try {
      const response = await fetch(`${API_BASE}/dioceses?${queryParams}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get all regions
   */
  async function getRegions(queryParams = '') {
    try {
      const response = await fetch(`${API_BASE}/regions?${queryParams}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  /**
   * Get all churches
   */
  async function getChurches(queryParams = '') {
    try {
      const response = await fetch(`${API_BASE}/churches?${queryParams}`, {
        method: Constants.HTTP_METHODS.GET,
        headers: getHeaders(),
      });

      return await handleResponse(response);
    } catch (error) {
      return handleError(error);
    }
  }

  // ========================================================================
  // PUBLIC API - Expose all functions
  // ========================================================================

  window.APIHandler = {
    // Users
    getUsers,
    getUser,
    createUser,
    updateUser,
    updateUserStatus, // Quick status toggle
    deleteUser,
    resetUserPassword,
    getUserAssignmentHistory,

    // Roles
    getRoles,
    getRole,
    createRole,
    updateRole,
    deleteRole,
    updateRolePermissions,
    getRoleUsers,

    // Permissions
    getPermissions,
    getPermission,
    createPermission,
    updatePermission,
    deletePermission,
    getGroupedPermissions,

    // Modules
    getModules,
    getModule,
    createModule,
    updateModule,
    deleteModule,
    updateModuleOrder,      // Drag-and-drop reordering
    moveSubmodule,          // Drag-and-drop between modules

    // Module Groups
    getModuleGroups,        // Get all groups with optional territory filter
    getModuleGroup,         // Get single group by ID
    createModuleGroup,      // Create new module group
    updateModuleGroup,      // Update existing module group
    deleteModuleGroup,      // Delete module group

    // Submodules
    getSubmodules,
    getSubmodule,
    createSubmodule,
    updateSubmodule,
    deleteSubmodule,

    // Sub-submodules
    getSubSubmodules,
    getSubSubmodule,
    createSubSubmodule,
    updateSubSubmodule,
    deleteSubSubmodule,

    // Territories
    getTerritories,
    getTerritory,
    getTerritoryHierarchy,
    getTerritoriesByType,
    createTerritory,
    updateTerritory,
    deleteTerritory,

    // Diocese/Region/Church
    getDioceses,
    getRegions,
    getChurches,

    // User Assignments
    getUserAssignments,
    createUserAssignment,
    getUserAssignmentsByUser,
    getTerritoryAssignments,
    switchPrimaryAssignment,
    updateUserAssignment,
    deleteUserAssignment,
  };
})();
