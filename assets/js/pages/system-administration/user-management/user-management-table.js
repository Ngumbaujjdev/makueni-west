 /**
 * ============================================================================
 * USER MANAGEMENT - TABLE RENDERING & DATA FETCHING
 * ============================================================================
 * Handles table display, pagination, and data loading for all tabs
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE ACCESSORS (Dynamic access - not on load)
  // ========================================================================

  /**
   * Get STATE from main module (dynamic access)
   */
  function getState() {
    if (!window.UserManagementState) {
      console.error(
        "❌ UserManagementState not found! Main module not loaded?"
      );
      return {
        currentTab: "users",
        currentPage: 1,
        perPage: 15,
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
      };
    }
    return window.UserManagementState;
  }

  /**
   * Get cache duration
   */
  function getCacheDuration() {
    return window.CACHE_DURATION || 5 * 60 * 1000; // 5 minutes default
  }

  /**
   * Get Utils module
   */
  function getUtils() {
    if (!window.UserManagementUtils) {
      console.error("❌ UserManagementUtils not found!");
      return {
        showTableLoader: () => {},
        showTableError: () => {},
        getInitials: () => "?",
        getStatusBadge: () => "",
        getTerritoryBadge: () => "",
        getActionBadge: () => "",
        debounce: (fn) => fn,
      };
    }
    return window.UserManagementUtils;
  }

  // ========================================================================
  // TAB 1: USERS DATA & RENDERING
  // ========================================================================

  /**
   * Load Users Tab with caching
   */
  async function loadUsersTab(forceRefresh = false) {
    try {
      const STATE = getState();
      const CACHE_DURATION = getCacheDuration();
      const Utils = getUtils();

      Utils.showTableLoader("usersTableBody");

      // Check cache first
      const now = Date.now();
      const lastFetch = STATE.cache.lastFetch.users;

      if (
        !forceRefresh &&
        STATE.cache.users &&
        lastFetch &&
        now - lastFetch < CACHE_DURATION
      ) {
        console.log("📦 Using cached users data");
        renderUsersTable(STATE.cache.users);
        return;
      }

      // Build query params
      const params = new URLSearchParams({
        page: STATE.currentPage,
        per_page: STATE.perPage,
        ...STATE.filters.users,
      });

      // Remove empty params
      for (let [key, value] of params.entries()) {
        if (!value) params.delete(key);
      }

      const response = await APIHandler.getUsers(params.toString());

      if (response.success) {
        // Cache the response
        STATE.cache.users = response.data;
        STATE.cache.lastFetch.users = now;

        renderUsersTable(response.data);
      } else {
        throw new Error(response.message || "Failed to load users");
      }
    } catch (error) {
      console.error("Error loading users:", error);
      const Utils = getUtils();
      Utils.showTableError("usersTableBody", "Failed to load users");
      Toast.error(error.message || "Failed to load users");
    }
  }

  /**
   * Render Users Table
   */
  function renderUsersTable(data) {
    const Utils = getUtils();
    const tbody = document.getElementById("usersTableBody");
    if (!tbody) return;

    const { users, pagination } = data;

    if (!users || users.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="text-center py-5">
            <i class="ri-user-line fs-48 text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">No users found</p>
          </td>
        </tr>`;
      return;
    }

    // Avatar color options for alternating
    const avatarColors = [
      { bg: 'bg-primary-transparent', text: 'text-primary' },
      { bg: 'bg-secondary-transparent', text: 'text-secondary' },
      { bg: 'bg-warning-transparent', text: 'text-warning' },
      { bg: 'bg-info-transparent', text: 'text-info' },
      { bg: 'bg-success-transparent', text: 'text-success' },
    ];

    let html = "";
    users.forEach((user, index) => {
      const rowNumber =
        (pagination.current_page - 1) * pagination.per_page + index + 1;
      const statusBadge = Utils.getStatusBadge(user.status);
      const primaryAssignment = user.territorial_assignments?.[0];
      
      // Get alternating avatar color
      const avatarColor = avatarColors[index % avatarColors.length];

      // Determine activate/deactivate button
      const isActive = user.status === "active";
      const toggleButton = isActive
        ? `<button class="btn btn-warning-light" onclick="UserManagement.deactivateUser(${user.id}, '${user.full_name}')"
                    title="Deactivate User">
                <i class="ri-toggle-line text-warning"></i>
            </button>`
        : `<button class="btn btn-success-light" onclick="UserManagement.activateUser(${user.id}, '${user.full_name}')"
                    title="Activate User">
                <i class="ri-toggle-fill text-success"></i>
            </button>`;

      html += `
        <tr>
          <td>${rowNumber}</td>
          <td>
            <div class="d-flex align-items-center">
              <span class="avatar avatar-sm ${avatarColor.bg} ${avatarColor.text} rounded-circle me-2">
                ${Utils.getInitials(user.full_name)}
              </span>
              <div>
                <h6 class="mb-0 fs-14 fw-semibold">${user.full_name}</h6>
                <small class="text-muted">${user.position || "N/A"}</small>
              </div>
            </div>
          </td>
          <td>
            <div>
              <small class="d-block text-muted">
                <i class="ri-mail-line me-1 text-info"></i>${
                  user.email || "N/A"
                }
              </small>
              <small class="d-block text-muted">
                <i class="ri-phone-line me-1 text-success"></i>${
                  user.phone || "N/A"
                }
              </small>
            </div>
          </td>
          <td>
            <span class="badge bg-primary-transparent text-primary">
              ${user.employee_code}
            </span>
          </td>
          <td>
            ${
              primaryAssignment
                ? `
                <div>
                  <small class="d-block fw-semibold">${
                    primaryAssignment.role?.name || "N/A"
                  }</small>
                  <small class="text-muted">${
                    primaryAssignment.territory?.name || "N/A"
                  }</small>
                </div>
              `
                : '<span class="text-muted">No assignment</span>'
            }
          </td>
          <td>${statusBadge}</td>
          <td class="text-center">
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-primary-light" onclick="UserManagement.viewUser(${
                user.id
              })" 
                      title="View Details">
                <i class="ri-eye-line text-primary"></i>
              </button>
              <button class="btn btn-success-light" onclick="UserManagement.editUser(${
                user.id
              })"
                      title="Edit User">
                <i class="ri-edit-line text-success"></i>
              </button>
              <button class="btn btn-info-light" onclick="UserManagement.resetPassword(${
                user.id
              })"
                      title="Reset Password">
                <i class="ri-lock-password-line text-info"></i>
              </button>
              ${toggleButton}
            </div>
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    updatePagination("usersPagination", "usersPaginationInfo", pagination);
  }

  // ========================================================================
  // TAB 2: ROLES DATA & RENDERING
  // ========================================================================

  /**
   * Load Roles Tab with caching
   */
  async function loadRolesTab(forceRefresh = false) {
    try {
      const STATE = getState();
      const CACHE_DURATION = getCacheDuration();
      const Utils = getUtils();

      Utils.showTableLoader("rolesTableBody");

      const now = Date.now();
      const lastFetch = STATE.cache.lastFetch.roles;

      if (
        !forceRefresh &&
        STATE.cache.roles &&
        lastFetch &&
        now - lastFetch < CACHE_DURATION
      ) {
        console.log("📦 Using cached roles data");
        renderRolesTable(STATE.cache.roles);
        return;
      }

      const params = new URLSearchParams({
        page: STATE.currentPage,
        per_page: STATE.perPage,
        ...STATE.filters.roles,
      });

      for (let [key, value] of params.entries()) {
        if (!value) params.delete(key);
      }

      const response = await APIHandler.getRoles(params.toString());

      if (response.success) {
        STATE.cache.roles = response.data;
        STATE.cache.lastFetch.roles = now;
        renderRolesTable(response.data);
      } else {
        throw new Error(response.message || "Failed to load roles");
      }
    } catch (error) {
      console.error("Error loading roles:", error);
      const Utils = getUtils();
      Utils.showTableError("rolesTableBody", "Failed to load roles");
      Toast.error(error.message || "Failed to load roles");
    }
  }

  /**
   * Render Roles Table
   */
  function renderRolesTable(data) {
    const Utils = getUtils();
    const tbody = document.getElementById("rolesTableBody");
    if (!tbody) return;

    const { roles, pagination } = data;

    if (!roles || roles.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="text-center py-5">
            <i class="ri-shield-user-line fs-48 text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">No roles found</p>
          </td>
        </tr>`;
      return;
    }

    let html = "";
    roles.forEach((role, index) => {
      const rowNumber =
        (pagination.current_page - 1) * pagination.per_page + index + 1;
      const territoryBadge = Utils.getTerritoryBadge(role.territory_level);
      const statusBadge = role.is_active
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';

      html += `
        <tr>
          <td>${rowNumber}</td>
          <td>
            <div class="d-flex align-items-center">
              <span class="avatar avatar-sm bg-primary-transparent text-primary rounded-circle me-2">
                <i class="ri-shield-star-line"></i>
              </span>
              <div>
                <h6 class="mb-0 fs-14 fw-semibold">${role.name}</h6>
                <small class="text-muted">${
                  role.description || "No description"
                }</small>
              </div>
            </div>
          </td>
          <td>${territoryBadge}</td>
          <td>
            <span class="badge bg-info-transparent text-info">
              ${role.users_count}
            </span>
          </td>
          <td>
            <span class="badge bg-primary-transparent text-primary">
              ${role.permissions_count}
            </span>
          </td>
          <td>
            <span class="badge bg-success-transparent text-success">
              ${role.modules_count}
            </span>
          </td>
          <td>${statusBadge}</td>
          <td class="text-center">
            <div class="d-flex gap-1 justify-content-center role-actions">
              <a href="/makueni-west/diocese/settings/admin/roles/edit-permissions?id=${role.id}" 
                 class="btn btn-sm btn-icon btn-primary-light role-action-btn" 
                 title="Manage Permissions"
                 data-bs-toggle="tooltip">
                <i class="ri-key-2-line"></i>
              </a>
              <button class="btn btn-sm btn-icon btn-success-light role-action-btn" 
                      onclick="UserManagement.editRole(${role.id})"
                      title="Edit Role"
                      data-bs-toggle="tooltip">
                <i class="ri-edit-line"></i>
              </button>
              <button class="btn btn-sm btn-icon btn-info-light role-action-btn" 
                      onclick="UserManagement.viewRoleUsers(${role.id})"
                      title="View Users"
                      data-bs-toggle="tooltip">
                <i class="ri-user-line"></i>
              </button>
              <button class="btn btn-sm btn-icon btn-danger-light role-action-btn" 
                      onclick="UserManagement.deleteRole(${role.id}, '${role.name}')"
                      title="Delete Role"
                      data-bs-toggle="tooltip">
                <i class="ri-delete-bin-line"></i>
              </button>
            </div>
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    updatePagination("rolesPagination", "rolesPaginationInfo", pagination);
  }

  // ========================================================================
  // TAB 3: PERMISSIONS DATA & RENDERING
  // ========================================================================

  /**
   * Load Permissions Tab with caching
   */
  async function loadPermissionsTab(forceRefresh = false) {
    try {
      const STATE = getState();
      const CACHE_DURATION = getCacheDuration();
      const Utils = getUtils();

      Utils.showTableLoader("permissionsTableBody");

      const now = Date.now();
      const lastFetch = STATE.cache.lastFetch.permissions;

      if (
        !forceRefresh &&
        STATE.cache.permissions &&
        lastFetch &&
        now - lastFetch < CACHE_DURATION
      ) {
        console.log("📦 Using cached permissions data");
        renderPermissionsTable(STATE.cache.permissions);
        return;
      }

      const params = new URLSearchParams({
        page: STATE.currentPage,
        per_page: STATE.perPage,
        ...STATE.filters.permissions,
      });

      for (let [key, value] of params.entries()) {
        if (!value) params.delete(key);
      }

      const response = await APIHandler.getPermissions(params.toString());

      if (response.success) {
        STATE.cache.permissions = response.data;
        STATE.cache.lastFetch.permissions = now;
        renderPermissionsTable(response.data);
      } else {
        throw new Error(response.message || "Failed to load permissions");
      }
    } catch (error) {
      console.error("Error loading permissions:", error);
      const Utils = getUtils();
      Utils.showTableError(
        "permissionsTableBody",
        "Failed to load permissions"
      );
      Toast.error(error.message || "Failed to load permissions");
    }
  }

  /**
   * Render Permissions Table
   */
  function renderPermissionsTable(data) {
    const Utils = getUtils();
    const tbody = document.getElementById("permissionsTableBody");
    if (!tbody) return;

    const { permissions, pagination } = data;

    if (!permissions || permissions.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="text-center py-5">
            <i class="ri-key-2-line fs-48 text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">No permissions found</p>
          </td>
        </tr>`;
      return;
    }

    let html = "";
    permissions.forEach((permission, index) => {
      const rowNumber =
        (pagination.current_page - 1) * pagination.per_page + index + 1;
      const actionBadge = Utils.getActionBadge(permission.action);
      const territoryBadge = Utils.getTerritoryBadge(
        permission.territory_scope
      );

      html += `
        <tr>
          <td>${rowNumber}</td>
          <td>
            <span class="fw-semibold">${
              permission.formatted_name || permission.name
            }</span>
          </td>
          <td>
            <small class="text-primary">${
              permission.module?.name || "N/A"
            }</small>
          </td>
          <td>
            <small class="text-muted">${
              permission.submodule?.title || "N/A"
            }</small>
          </td>
          <td>${actionBadge}</td>
          <td>${territoryBadge}</td>
          <td class="text-center">
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-success-light" onclick="UserManagement.editPermission(${
                permission.id
              })"
                      title="Edit Permission">
                <i class="ri-edit-line text-success"></i>
              </button>
              <button class="btn btn-danger-light" onclick="UserManagement.deletePermission(${
                permission.id
              }, '${permission.name}')"
                      title="Delete Permission">
                <i class="ri-delete-bin-line text-danger"></i>
              </button>
            </div>
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    updatePagination(
      "permissionsPagination",
      "permissionsPaginationInfo",
      pagination
    );
  }

  // ========================================================================
  // TAB 4: MODULES DATA & RENDERING
  // ========================================================================

  /**
   * Load Modules Tab - Delegates to Module Management module
   */
  async function loadModulesTab(forceRefresh = false) {
    // Call the module management module
    if (window.ModuleManagement) {
      console.log('📡 Delegating to ModuleManagement');
      // Initialize event listeners (only runs once)
      window.ModuleManagement.init();
      window.ModuleManagement.loadModulesTab();
    } else {
      console.error('❌ ModuleManagement not loaded!');
      const container = document.getElementById("moduleTreeContainer");
      if (container) {
        container.innerHTML = `
          <div class="text-center py-5">
            <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
            <p class="text-danger mb-0">Module Management not loaded</p>
            <small class="text-muted">Please refresh the page</small>
          </div>`;
      }
    }
  }

  /**
   * Render Module Tree (Collapsible)
   */
  function renderModuleTree(data) {
    const container = document.getElementById("moduleTreeContainer");
    if (!container) return;

    const { module_groups } = data;

    if (!module_groups || module_groups.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5">
          <i class="ri-folder-line fs-48 text-muted mb-3 d-block"></i>
          <p class="text-muted mb-0">No modules found</p>
        </div>`;
      return;
    }

    let html = '<div class="accordion" id="moduleAccordion">';

    module_groups.forEach((group, groupIndex) => {
      const collapseId = `moduleGroup${groupIndex}`;

      html += `
        <div class="accordion-item border mb-2">
          <h2 class="accordion-header">
            <button class="accordion-button ${
              groupIndex === 0 ? "" : "collapsed"
            }" type="button" 
                    data-bs-toggle="collapse" data-bs-target="#${collapseId}">
              <i class="${
                group.icon || "ri-folder-line"
              } me-2 text-primary fs-18"></i>
              <strong>${group.name}</strong>
              <span class="badge bg-primary-transparent text-primary ms-2">
                ${group.modules_count} modules
              </span>
            </button>
          </h2>
          <div id="${collapseId}" class="accordion-collapse collapse ${
        groupIndex === 0 ? "show" : ""
      }" 
               data-bs-parent="#moduleAccordion">
            <div class="accordion-body">
              ${renderModules(group.modules)}
            </div>
          </div>
        </div>`;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  /**
   * Render Modules within a group
   */
  function renderModules(modules) {
    if (!modules || modules.length === 0) {
      return '<p class="text-muted mb-0">No modules</p>';
    }

    let html = '<ul class="list-unstyled ms-3">';

    modules.forEach((module) => {
      html += `
        <li class="mb-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <i class="${module.icon || "ri-folder-line"} me-2 text-info"></i>
              <strong>${module.name}</strong>
              <span class="badge bg-info-transparent text-info ms-2">
                ${module.submodules_count} submodules
              </span>
            </div>
            <div class="btn-group btn-group-sm">
              <button class="btn btn-success-light btn-sm" onclick="UserManagement.editModule(${
                module.id
              })"
                      title="Edit Module">
                <i class="ri-edit-line text-success"></i>
              </button>
              <button class="btn btn-primary-light btn-sm" onclick="UserManagement.addSubmodule(${
                module.id
              })"
                      title="Add Submodule">
                <i class="ri-add-line text-primary"></i>
              </button>
            </div>
          </div>
          ${
            module.submodules && module.submodules.length > 0
              ? renderSubmodules(module.submodules)
              : ""
          }
        </li>`;
    });

    html += "</ul>";
    return html;
  }

  /**
   * Render Submodules
   */
  function renderSubmodules(submodules) {
    let html = '<ul class="list-unstyled ms-4 mt-2">';

    submodules.forEach((submodule) => {
      html += `
        <li class="mb-2">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <i class="ri-file-line me-2 text-success"></i>
              <span class="text-dark">${submodule.title}</span>
              ${
                submodule.sub_submodules_count > 0
                  ? `
                  <span class="badge bg-success-transparent text-success ms-2">
                    ${submodule.sub_submodules_count} items
                  </span>
                `
                  : ""
              }
            </div>
            <div class="btn-group btn-group-sm">
              <button class="btn btn-success-light btn-sm" onclick="UserManagement.editSubmodule(${
                submodule.id
              })"
                      title="Edit Submodule">
                <i class="ri-edit-line text-success"></i>
              </button>
            </div>
          </div>
          ${
            submodule.sub_submodules && submodule.sub_submodules.length > 0
              ? renderSubSubmodules(submodule.sub_submodules)
              : ""
          }
        </li>`;
    });

    html += "</ul>";
    return html;
  }

  /**
   * Render Sub-submodules
   */
  function renderSubSubmodules(subSubmodules) {
    let html = '<ul class="list-unstyled ms-4 mt-1">';

    subSubmodules.forEach((subSub) => {
      html += `
        <li class="mb-1">
          <i class="ri-file-text-line me-2 text-warning fs-12"></i>
          <small class="text-muted">${subSub.title}</small>
        </li>`;
    });

    html += "</ul>";
    return html;
  }

  // ========================================================================
  // PAGINATION
  // ========================================================================

  /**
   * Update pagination UI
   */
  function updatePagination(paginationId, infoId, pagination) {
    // Update info text
    const infoEl = document.getElementById(infoId);
    if (infoEl) {
      infoEl.textContent = `Showing ${pagination.from || 0} to ${
        pagination.to || 0
      } of ${pagination.total || 0} entries`;
    }

    // Update pagination buttons
    const paginationEl = document.getElementById(paginationId);
    if (!paginationEl) return;

    let html = "";

    // Previous button
    html += `
      <li class="page-item ${pagination.current_page === 1 ? "disabled" : ""}">
        <a class="page-link" href="javascript:void(0);" onclick="UserManagement.goToPage(${
          pagination.current_page - 1
        })">
          <i class="ri-arrow-left-s-line"></i>
        </a>
      </li>`;

    // Page numbers
    const maxPages = 5;
    const startPage = Math.max(
      1,
      pagination.current_page - Math.floor(maxPages / 2)
    );
    const endPage = Math.min(pagination.last_page, startPage + maxPages - 1);

    for (let i = startPage; i <= endPage; i++) {
      html += `
        <li class="page-item ${i === pagination.current_page ? "active" : ""}">
          <a class="page-link" href="javascript:void(0);" onclick="UserManagement.goToPage(${i})">${i}</a>
        </li>`;
    }

    // Next button
    html += `
      <li class="page-item ${
        pagination.current_page === pagination.last_page ? "disabled" : ""
      }">
        <a class="page-link" href="javascript:void(0);" onclick="UserManagement.goToPage(${
          pagination.current_page + 1
        })">
          <i class="ri-arrow-right-s-line"></i>
        </a>
      </li>`;

    paginationEl.innerHTML = html;
  }

  // ========================================================================
  // EVENT LISTENERS
  // ========================================================================

  /**
   * Setup all event listeners with dynamic filtering
   */
  function setupEventListeners() {
    const STATE = getState();
    const Utils = getUtils();

    // USERS TAB - DYNAMIC FILTERS
    const userSearchInput = document.getElementById("userSearchInput");
    if (userSearchInput) {
      userSearchInput.addEventListener(
        "input",
        Utils.debounce(function (e) {
          STATE.filters.users.search = e.target.value;
          STATE.currentPage = 1;
          loadUsersTab(true);
        }, 500)
      );
    }

    const userTerritoryFilter = document.getElementById("userTerritoryFilter");
    if (userTerritoryFilter) {
      userTerritoryFilter.addEventListener("change", function (e) {
        STATE.filters.users.territory = e.target.value;
        STATE.currentPage = 1;
        loadUsersTab(true);
      });
    }

    const userRoleFilter = document.getElementById("userRoleFilter");
    if (userRoleFilter) {
      userRoleFilter.addEventListener("change", function (e) {
        STATE.filters.users.role = e.target.value;
        STATE.currentPage = 1;
        loadUsersTab(true);
      });
    }

    const userStatusFilter = document.getElementById("userStatusFilter");
    if (userStatusFilter) {
      userStatusFilter.addEventListener("change", function (e) {
        STATE.filters.users.status = e.target.value;
        STATE.currentPage = 1;
        loadUsersTab(true);
      });
    }

    const createUserBtn = document.getElementById("createUserBtn");
    if (createUserBtn) {
      createUserBtn.addEventListener("click", function () {
        if (typeof UserManagementModals !== "undefined") {
          UserManagementModals.showCreateUserModal();
        }
      });
    }

    // ROLES TAB - DYNAMIC FILTERS
    const roleSearchInput = document.getElementById("roleSearchInput");
    if (roleSearchInput) {
      roleSearchInput.addEventListener(
        "input",
        Utils.debounce(function (e) {
          STATE.filters.roles.search = e.target.value;
          STATE.currentPage = 1;
          loadRolesTab(true);
        }, 500)
      );
    }

    const roleTerritoryFilter = document.getElementById("roleTerritoryFilter");
    if (roleTerritoryFilter) {
      roleTerritoryFilter.addEventListener("change", function (e) {
        STATE.filters.roles.territory_level = e.target.value;
        STATE.currentPage = 1;
        loadRolesTab(true);
      });
    }

    const roleStatusFilter = document.getElementById("roleStatusFilter");
    if (roleStatusFilter) {
      roleStatusFilter.addEventListener("change", function (e) {
        STATE.filters.roles.status = e.target.value;
        STATE.currentPage = 1;
        loadRolesTab(true);
      });
    }

    // PERMISSIONS TAB - DYNAMIC FILTERS
    const permissionSearchInput = document.getElementById(
      "permissionSearchInput"
    );
    if (permissionSearchInput) {
      permissionSearchInput.addEventListener(
        "input",
        Utils.debounce(function (e) {
          STATE.filters.permissions.search = e.target.value;
          STATE.currentPage = 1;
          loadPermissionsTab(true);
        }, 500)
      );
    }

    const permissionModuleFilter = document.getElementById(
      "permissionModuleFilter"
    );
    if (permissionModuleFilter) {
      permissionModuleFilter.addEventListener("change", function (e) {
        STATE.filters.permissions.module = e.target.value;
        STATE.currentPage = 1;
        loadPermissionsTab(true);
      });
    }

    const permissionActionFilter = document.getElementById(
      "permissionActionFilter"
    );
    if (permissionActionFilter) {
      permissionActionFilter.addEventListener("change", function (e) {
        STATE.filters.permissions.action = e.target.value;
        STATE.currentPage = 1;
        loadPermissionsTab(true);
      });
    }

    const permissionTerritoryFilter = document.getElementById(
      "permissionTerritoryFilter"
    );
    if (permissionTerritoryFilter) {
      permissionTerritoryFilter.addEventListener("change", function (e) {
        STATE.filters.permissions.territory = e.target.value;
        STATE.currentPage = 1;
        loadPermissionsTab(true);
      });
    }
  }

  // ========================================================================
  // EXPOSE TABLE MODULE
  // ========================================================================

  window.UserManagementTable = {
    loadUsersTab,
    loadRolesTab,
    loadPermissionsTab,
    loadModulesTab,
    setupEventListeners,
  };
})();
