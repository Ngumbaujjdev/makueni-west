/**
 * ============================================================================
 * USER MANAGEMENT - MODAL LOGIC
 * ============================================================================
 * Handles all modal operations: Create, Edit, View, Reset Password
 * ============================================================================
 */

(function () {
  "use strict";

  // Get STATE and Utils from other modules
  const STATE = window.UserManagementState;
  const Utils = window.UserManagementUtils;

  // ========================================================================
  // CREATE USER MODAL
  // ========================================================================

  /**
   * Show Create User Modal
   */
  function showCreateUserModal() {
    const modalElement = document.getElementById("createUserModal");
    if (!modalElement) {
      Toast.error("Create User modal not found");
      return;
    }

    // Clear form
    const form = document.getElementById("createUserForm");
    if (form) {
      form.reset();
    }

    // Load dropdowns
    loadTerritoryDropdown("createUserTerritory");
    loadRoleDropdown("createUserRole");

    // Show modal
    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    // Ensure modal is scrollable after it's shown
    modalElement.addEventListener('shown.bs.modal', function () {
      const modalBody = modalElement.querySelector('.modal-body');
      if (modalBody) {
        // Ensure modal body is scrollable
        modalBody.style.overflowY = 'auto';
        modalBody.style.maxHeight = 'calc(100vh - 200px)'; // Account for header and footer
      }
    }, { once: true }); // Use once: true so it only fires once per modal show

    // Setup form interactions
    setupCreateUserFormInteractions();
  }

  /**
   * Setup Create User form interactions
   */
  function setupCreateUserFormInteractions() {
    // Generate Employee Code button
    const generateCodeBtn = document.getElementById("generateEmployeeCodeBtn");
    if (generateCodeBtn) {
      generateCodeBtn.onclick = function () {
        const codeInput = document.getElementById("createUserEmployeeCode");
        if (codeInput) {
          codeInput.value = Utils.generateEmployeeCode();
        }
      };
    }

    // Generate Password button
    const generatePasswordBtn = document.getElementById("generatePasswordBtn");
    if (generatePasswordBtn) {
      generatePasswordBtn.onclick = function () {
        const passwordInput = document.getElementById("createUserPassword");
        if (passwordInput) {
          passwordInput.value = Utils.generatePassword();
        }
      };
    }

    // Toggle Password Visibility
    const togglePasswordBtn = document.getElementById("toggleCreatePassword");
    if (togglePasswordBtn) {
      togglePasswordBtn.onclick = function () {
        const passwordInput = document.getElementById("createUserPassword");
        if (passwordInput) {
          const type = passwordInput.type === "password" ? "text" : "password";
          passwordInput.type = type;

          const icon = this.querySelector("i");
          if (icon) {
            icon.className =
              type === "password" ? "ri-eye-line" : "ri-eye-off-line";
          }
        }
      };
    }

    // Assign Territory Toggle
    const assignTerritoryToggle = document.getElementById(
      "assignTerritoryToggle"
    );
    const assignmentSection = document.getElementById(
      "territorialAssignmentSection"
    );

    if (assignTerritoryToggle && assignmentSection) {
      assignTerritoryToggle.onchange = function () {
        assignmentSection.style.display = this.checked ? "block" : "none";
      };
    }

    // Territory change - filter roles
    const territorySelect = document.getElementById("createUserTerritory");
    if (territorySelect) {
      territorySelect.onchange = function () {
        const territoryId = this.value;
        if (territoryId) {
          loadRoleDropdown("createUserRole", territoryId);
        }
      };
    }

    // Form submit
    const form = document.getElementById("createUserForm");
    if (form) {
      form.onsubmit = async function (e) {
        e.preventDefault();
        await handleCreateUserSubmit();
      };
    }
  }

  /**
   * Handle Create User form submission
   */
  async function handleCreateUserSubmit() {
    try {
      const formData = {
        firstname: document.getElementById("createUserFirstname")?.value,
        lastname: document.getElementById("createUserLastname")?.value,
        email: document.getElementById("createUserEmail")?.value,
        phone: document.getElementById("createUserPhone")?.value,
        position: document.getElementById("createUserPosition")?.value,
        username: document.getElementById("createUserUsername")?.value,
        employee_code: document.getElementById("createUserEmployeeCode")?.value,
        password: document.getElementById("createUserPassword")?.value,
        must_change_password: document.getElementById(
          "createUserMustChangePassword"
        )?.checked,
        status: document.getElementById("createUserStatus")?.value,
      };

      // Add territorial assignment if toggled
      const assignTerritory = document.getElementById(
        "assignTerritoryToggle"
      )?.checked;
      if (assignTerritory) {
        formData.territory_id = document.getElementById(
          "createUserTerritory"
        )?.value;
        formData.role_id = document.getElementById("createUserRole")?.value;
        formData.assignment_type = document.getElementById(
          "createUserAssignmentType"
        )?.value;
      }

      // Validate required fields
      if (!formData.firstname || !formData.lastname || !formData.email) {
        Toast.error("Please fill in all required fields");
        return;
      }

      // Call API
      const response = await APIHandler.createUser(formData);

      if (response.success) {
        Toast.success("User created successfully!");

        // Close modal
        const modalElement = document.getElementById("createUserModal");
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();

        // Reload users table
        if (typeof UserManagementTable !== "undefined") {
          await UserManagementTable.loadUsersTab(true);
        }
      } else {
        Toast.error(response.message || "Failed to create user");
      }
    } catch (error) {
      console.error("Error creating user:", error);
      Toast.error("Failed to create user");
    }
  }

  // ========================================================================
  // EDIT USER MODAL
  // ========================================================================

  /**
   * Show Edit User Modal
   */
  async function editUser(userId) {
    try {
      // Fetch user details
      const response = await APIHandler.getUser(userId);

      if (!response.success) {
        Toast.error(response.message || "Failed to load user");
        return;
      }

      const user = response.data;

      // Get modal
      const modalElement = document.getElementById("editUserModal");
      if (!modalElement) {
        Toast.error("Edit User modal not found");
        return;
      }

      // Populate form
      document.getElementById("editUserId").value = user.id;
      document.getElementById("editUserFirstname").value = user.firstname || "";
      document.getElementById("editUserLastname").value = user.lastname || "";
      document.getElementById("editUserEmail").value = user.email || "";
      document.getElementById("editUserPhone").value = user.phone || "";
      document.getElementById("editUserPosition").value = user.position || "";
      document.getElementById("editUserUsername").value = user.username || "";
      document.getElementById("editUserEmployeeCode").value =
        user.employee_code || "";
      document.getElementById("editUserStatus").value = user.status || "active";

      const mustChangeCheckbox = document.getElementById(
        "editUserMustChangePassword"
      );
      if (mustChangeCheckbox) {
        mustChangeCheckbox.checked = user.must_change_password || false;
      }

      // Show modal
      const modal = new bootstrap.Modal(modalElement);
      modal.show();

      // Ensure modal is scrollable after it's shown
      modalElement.addEventListener('shown.bs.modal', function () {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
          // Ensure modal body is scrollable
          modalBody.style.overflowY = 'auto';
          modalBody.style.maxHeight = 'calc(100vh - 200px)'; // Account for header and footer
        }
      }, { once: true }); // Use once: true so it only fires once per modal show

      // Setup form interactions
      setupEditUserFormInteractions();
    } catch (error) {
      console.error("Error loading user for edit:", error);
      Toast.error("Failed to load user");
    }
  }

  /**
   * Setup Edit User form interactions
   */
  function setupEditUserFormInteractions() {
    // Toggle Password Visibility
    const togglePasswordBtn = document.getElementById("toggleEditPassword");
    if (togglePasswordBtn) {
      togglePasswordBtn.onclick = function () {
        const passwordInput = document.getElementById("editUserPassword");
        if (passwordInput) {
          const type = passwordInput.type === "password" ? "text" : "password";
          passwordInput.type = type;

          const icon = this.querySelector("i");
          if (icon) {
            icon.classList.toggle("ri-eye-line");
            icon.classList.toggle("ri-eye-off-line");
          }
        }
      };
    }

    // Toggle Status Switch - Update label when toggled
    const statusToggle = document.getElementById("editUserStatus");
    const statusLabel = document.getElementById("editStatusLabel");
    if (statusToggle && statusLabel) {
      statusToggle.onchange = function () {
        if (this.checked) {
          statusLabel.textContent = "Active";
          statusLabel.classList.remove("text-danger");
          statusLabel.classList.add("text-success");
        } else {
          statusLabel.textContent = "Inactive";
          statusLabel.classList.remove("text-success");
          statusLabel.classList.add("text-danger");
        }
      };
    }

    // Form submit
    const form = document.getElementById("editUserForm");
    if (form) {
      form.onsubmit = async function (e) {
        e.preventDefault();
        await handleEditUserSubmit();
      };
    }
  }

  /**
   * Handle Edit User form submission
   */
  async function handleEditUserSubmit() {
    try {
      const userId = document.getElementById("editUserId")?.value;

      // Get status from toggle (checkbox) and convert to active/inactive
      const statusToggle = document.getElementById("editUserStatus");
      const status = statusToggle?.checked ? "active" : "inactive";

      const formData = {
        firstname: document.getElementById("editUserFirstname")?.value,
        lastname: document.getElementById("editUserLastname")?.value,
        email: document.getElementById("editUserEmail")?.value,
        phone: document.getElementById("editUserPhone")?.value,
        position: document.getElementById("editUserPosition")?.value,
        username: document.getElementById("editUserUsername")?.value,
        employee_code: document.getElementById("editUserEmployeeCode")?.value,
        status: status, // Convert checkbox to active/inactive
        must_change_password: document.getElementById(
          "editUserMustChangePassword"
        )?.checked,
      };

      // Add password only if provided
      const password = document.getElementById("editUserPassword")?.value;
      if (password) {
        formData.password = password;
      }

      // Validate
      if (!formData.firstname || !formData.lastname || !formData.email) {
        Toast.error("Please fill in all required fields");
        return;
      }

      // Call API
      const response = await APIHandler.updateUser(userId, formData);

      if (response.success) {
        Toast.success("User updated successfully!");

        // Close modal
        const modalElement = document.getElementById("editUserModal");
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();

        // Reload users table
        if (typeof UserManagementTable !== "undefined") {
          await UserManagementTable.loadUsersTab(true);
        }
      } else {
        Toast.error(response.message || "Failed to update user");
      }
    } catch (error) {
      console.error("Error updating user:", error);
      Toast.error("Failed to update user");
    }
  }

  // ========================================================================
  // VIEW USER MODAL
  // ========================================================================

  /**
   * Show View User Details Modal
   */
  async function viewUser(userId) {
    try {
      // Show loading toast
      Toast.info("Loading user data...");

      // Store current user ID in STATE for view modal actions
      const STATE = window.UserManagementState;
      if (STATE) {
        STATE.currentUserId = userId;
      }

      // Fetch user details (without audits - we'll fetch those separately)
      const response = await APIHandler.getUser(userId);

      if (!response.success) {
        Toast.error(response.message || "Failed to load user");
        return;
      }

      const user = response.data;

      // Fetch audit trail separately using the correct API
      let auditTrail = [];
      try {
        // Use the same token retrieval method as APIHandler
        const authToken = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
        
        const auditResponse = await fetch(`${window.AppConfig?.API_BASE_URL || 'http://localhost:8004/api'}/auth/user/${userId}/audits`, {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${authToken}`
          }
        });
        
        if (auditResponse.ok) {
          const auditData = await auditResponse.json();
          console.log('📦 Audit API Response:', auditData); // Debug log
          
          // The API returns { success: true, data: { audits: [...] } }
          if (auditData.success && auditData.data) {
            auditTrail = auditData.data.audits || auditData.data || [];
          } else {
            auditTrail = auditData.audits || auditData.data || auditData || [];
          }
          
          console.log('✅ Extracted audit trail:', auditTrail.length, 'items'); // Debug log
          
          // Store full audit trail in state for filtering
          if (STATE) {
            STATE.fullAuditTrail = auditTrail;
          }
        }
      } catch (auditError) {
        console.warn('Could not fetch audit trail:', auditError);
        // Continue without audit trail
      }

      // Get modal
      const modalElement = document.getElementById("viewUserModal");
      if (!modalElement) {
        Toast.error("View User modal not found");
        return;
      }

      // Populate user profile header
      populateUserProfileHeader(user);

      // Populate tabs - try multiple property names for assignments
      const assignments = user.territorial_assignments || user.active_assignments || user.assignments || [];
      populateAssignmentsTab(assignments);
      
      // Use user.permissions (not aggregated_permissions)
      populatePermissionsTab(user.permissions || []);
      populateSecurityTab(user);
      populateAuditTab(auditTrail);

      // Show modal
      const modal = new bootstrap.Modal(modalElement);
      modal.show();

      // Ensure modal is scrollable after it's shown
      modalElement.addEventListener('shown.bs.modal', function () {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
          // Ensure modal body is scrollable
          modalBody.style.overflowY = 'auto';
          modalBody.style.maxHeight = 'calc(100vh - 200px)'; // Account for header and footer
        }
      }, { once: true }); // Use once: true so it only fires once per modal show

      // Setup quick action buttons
      setupViewUserActions(userId);
    } catch (error) {
      console.error("Error viewing user:", error);
      Toast.error("Failed to load user details");
    }
  }

  /**
   * Populate user profile header
   */
  function populateUserProfileHeader(user) {
    // Avatar initials
    const avatarElement = document.querySelector("#viewUserModal .avatar");
    if (avatarElement) {
      avatarElement.textContent = Utils.getInitials(user.full_name);
    }

    // Full name
    const nameElement = document.getElementById("viewUserFullName");
    if (nameElement) {
      nameElement.textContent = user.full_name;
    }

    // Employee code badge
    const codeElement = document.getElementById("viewUserEmployeeCode");
    if (codeElement) {
      codeElement.textContent = user.employee_code;
    }

    // Status badge
    const statusElement = document.getElementById("viewUserStatus");
    if (statusElement) {
      statusElement.innerHTML = Utils.getStatusBadge(user.status);
    }

    // Email
    const emailElement = document.getElementById("viewUserEmail");
    if (emailElement) {
      emailElement.textContent = user.email || "N/A";
    }

    // Phone
    const phoneElement = document.getElementById("viewUserPhone");
    if (phoneElement) {
      phoneElement.textContent = user.phone || "N/A";
    }

    // Position
    const positionElement = document.getElementById("viewUserPosition");
    if (positionElement) {
      positionElement.textContent = user.position || "N/A";
    }

    // Last login
    const lastLoginElement = document.getElementById("viewUserLastLogin");
    if (lastLoginElement) {
      lastLoginElement.textContent = user.last_login_at
        ? Utils.formatDate(user.last_login_at)
        : "Never";
    }
  }

  /**
   * Populate Assignments tab
   */
  function populateAssignmentsTab(assignments) {
    const tbody = document.getElementById("viewAssignmentsTableBody");
    if (!tbody) return;

    if (!assignments || assignments.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="text-center text-muted py-4">
            No territorial assignments
          </td>
        </tr>`;
      return;
    }

    let html = "";
    assignments.forEach((assignment) => {
      const typeBadge =
        assignment.assignment_type === "primary"
          ? '<span class="badge bg-success">Primary</span>'
          : assignment.assignment_type === "secondary"
          ? '<span class="badge bg-info">Secondary</span>'
          : '<span class="badge bg-warning">Temporary</span>';

      html += `
        <tr>
          <td>${assignment.territory?.name || "N/A"}</td>
          <td>${assignment.role?.name || "N/A"}</td>
          <td>${typeBadge}</td>
          <td>${Utils.formatDate(assignment.assigned_at)}</td>
          <td>${assignment.assigned_by?.full_name || "System"}</td>
          <td>
            ${
              assignment.assignment_type !== "primary"
                ? `
              <button class="btn btn-sm btn-primary" 
                      onclick="UserManagementModals.switchToPrimary(${assignment.id})">
                Switch to Primary
              </button>
              <button class="btn btn-sm btn-danger" 
                      onclick="UserManagementModals.removeAssignment(${assignment.id})">
                Remove
              </button>
            `
                : '<span class="badge bg-success-transparent text-success"><i class="ri-shield-check-line me-1"></i>Primary Assignment</span>'
            }
          </td>
        </tr>`;
    });

    tbody.innerHTML = html;
  }

  /**
   * Populate Permissions tab - Show permissions from primary assignment
   */
  function populatePermissionsTab(permissions) {
    const container = document.getElementById("viewPermissionsList");
    if (!container) return;

    if (!permissions || permissions.length === 0) {
      container.innerHTML = '<p class="text-muted">No permissions assigned</p>';
      return;
    }

    // Group permissions by module
    const grouped = {};
    
    permissions.forEach(permission => {
      const parts = permission.split('.');
      const module = parts[0];
      
      if (!grouped[module]) {
        grouped[module] = [];
      }
      grouped[module].push(permission);
    });

    // Color palette for modules
    const colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark'];
    let colorIndex = 0;

    // Build styled grid
    let html = '<div class="row g-3">';
    
    for (const [module, perms] of Object.entries(grouped)) {
      const color = colors[colorIndex % colors.length];
      colorIndex++;
      
      html += `
        <div class="col-md-6 col-lg-4">
          <div class="card border-${color} shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex align-items-center mb-2">
                <span class="avatar avatar-sm bg-${color}-transparent rounded me-2">
                  <i class="ri-folder-line text-${color}"></i>
                </span>
                <h6 class="card-title mb-0 text-${color} fw-semibold">
                  ${formatModuleName(module)}
                </h6>
              </div>
              <div class="d-flex align-items-center">
                <span class="badge bg-${color}-transparent text-${color}">
                  <i class="ri-shield-check-line me-1"></i>${perms.length} permissions
                </span>
              </div>
            </div>
          </div>
        </div>`;
    }
    
    html += '</div>';
    
    // Summary with gradient background
    const summary = `
      <div class="alert alert-primary alert-dismissible fade show mb-3" role="alert">
        <div class="d-flex align-items-center">
          <i class="ri-information-line fs-18 me-2"></i>
          <div>
            <strong>${permissions.length} total permissions</strong> across 
            <strong>${Object.keys(grouped).length} modules</strong>
          </div>
        </div>
      </div>`;
    
    container.innerHTML = summary + html;
  }

  /**
   * Format module name
   */
  function formatModuleName(name) {
    return name.charAt(0).toUpperCase() + name.slice(1).replace(/([A-Z])/g, ' $1');
  }

  /**
   * Populate Security tab
   */
  function populateSecurityTab(user) {
    // Password last changed
    const pwdChangedElement = document.getElementById(
      "viewPasswordLastChanged"
    );
    if (pwdChangedElement) {
      pwdChangedElement.textContent = user.password_changed_at
        ? Utils.formatDate(user.password_changed_at)
        : "Never";
    }

    // Password expires
    const pwdExpiresElement = document.getElementById("viewPasswordExpires");
    if (pwdExpiresElement) {
      pwdExpiresElement.textContent = user.password_expires_at
        ? Utils.formatDate(user.password_expires_at)
        : "N/A";
    }

    // Must change password
    const mustChangeElement = document.getElementById("viewMustChangePassword");
    if (mustChangeElement) {
      mustChangeElement.textContent = user.must_change_password ? "Yes" : "No";
    }

    // Last login
    const lastLoginElement = document.getElementById("viewSecurityLastLogin");
    if (lastLoginElement) {
      lastLoginElement.textContent = user.last_login_at
        ? Utils.formatDate(user.last_login_at)
        : "Never";
    }

    // Failed attempts
    const failedAttemptsElement = document.getElementById("viewFailedAttempts");
    if (failedAttemptsElement) {
      failedAttemptsElement.textContent = user.failed_login_attempts || 0;
    }
  }

  /**
   * Populate Audit Trail tab with beautiful timeline design (limited to recent 5)
   */
  function populateAuditTab(auditTrail) {
    const container = document.getElementById("viewAuditTimeline");
    if (!container) return;

    // Check if auditTrail is an array and has items
    if (!Array.isArray(auditTrail) || auditTrail.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5">
          <i class="ri-history-line fs-48 text-muted mb-3 d-block"></i>
          <p class="text-muted mb-0">No audit trail available</p>
        </div>`;
      return;
    }

    // Limit to 5 most recent entries for modal view
    const recentAudits = auditTrail.slice(0, 5);
    const totalCount = auditTrail.length;

    let html = '<ul class="list-unstyled profile-timeline">';

    recentAudits.forEach((audit, index) => {
      const eventIcon = getEventIcon(audit.event);
      const eventColor = getEventColor(audit.event);

      // Format the event name for display (replace underscores with spaces)
      const eventDisplay = audit.event.replace(/_/g, ' ');

      html += `
        <li>
          <div>
            <span class="avatar avatar-sm bg-${eventColor}-transparent avatar-rounded profile-timeline-avatar">
              <i class="${eventIcon}"></i>
            </span>
            <p class="mb-2">
              <b>User</b> ${eventDisplay}
              <span class="badge bg-${eventColor} ms-2">${audit.event}</span>
              <span class="float-end fs-11 text-muted">
                <i class="ri-time-line me-1"></i>${Utils.formatDate(audit.created_at)}
              </span>
            </p>
            <p class="text-muted fs-12 mb-0">
              <i class="ri-map-pin-line me-1 text-info"></i>IP: ${audit.ip_address || "N/A"}
              ${audit.user_agent ? `<i class="ri-computer-line ms-3 me-1 text-success"></i>${truncateUserAgent(audit.user_agent)}` : ""}
            </p>
          </div>
        </li>`;
    });

    html += "</ul>";
    
    // Add "showing X of Y" message if there are more entries
    if (totalCount > 5) {
      html += `
        <div class="text-center mt-3">
          <p class="text-muted fs-12 mb-0">
            <i class="ri-information-line me-1"></i>
            Showing 5 most recent of ${totalCount} total entries
          </p>
        </div>`;
    }
    
    container.innerHTML = html;
  }

  /**
   * Get icon for audit event type
   */
  function getEventIcon(event) {
    const iconMap = {
      'user_login': 'ri-login-box-line',
      'user_logout': 'ri-logout-box-line',
      'password_changed': 'ri-lock-password-line',
      'profile_updated': 'ri-user-settings-line',
      'status_changed': 'ri-toggle-line',
      'user_created': 'ri-user-add-line',
      'user_updated': 'ri-user-line',
      'user_deleted': 'ri-user-unfollow-line',
      'updated': 'ri-edit-line'
    };
    return iconMap[event] || 'ri-information-line';
  }

  /**
   * Get color for audit event type
   */
  function getEventColor(event) {
    const colorMap = {
      'user_login': 'success',
      'user_logout': 'secondary',
      'password_changed': 'warning',
      'profile_updated': 'info',
      'status_changed': 'primary',
      'user_created': 'success',
      'user_updated': 'info',
      'user_deleted': 'danger',
      'updated': 'primary'
    };
    return colorMap[event] || 'primary';
  }

  /**
   * Truncate user agent string for display
   */
  function truncateUserAgent(userAgent) {
    if (!userAgent) return 'N/A';
    
    // Extract browser name
    if (userAgent.includes('Chrome')) return 'Chrome';
    if (userAgent.includes('Firefox')) return 'Firefox';
    if (userAgent.includes('Safari')) return 'Safari';
    if (userAgent.includes('Edge')) return 'Edge';
    
    // Fallback to truncated string
    return userAgent.length > 30 ? userAgent.substring(0, 30) + '...' : userAgent;
  }

  /**
   * Setup View User quick action buttons
   */
  function setupViewUserActions(userId) {
    // Edit Profile button
    const editBtn = document.getElementById("viewUserEditBtn");
    if (editBtn) {
      editBtn.onclick = function () {
        const modal = bootstrap.Modal.getInstance(
          document.getElementById("viewUserModal")
        );
        if (modal) modal.hide();
        editUser(userId);
      };
    }

    // Reset Password button
    const resetPwdBtn = document.getElementById("viewUserResetPasswordBtn");
    if (resetPwdBtn) {
      resetPwdBtn.onclick = function () {
        resetPassword(userId);
      };
    }
  }

  // ========================================================================
  // RESET PASSWORD MODAL
  // ========================================================================

  /**
   * Show Reset Password Modal
   */
  async function resetPassword(userId) {
    try {
      // Fetch user info
      const response = await APIHandler.getUser(userId);

      if (!response.success) {
        Toast.error("Failed to load user");
        return;
      }

      const user = response.data;

      // Get modal
      const modalElement = document.getElementById("resetPasswordModal");
      if (!modalElement) {
        Toast.error("Reset Password modal not found");
        return;
      }

      // Populate user info
      document.getElementById("resetPasswordUserId").value = user.id;
      document.getElementById("resetPasswordUserName").textContent =
        user.full_name;
      document.getElementById("resetPasswordUserEmail").textContent =
        user.email;
      document.getElementById("resetPasswordUserCode").textContent =
        user.employee_code;

      // Auto-generate password
      const generatedPassword = Utils.generatePassword();
      document.getElementById("resetGeneratedPassword").value =
        generatedPassword;

      // Show modal
      const modal = new bootstrap.Modal(modalElement);
      modal.show();

      // Ensure modal is scrollable after it's shown
      modalElement.addEventListener('shown.bs.modal', function () {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
          // Ensure modal body is scrollable
          modalBody.style.overflowY = 'auto';
          modalBody.style.maxHeight = 'calc(100vh - 200px)'; // Account for header and footer
        }
      }, { once: true }); // Use once: true so it only fires once per modal show

      // Setup form interactions
      setupResetPasswordFormInteractions();
    } catch (error) {
      console.error("Error opening reset password modal:", error);
      Toast.error("Failed to open reset password modal");
    }
  }

  /**
   * Setup Reset Password form interactions
   */
  function setupResetPasswordFormInteractions() {
    // Radio toggle (Auto-generate vs Custom)
    const autoRadio = document.getElementById("resetPasswordAuto");
    const customRadio = document.getElementById("resetPasswordCustom");
    const generatedSection = document.getElementById(
      "generatedPasswordSection"
    );
    const customSection = document.getElementById("customPasswordSection");

    if (autoRadio && customRadio) {
      autoRadio.onchange = function () {
        if (this.checked) {
          generatedSection.style.display = "block";
          customSection.style.display = "none";
        }
      };

      customRadio.onchange = function () {
        if (this.checked) {
          generatedSection.style.display = "none";
          customSection.style.display = "block";
        }
      };
    }

    // Copy password button
    const copyBtn = document.getElementById("copyPasswordBtn");
    if (copyBtn) {
      copyBtn.onclick = function () {
        const password = document.getElementById(
          "resetGeneratedPassword"
        ).value;
        Utils.copyToClipboard(password);
      };
    }

    // Form submit
    const form = document.getElementById("resetPasswordForm");
    if (form) {
      form.onsubmit = async function (e) {
        e.preventDefault();
        await handleResetPasswordSubmit();
      };
    }
  }

  /**
   * Handle Reset Password form submission
   */
  async function handleResetPasswordSubmit() {
    try {
      const userId = document.getElementById("resetPasswordUserId").value;
      const isAuto = document.getElementById("resetPasswordAuto").checked;

      let password;
      if (isAuto) {
        password = document.getElementById("resetGeneratedPassword").value;
      } else {
        password = document.getElementById("customPassword").value; // Fixed ID
        const confirmPassword = document.getElementById("customPasswordConfirm").value; // Fixed ID

        if (password !== confirmPassword) {
          Toast.error("Passwords do not match");
          return;
        }
      }

      const forceChange = document.getElementById("resetForceChange").checked;

      // Get submit button and add loading state
      const submitBtn = document.querySelector('#resetPasswordForm button[type="submit"]');
      const originalBtnHTML = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Resetting...';

      // Call API
      const response = await APIHandler.resetUserPassword(userId, {
        password: password,
        must_change_password: forceChange,
      });

      // Restore button
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnHTML;

      if (response.success) {
        Toast.success("Password reset successfully!");

        // Close modal
        const modalElement = document.getElementById("resetPasswordModal");
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();

        // Reload users table
        if (typeof UserManagementTable !== "undefined") {
          await UserManagementTable.loadUsersTab(true);
        }
      } else {
        Toast.error(response.message || "Failed to reset password");
      }
    } catch (error) {
      console.error("Error resetting password:", error);
      Toast.error("Failed to reset password");
      
      // Restore button on error
      const submitBtn = document.querySelector('#resetPasswordForm button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ri-lock-password-line me-1"></i>Reset Password';
      }
    }
  }

  // ========================================================================
  // ASSIGNMENT ACTIONS
  // ========================================================================

  /**
   * Switch assignment to primary
   */
  async function switchToPrimary(assignmentId) {
    Toast.confirm("Switch this assignment to primary?", async () => {
      try {
        const response = await APIHandler.switchPrimaryAssignment(assignmentId);

        if (response.success) {
          Toast.success("Primary assignment switched successfully!");
          // Reload assignments in view modal
          // TODO: Refresh just the assignments tab
        } else {
          Toast.error(response.message || "Failed to switch primary");
        }
      } catch (error) {
        console.error("Error switching primary:", error);
        Toast.error("Failed to switch primary assignment");
      }
    });
  }

  /**
   * Remove assignment
   */
  async function removeAssignment(assignmentId) {
    Toast.confirm(
      "Remove this assignment? User will lose access to this territory.",
      async () => {
        try {
          const response = await APIHandler.deleteAssignment(assignmentId);

          if (response.success) {
            Toast.success("Assignment removed successfully!");
            // Reload assignments in view modal
            // TODO: Refresh just the assignments tab
          } else {
            Toast.error(response.message || "Failed to remove assignment");
          }
        } catch (error) {
          console.error("Error removing assignment:", error);
          Toast.error("Failed to remove assignment");
        }
      }
    );
  }

  // ========================================================================
  // DROPDOWN LOADERS
  // ========================================================================

  /**
   * Load territories into dropdown
   */
  async function loadTerritoryDropdown(selectId) {
    try {
      const select = document.getElementById(selectId);
      if (!select) return;

      const response = await APIHandler.getTerritories();

      if (response.success) {
        const territories = response.data;

        let html = '<option value="">Select Territory</option>';

        territories.forEach((territory) => {
          const indent = "  ".repeat(territory.level || 0);
          html += `<option value="${territory.id}">${indent}${territory.name}</option>`;
        });

        select.innerHTML = html;
      }
    } catch (error) {
      console.error("Error loading territories:", error);
    }
  }

  /**
   * Load roles into dropdown (optionally filtered by territory)
   */
  async function loadRoleDropdown(selectId, territoryId = null) {
    try {
      const select = document.getElementById(selectId);
      if (!select) return;

      const params = territoryId ? `?territory_id=${territoryId}` : "";
      const response = await APIHandler.getRoles(params);

      if (response.success) {
        const roles = response.data.roles || response.data;

        let html = '<option value="">Select Role</option>';

        roles.forEach((role) => {
          html += `<option value="${role.id}">${role.name}</option>`;
        });

        select.innerHTML = html;
      }
    } catch (error) {
      console.error("Error loading roles:", error);
    }
  }

  // ========================================================================
  // ASSIGNMENT ACTIONS (Switch to Primary & Remove)
  // ========================================================================

  /**
   * Switch a secondary assignment to primary
   * @param {number} assignmentId - Assignment ID to switch to primary
   */
  async function switchToPrimary(assignmentId) {
    try {
      // Get current user ID from state
      const STATE = window.UserManagementState;
      const userId = STATE?.currentUserId;

      if (!userId) {
        Toast.error("User ID not found");
        return;
      }

      Toast.info("Switching assignment to primary...");

      // Call API to switch primary assignment
      const response = await APIHandler.switchPrimaryAssignment(userId, assignmentId);

      if (response.success) {
        Toast.success("Assignment switched to primary successfully!");
        
        // Close current modal properly before reopening
        const modalElement = document.getElementById("viewUserModal");
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
          modal.hide();
          
          // Wait for modal to fully close before reopening
          modalElement.addEventListener('hidden.bs.modal', async function() {
            await viewUser(userId);
          }, { once: true });
        } else {
          // If no modal instance, just reload
          await viewUser(userId);
        }
      } else {
        Toast.error(response.message || "Failed to switch assignment to primary");
      }
    } catch (error) {
      console.error("Error switching assignment to primary:", error);
      Toast.error("Failed to switch assignment to primary");
    }
  }

  /**
   * Remove an assignment
   * @param {number} assignmentId - Assignment ID to remove
   */
  async function removeAssignment(assignmentId) {
    try {
      // Get current user ID from state
      const STATE = window.UserManagementState;
      const userId = STATE?.currentUserId;

      if (!userId) {
        Toast.error("User ID not found");
        return;
      }

      Toast.info("Removing assignment...");

      // Call API to delete assignment
      const response = await APIHandler.deleteUserAssignment(assignmentId);

      if (response.success) {
        Toast.success("Assignment removed successfully!");
        
        // Close current modal properly before reopening
        const modalElement = document.getElementById("viewUserModal");
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
          modal.hide();
          
          // Wait for modal to fully close before reopening
          modalElement.addEventListener('hidden.bs.modal', async function() {
            await viewUser(userId);
          }, { once: true });
        } else {
          // If no modal instance, just reload
          await viewUser(userId);
        }
      } else {
        Toast.error(response.message || "Failed to remove assignment");
      }
    } catch (error) {
      console.error("Error removing assignment:", error);
      Toast.error("Failed to remove assignment");
    }
  }

  // ========================================================================
  // EXPOSE MODALS MODULE
  // ========================================================================

  window.UserManagementModals = {
    showCreateUserModal,
    editUser,
    viewUser,
    resetPassword,
    switchToPrimary,
    removeAssignment,
  };
})();
