/**
 * ============================================================================
 * PROFILE PAGE - UPDATED VERSION WITH FIXES
 * ============================================================================
 * Diocese Management System - Makueni West
 * Features: Fixed API endpoints, Styled components, Edit Profile Modal
 * ============================================================================
 */

(function () {
  ("use strict");

  // ========================================================================
  // CONFIGURATION & CONSTANTS
  // ========================================================================

  const API_BASE =
    AppConfig.API_BASE_URL;

  const STORAGE_KEYS = {
    AUTH_TOKEN: "mwd_auth_token",
    USER_DATA: "mwd_user_data",
  };

  const AUDIT_TYPES = {
    ALL: "all",
    LOGIN: "login",
    PASSWORD: "password",
    PROFILE: "profile",
    STATUS: "status",
  };

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  let currentUserId = null;
  let authToken = null;
  let cachedProfileData = null;
  let cachedAuditData = [];
  let activeTab = "personal-info";

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  /**
   * Main Initialization Function
   */
  async function initializeProfilePage() {
    try {
      console.log("🚀 PROFILE PAGE: Starting initialization...");

      // Get user data from localStorage
      const userData = JSON.parse(localStorage.getItem(STORAGE_KEYS.USER_DATA));
      authToken = localStorage.getItem(STORAGE_KEYS.AUTH_TOKEN);

      console.log("📦 User Data from localStorage:", userData);
      console.log("🔑 Auth Token:", authToken ? "Present ✅" : "Missing ❌");

      if (!userData || !authToken) {
        console.error("❌ No authentication data found - Redirecting to login");
        window.location.href = "/makueni-west/login";
        return;
      }

      currentUserId = userData.id;
      console.log("👤 Current User ID:", currentUserId);

      // Show loading state
      showLoader("Loading profile...");

      // Load profile data from backend
      console.log("📡 Fetching profile data from API...");
      await loadProfileData();

      // Setup event listeners
      console.log("🎧 Setting up event listeners...");
      setupEventListeners();

      // Load initial tab (Personal Info is default)
      console.log("📋 Rendering Personal Info tab...");
      renderPersonalInfo();

      hideLoader();
      console.log("✅ PROFILE PAGE: Initialization complete!");
    } catch (error) {
      console.error("❌ Failed to initialize profile:", error);
      hideLoader();
      showToast("Failed to load profile. Please refresh the page.", "error");
    }
  }

  // ========================================================================
  // API FUNCTIONS
  // ========================================================================

  /**
   * Load Profile Data from Backend
   */
  async function loadProfileData() {
    try {
      const apiUrl = `${API_BASE}/users/${currentUserId}`;
      console.log("📡 API Request URL:", apiUrl);

      const response = await fetch(apiUrl, {
        method: "GET",
        headers: {
          Authorization: `Bearer ${authToken}`,
          Accept: "application/json",
        },
      });

      console.log(
        "📨 API Response Status:",
        response.status,
        response.statusText
      );

      if (!response.ok) {
        console.error("❌ HTTP Error! Status:", response.status);
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      console.log("📦 API Response Data:", result);

      if (result.success) {
        cachedProfileData = result.data;
        console.log("✅ Profile data cached successfully:", cachedProfileData);
      } else {
        console.error("❌ API returned success: false");
        throw new Error(result.message || "Failed to load profile data");
      }
    } catch (error) {
      console.error("❌ Error loading profile:", error);
      throw error;
    }
  }

  /**
   * ✅ FIXED: Fetch Activity Log with Correct Endpoint
   */
  /**
   * ✅ FIXED: Fetch Activity Log WITH FILTERS
   */
  async function fetchActivityLog(filters = {}) {
    try {
      const {
        auditLimit = 50,
        auditType = "all",
        fromDate = "",
        toDate = "",
      } = filters;

      // ✅ Build URL with filters
      let url = `${API_BASE}/auth/user/${currentUserId}/audits?limit=${auditLimit}`;

      // Add event type filter
      if (auditType && auditType !== "all") {
        url += `&event_type=${auditType}`;
      }

      // Add date filters
      if (fromDate) {
        url += `&from_date=${fromDate}`;
      }

      if (toDate) {
        url += `&to_date=${toDate}`;
      }

      console.log("📡 Fetching Activity Log:", url);

      const response = await fetch(url, {
        method: "GET",
        headers: {
          Authorization: `Bearer ${authToken}`,
          Accept: "application/json",
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      console.log("📦 Activity Log Response:", result);

      if (result.success) {
        // Unlike login-history/password-changes (flat arrays), this endpoint
        // wraps the list: { user, audits, filters_applied }. Returning
        // result.data directly here made renderActivityLog() call .forEach()
        // on that whole object instead of the actual list.
        return result.data.audits || [];
      } else {
        throw new Error(result.message || "Failed to load activity log");
      }
    } catch (error) {
      console.error("❌ Error fetching activity log:", error);
      throw error;
    }
  }

  /**
   * ✅ FIXED: Fetch Login History with Correct Endpoint
   */
  async function fetchLoginHistory(limit = 100) {
    try {
      // ✅ USE CORRECT ENDPOINT
      const url = `${API_BASE}/auth/user/${currentUserId}/audits/login-history`;

      console.log("📡 Fetching Login History:", url);

      const response = await fetch(url, {
        method: "GET",
        headers: {
          Authorization: `Bearer ${authToken}`,
          Accept: "application/json",
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      console.log("📦 Login History Response:", result);

      if (result.success) {
        return result.data; // This is the login history array
      } else {
        throw new Error(result.message || "Failed to load login history");
      }
    } catch (error) {
      console.error("❌ Error fetching login history:", error);
      throw error;
    }
  }

  /**
   * ✅ FIXED: Fetch Password History with Correct Endpoint
   */
  async function fetchPasswordHistory() {
    try {
      // ✅ USE CORRECT ENDPOINT
      const url = `${API_BASE}/auth/user/${currentUserId}/audits/password-changes`;

      console.log("📡 Fetching Password History:", url);

      const response = await fetch(url, {
        method: "GET",
        headers: {
          Authorization: `Bearer ${authToken}`,
          Accept: "application/json",
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      console.log("📦 Password History Response:", result);

      if (result.success) {
        return result.data; // This is the password changes array
      } else {
        throw new Error(result.message || "Failed to load password history");
      }
    } catch (error) {
      console.error("❌ Error fetching password history:", error);
      throw error;
    }
  }

  /**
   * ✅ FIXED: Change Password with Correct Endpoint (NO current password needed!)
   */
  async function changePassword(formData) {
    try {
      // ✅ USE CORRECT ENDPOINT - NO CURRENT PASSWORD NEEDED
      const response = await fetch(
        `${API_BASE}/password-reset/change-password`,
        {
          method: "POST",
          headers: {
            Authorization: `Bearer ${authToken}`,
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            new_password: formData.new_password,
            new_password_confirmation: formData.new_password_confirmation,
          }),
        }
      );

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || "Failed to change password");
      }

      return result;
    } catch (error) {
      console.error("❌ Error changing password:", error);
      throw error;
    }
  }

  /**
   * ✅ NEW: Update Profile Function
   */
  async function updateProfile(formData) {
    try {
      const response = await fetch(`${API_BASE}/auth/profile`, {
        method: "PUT",
        headers: {
          Authorization: `Bearer ${authToken}`,
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(formData),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || "Failed to update profile");
      }

      return result;
    } catch (error) {
      console.error("❌ Error updating profile:", error);
      throw error;
    }
  }

  // ========================================================================
  // TAB 1: PERSONAL INFORMATION
  // ========================================================================

  /**
   * Render Personal Information Tab
   */
  function renderPersonalInfo() {
    if (!cachedProfileData) {
      console.error("❌ No profile data available");
      return;
    }

    console.log("📋 Populating profile fields...");
    const data = cachedProfileData;

    // ========== PROFILE HEADER ==========
    const avatarEl = document.getElementById("profileHeaderAvatar");
    if (avatarEl) {
      const initials = getInitials(data.full_name);
      avatarEl.innerHTML = initials;
    }

    updateElementText("profileHeaderName", data.full_name || "N/A");
    updateElementText("profileHeaderPosition", data.position || "N/A");
    updateElementText("profileHeaderEmail", data.email || "N/A");
    updateElementText("profileHeaderPhone", data.phone || "N/A");
    updateElementText(
      "profileAssignmentsCount",
      data.active_assignments?.length || 0
    );

    // Header Status Badge
    const headerStatusEl = document.getElementById("profileHeaderStatus");
    if (headerStatusEl) {
      headerStatusEl.textContent =
        data.status.charAt(0).toUpperCase() + data.status.slice(1);
      headerStatusEl.className = "badge " + getStatusBadgeClass(data.status);
    }

    // ========== SIDEBAR ==========
    updateElementText("profileSidebarEmail", data.email || "N/A");
    updateElementText("profileSidebarPhone", data.phone || "N/A");
    updateElementText("profileSidebarUsername", data.username || "N/A");

    const empCodeSidebarEl = document.getElementById("profileEmployeeCode");
    if (empCodeSidebarEl) {
      empCodeSidebarEl.textContent = data.employee_code;
    }

    updateElementText(
      "profileLastLogin",
      data.last_login_at ? formatDateTime(data.last_login_at) : "Never"
    );
    updateElementText(
      "profileMemberSince",
      data.created_at ? formatDate(data.created_at) : "N/A"
    );

    // ========== PERSONAL INFO TAB CONTENT ==========
    updateElementText("profileFirstname", data.firstname || "N/A");
    updateElementText("profileLastname", data.lastname || "N/A");
    updateElementText("profileEmail", data.email || "N/A");
    updateElementText("profilePhone", data.phone || "N/A");
    updateElementText("profileUsername", data.username || "N/A");

    const empCodeTabEl = document.getElementById("profileEmpCode");
    if (empCodeTabEl) {
      empCodeTabEl.textContent = data.employee_code;
    }

    updateElementText("profilePositionDetail", data.position || "N/A");

    const statusDetailEl = document.getElementById("profileStatusDetail");
    if (statusDetailEl) {
      statusDetailEl.textContent =
        data.status.charAt(0).toUpperCase() + data.status.slice(1);
      statusDetailEl.className = "badge " + getStatusBadgeClass(data.status);
    }

    console.log("✅ Personal Info tab populated successfully");
  }

  // ========================================================================
  // TAB 2: ACCOUNT STATUS
  // ========================================================================

  /**
   * Render Account Status Tab
   */
  function renderAccountStatus() {
    if (!cachedProfileData) {
      console.error("No profile data available");
      return;
    }

    const data = cachedProfileData;
    const passwordStatus = data.password_status || {};

    updateElementText(
      "lastPasswordChange",
      passwordStatus.last_changed
        ? formatDate(passwordStatus.last_changed)
        : "Never"
    );
    updateElementText(
      "passwordExpires",
      passwordStatus.expires_at
        ? formatDate(passwordStatus.expires_at)
        : "Never"
    );
    updateElementText(
      "passwordExpiryDays",
      passwordStatus.days_until_expiry !== undefined
        ? `${passwordStatus.days_until_expiry} days`
        : "N/A"
    );

    updateElementText(
      "lastLogin",
      data.last_login_at ? formatDateTime(data.last_login_at) : "Never"
    );

    const loginAttemptsEl = document.getElementById("loginAttempts");
    if (loginAttemptsEl) {
      loginAttemptsEl.textContent = data.login_attempts || 0;
      loginAttemptsEl.className =
        data.login_attempts >= 3
          ? "badge bg-danger fs-13 px-3 py-2"
          : "badge bg-success fs-13 px-3 py-2";
    }

    // Show/hide warnings
    const mustChangeEl = document.getElementById("mustChangePasswordWarning");
    if (mustChangeEl && data.must_change_password) {
      mustChangeEl.classList.remove("d-none");
    }

    const expiryWarningEl = document.getElementById("passwordExpiryWarning");
    if (expiryWarningEl && passwordStatus.days_until_expiry !== undefined) {
      const days = passwordStatus.days_until_expiry;
      if (days <= 7 && days > 0) {
        expiryWarningEl.className = "alert alert-warning";
        expiryWarningEl.innerHTML = `<i class="ri-error-warning-line me-2"></i><strong>Password Expiring Soon!</strong> Your password expires in ${days} days.`;
        expiryWarningEl.classList.remove("d-none");
      } else if (days <= 0) {
        expiryWarningEl.className = "alert alert-danger";
        expiryWarningEl.innerHTML = `<i class="ri-error-warning-line me-2"></i><strong>Password Expired!</strong> Please change your password immediately.`;
        expiryWarningEl.classList.remove("d-none");
      }
    }

    const lockedWarningEl = document.getElementById("accountLockedWarning");
    if (lockedWarningEl && data.login_attempts >= 3) {
      lockedWarningEl.classList.remove("d-none");
    }
  }

  // ========================================================================
  // TAB 3: ASSIGNMENTS
  // ========================================================================

  /**
   * ✅ STYLED: Render Assignments Tab
   */
  function renderAssignments() {
    if (!cachedProfileData) {
      console.error("No profile data available");
      return;
    }

    const data = cachedProfileData;

    // Render active assignments
    const activeContainer = document.getElementById(
      "activeAssignmentsContainer"
    );
    if (activeContainer) {
      if (data.active_assignments && data.active_assignments.length > 0) {
        let html = '<div class="row g-3">';
        data.active_assignments.forEach((assignment) => {
          const isPrimary =
            assignment.is_primary || assignment.assignment_type === "primary";
          html += `
            <div class="col-md-6">
                <div class="card border shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="d-flex align-items-center">
                                <span class="avatar avatar-md bg-primary-transparent text-primary rounded-circle me-2">
                                    <i class="ri-shield-star-line fs-18"></i>
                                </span>
                                <div>
                                    <h6 class="mb-0 fw-semibold">${
                                      assignment.role?.name || "N/A"
                                    }</h6>
                                </div>
                            </div>
                            <span class="badge ${
                              isPrimary ? "bg-primary" : "bg-warning"
                            } fs-12 px-3 py-2">
                                ${isPrimary ? "Primary" : "Secondary"}
                            </span>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1">
                                <i class="ri-building-line me-1 text-info"></i>
                                <strong>Territory:</strong>
                            </small>
                            <p class="mb-0 ms-3 fw-medium">${
                              assignment.territory?.name || "N/A"
                            }</p>
                        </div>
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1">
                                <i class="ri-map-pin-line me-1 text-success"></i>
                                <strong>Type:</strong>
                            </small>
                            <p class="mb-0 ms-3">
                                <span class="badge bg-success-transparent text-success">
                                    ${assignment.territory?.type || "N/A"}
                                </span>
                            </p>
                        </div>
                        <div class="border-top pt-2 mt-2">
                            <small class="text-muted">
                                <i class="ri-calendar-line me-1"></i>
                                Assigned: <strong>${formatDate(
                                  assignment.assigned_at
                                )}</strong>
                            </small>
                        </div>
                    </div>
                </div>
            </div>`;
        });
        html += "</div>";
        activeContainer.innerHTML = html;
      } else {
        activeContainer.innerHTML = `
          <div class="text-center py-5">
              <i class="ri-briefcase-line fs-48 text-muted mb-3 d-block"></i>
              <p class="text-muted mb-0">No active assignments</p>
          </div>`;
      }
    }

    // Render assignment history
    const historyContainer = document.getElementById(
      "assignmentHistoryContainer"
    );
    if (historyContainer) {
      if (data.assignment_history && data.assignment_history.length > 0) {
        let html = `
          <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                  <thead class="bg-light">
                      <tr>
                          <th class="fw-semibold"><i class="ri-shield-line me-1 text-primary"></i>Role</th>
                          <th class="fw-semibold"><i class="ri-building-line me-1 text-info"></i>Territory</th>
                          <th class="fw-semibold"><i class="ri-user-line me-1 text-success"></i>Assigned By</th>
                          <th class="fw-semibold"><i class="ri-calendar-line me-1 text-warning"></i>Date</th>
                          <th class="fw-semibold text-center"><i class="ri-checkbox-circle-line me-1"></i>Status</th>
                      </tr>
                  </thead>
                  <tbody>`;

        data.assignment_history.forEach((history) => {
          const statusBadge = history.is_active
            ? '<span class="badge bg-success"><i class="ri-check-line me-1"></i>Active</span>'
            : '<span class="badge bg-secondary"><i class="ri-close-line me-1"></i>Removed</span>';

          html += `
            <tr>
                <td><span class="fw-medium">${history.role_name}</span></td>
                <td>${history.territory_name}</td>
                <td><small class="text-muted">${
                  history.assigned_by || "N/A"
                }</small></td>
                <td><small class="text-muted">${formatDate(
                  history.assigned_at
                )}</small></td>
                <td class="text-center">${statusBadge}</td>
            </tr>`;
        });

        html += "</tbody></table></div>";
        historyContainer.innerHTML = html;
      } else {
        historyContainer.innerHTML = `
          <div class="text-center py-5">
              <i class="ri-history-line fs-48 text-muted mb-3 d-block"></i>
              <p class="text-muted mb-0">No assignment history</p>
          </div>`;
      }
    }
  }

  // ========================================================================
  // TAB 4: SECURITY / PASSWORD HISTORY
  // ========================================================================

  /**
   * ✅ FIXED: Load Password History
   */
  async function loadPasswordHistory() {
    try {
      showLoader("Loading password history...");

      const passwordChanges = await fetchPasswordHistory();
      renderPasswordHistory(passwordChanges);
      hideLoader();
    } catch (error) {
      console.error("Error loading password history:", error);
      hideLoader();
      showToast("Failed to load password history", "error");
    }
  }

  /**
   * ✅ STYLED: Render Password History
   */
  function renderPasswordHistory(passwordChanges) {
    const container = document.getElementById("passwordHistoryContainer");

    if (!passwordChanges || passwordChanges.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5">
            <i class="ri-key-2-line fs-48 text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">No password changes found</p>
        </div>`;
      return;
    }

    let html = `
      <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
              <thead class="bg-light">
                  <tr>
                      <th class="fw-semibold"><i class="ri-calendar-line me-1 text-primary"></i>Date & Time</th>
                      <th class="fw-semibold"><i class="ri-file-list-line me-1 text-warning"></i>Event</th>
                      <th class="fw-semibold"><i class="ri-user-line me-1 text-success"></i>Changed By</th>
                      <th class="fw-semibold"><i class="ri-map-pin-line me-1 text-info"></i>IP Address</th>
                  </tr>
              </thead>
              <tbody>`;

    passwordChanges.forEach((change) => {
      html += `
        <tr>
            <td><small class="text-muted">${formatDateTime(
              change.created_at
            )}</small></td>
            <td><span class="badge bg-warning"><i class="ri-key-2-line me-1"></i>${
              change.event
            }</span></td>
          <td>${change.changed_by?.name || change.changed_by || "System"}</td>
            <td><code class="bg-light px-2 py-1 rounded fs-12">${
              change.ip_address || "N/A"
            }</code></td>
        </tr>`;
    });

    html += "</tbody></table></div>";
    container.innerHTML = html;
  }

  // ========================================================================
  // TAB 5: ACTIVITY LOG (BEAUTIFUL TIMELINE)
  // ========================================================================

  /**
   * ✅ FIXED: Load Activity Log
   */
  /**
   * : Load Activity Log with filter values
   */
  async function loadActivityLog() {
    try {
      // ✅ GET ALL FILTER VALUES
      const auditLimit =
        document.getElementById("auditLimitSelect")?.value || 50;
      const auditType =
        document.getElementById("auditTypeFilter")?.value || "all";
      const fromDate = document.getElementById("auditFromDate")?.value || "";
      const toDate = document.getElementById("auditToDate")?.value || "";

      showLoader("Loading activity log...");

      // ✅ PASS ALL FILTERS TO fetchActivityLog
      const auditData = await fetchActivityLog({
        auditLimit: parseInt(auditLimit),
        auditType: auditType,
        fromDate: fromDate,
        toDate: toDate,
      });

      cachedAuditData = auditData || [];
      renderActivityLog(cachedAuditData);
      hideLoader();
    } catch (error) {
      console.error("Error loading activity log:", error);
      hideLoader();
      showToast("Failed to load activity log", "error");
    }
  }
  /**
   * ✅ STYLED: Render Activity Log as BEAUTIFUL TIMELINE
   */
  /**
   * ✅ STYLED: Render Activity Log as BEAUTIFUL TIMELINE
   */
  function renderActivityLog(auditData) {
    const container = document.getElementById("activityLogContainer");

    if (!auditData || auditData.length === 0) {
      container.innerHTML = `
        <li>
            <div class="text-center py-5">
                <i class="ri-history-line fs-48 text-muted mb-3 d-block"></i>
                <p class="text-muted mb-0">No activity found</p>
            </div>
        </li>`;
      return;
    }

    let html = "";
    auditData.forEach((audit, index) => {
      const eventIcon = getEventIcon(audit.event);
      const eventColor = getEventColor(audit.event);

      // ✅ FIX: Extract name from changed_by object
      const changedByName =
        audit.changed_by?.name || audit.changed_by || "System";
      const changedByInitials = getInitials(changedByName);

      html += `
        <li>
            <div>
                <span class="avatar avatar-sm bg-${eventColor}-transparent avatar-rounded profile-timeline-avatar">
                    <i class="${eventIcon}"></i>
                </span>
                <p class="mb-2">
                    <b>${changedByName}</b> performed action
                    <span class="badge bg-${eventColor} ms-2">${
        audit.event
      }</span>
                    <span class="float-end fs-11 text-muted">
                        <i class="ri-time-line me-1"></i>${formatRelativeTime(
                          audit.created_at
                        )}
                    </span>
                </p>
                <p class="text-muted fs-12 mb-2">
                    <i class="ri-map-pin-line me-1 text-info"></i>IP: ${
                      audit.ip_address || "N/A"
                    }
                    ${
                      audit.user_agent
                        ? `<i class="ri-computer-line ms-3 me-1 text-success"></i>${truncateUserAgent(
                            audit.user_agent
                          )}`
                        : ""
                    }
                </p>
                <button class="btn btn-sm btn-${eventColor}-light" onclick="ProfilePage.viewAuditDetails(${index})">
                    <i class="ri-eye-line me-1"></i>View Details
                </button>
            </div>
        </li>`;
    });

    container.innerHTML = html;
  }
  /**
   * ✅ STYLED: View Audit Details in Modal
   */
  /**
   * ✅ STYLED: View Audit Details in Modal (NO RAW JSON!)
   */
  /**
   * ✅ CREATIVE: View Audit Details with Beautiful Card Design (NO TABLES!)
   */
  function viewAuditDetails(index) {
    const audit = cachedAuditData[index];
    if (!audit) return;

    const modalBody = document.getElementById("auditDetailsModalBody");
    if (!modalBody) return;

    const eventColor = getEventColor(audit.event);

    // ✅ FIX: Extract name from changed_by object
    const changedByName =
      audit.changed_by?.name || audit.changed_by || "System";

    let html = `
      <!-- Event Summary Card -->
      <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, var(--${eventColor}) 0%, var(--${eventColor}-dark) 100%);">
          <div class="card-body text-white p-4">
              <div class="row align-items-center">
                  <div class="col-auto">
                      <div class="avatar avatar-xl rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center">
                          <i class="${getEventIcon(audit.event)} fs-1"></i>
                      </div>
                  </div>
                  <div class="col">
                      <h5 class="mb-2 fw-bold text-white">${audit.event}</h5>
                      <p class="mb-0 opacity-75">
                          <i class="ri-calendar-line me-2"></i>${formatDateTime(
                            audit.created_at
                          )}
                      </p>
                  </div>
              </div>
          </div>
      </div>

      <!-- Details Cards -->
      <div class="row g-3 mb-4">
          <div class="col-md-4">
              <div class="card border-0 shadow-sm h-100">
                  <div class="card-body text-center">
                      <div class="avatar avatar-md rounded-circle bg-primary-transparent text-primary mx-auto mb-3">
                          <i class="ri-user-line fs-4"></i>
                      </div>
                      <p class="text-muted mb-1 fs-12 text-uppercase">Changed By</p>
                      <h6 class="mb-0 fw-semibold">${changedByName}</h6>
                  </div>
              </div>
          </div>
          
          <div class="col-md-4">
              <div class="card border-0 shadow-sm h-100">
                  <div class="card-body text-center">
                      <div class="avatar avatar-md rounded-circle bg-success-transparent text-success mx-auto mb-3">
                          <i class="ri-map-pin-line fs-4"></i>
                      </div>
                      <p class="text-muted mb-1 fs-12 text-uppercase">IP Address</p>
                      <h6 class="mb-0"><code class="bg-light px-2 py-1 rounded">${
                        audit.ip_address || "N/A"
                      }</code></h6>
                  </div>
              </div>
          </div>
          
          <div class="col-md-4">
              <div class="card border-0 shadow-sm h-100">
                  <div class="card-body text-center">
                      <div class="avatar avatar-md rounded-circle bg-info-transparent text-info mx-auto mb-3">
                          <i class="ri-computer-line fs-4"></i>
                      </div>
                      <p class="text-muted mb-1 fs-12 text-uppercase">Device</p>
                      <h6 class="mb-0 fs-13">${truncateUserAgent(
                        audit.user_agent
                      )}</h6>
                  </div>
              </div>
          </div>
      </div>

      <!-- User Agent Full Details -->
      <div class="card border-0 shadow-sm mb-4">
          <div class="card-body">
              <div class="d-flex align-items-center mb-2">
                  <span class="avatar avatar-sm bg-info-transparent text-info rounded-circle me-2">
                      <i class="ri-device-line"></i>
                  </span>
                  <h6 class="mb-0 fw-semibold">Full User Agent</h6>
              </div>
              <p class="mb-0 text-muted fs-12 font-monospace bg-light p-3 rounded">${
                audit.user_agent || "N/A"
              }</p>
          </div>
      </div>`;

    // ✅ OLD VALUES - Beautiful Card Layout
    if (audit.old_values && Object.keys(audit.old_values).length > 0) {
      html += `
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-danger-transparent border-0">
                <div class="d-flex align-items-center">
                    <span class="avatar avatar-sm bg-danger text-white rounded-circle me-2">
                        <i class="ri-arrow-left-line"></i>
                    </span>
                    <h6 class="mb-0 fw-semibold text-danger">Previous Values</h6>
                </div>
            </div>
            <div class="card-body">`;

      let oldValuesCount = 0;
      for (const [key, value] of Object.entries(audit.old_values)) {
        const isLastItem =
          oldValuesCount === Object.keys(audit.old_values).length - 1;
        html += `
                <div class="d-flex align-items-start py-3 ${
                  !isLastItem ? "border-bottom" : ""
                }">
                    <div class="flex-shrink-0 me-3">
                        <span class="avatar avatar-sm bg-light rounded-circle">
                            <i class="ri-subtract-line text-danger"></i>
                        </span>
                    </div>
                    <div class="flex-grow-1">
                        <p class="mb-1 fw-semibold text-dark">${formatFieldName(
                          key
                        )}</p>
                        <p class="mb-0 text-muted">${formatAuditValue(
                          value
                        )}</p>
                    </div>
                </div>`;
        oldValuesCount++;
      }

      html += `
            </div>
        </div>`;
    }

    // ✅ NEW VALUES - Beautiful Card Layout
    if (audit.new_values && Object.keys(audit.new_values).length > 0) {
      html += `
        <div class="card border-0 shadow-sm mb-0">
            <div class="card-header bg-success-transparent border-0">
                <div class="d-flex align-items-center">
                    <span class="avatar avatar-sm bg-success text-white rounded-circle me-2">
                        <i class="ri-arrow-right-line"></i>
                    </span>
                    <h6 class="mb-0 fw-semibold text-success">Updated Values</h6>
                </div>
            </div>
            <div class="card-body">`;

      let newValuesCount = 0;
      for (const [key, value] of Object.entries(audit.new_values)) {
        const isLastItem =
          newValuesCount === Object.keys(audit.new_values).length - 1;
        html += `
                <div class="d-flex align-items-start py-3 ${
                  !isLastItem ? "border-bottom" : ""
                }">
                    <div class="flex-shrink-0 me-3">
                        <span class="avatar avatar-sm bg-light rounded-circle">
                            <i class="ri-add-line text-success"></i>
                        </span>
                    </div>
                    <div class="flex-grow-1">
                        <p class="mb-1 fw-semibold text-dark">${formatFieldName(
                          key
                        )}</p>
                        <p class="mb-0 text-success fw-medium">${formatAuditValue(
                          value
                        )}</p>
                    </div>
                </div>`;
        newValuesCount++;
      }

      html += `
            </div>
        </div>`;
    }

    modalBody.innerHTML = html;

    // Show modal
    const modal = new bootstrap.Modal(
      document.getElementById("auditDetailsModal")
    );
    modal.show();
  }

  // ========================================================================
  // TAB 6: LOGIN HISTORY
  // ========================================================================

  /**
   * ✅ FIXED: Load Login History
   */
  async function loadLoginHistory() {
    try {
      const limit =
        document.getElementById("loginHistoryLimitSelect")?.value || 100;

      showLoader("Loading login history...");

      const loginData = await fetchLoginHistory(parseInt(limit));
      renderLoginHistory(loginData);
      hideLoader();
    } catch (error) {
      console.error("Error loading login history:", error);
      hideLoader();
      showToast("Failed to load login history", "error");
    }
  }

  /**
   * ✅ STYLED: Render Login History
   */
  /**
   * ✅ STYLED: Render Login History as BEAUTIFUL TIMELINE
   */
  function renderLoginHistory(loginData) {
    const container = document.getElementById("loginHistoryContainer");

    if (!loginData || loginData.length === 0) {
      container.innerHTML = `
        <div class="text-center py-5">
            <i class="ri-login-box-line fs-48 text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">No login history found</p>
        </div>`;
      return;
    }

    let html = `<ul class="list-unstyled profile-timeline">`;

    loginData.forEach((login) => {
      // Determine if login was successful
      const isSuccess =
        login.event === "user_login" ||
        (login.login_success !== false && !login.event.includes("failed"));

      const eventColor = isSuccess ? "success" : "danger";
      const eventIcon = isSuccess
        ? "ri-login-circle-line"
        : "ri-close-circle-line";
      const eventText = isSuccess ? "logged in successfully" : "login failed";

      // Get login method if available
      const loginMethod =
        login.new_values?.login_method ||
        (login.event === "user_login" ? "password" : "unknown");

      html += `
        <li>
            <div>
                <span class="avatar avatar-sm bg-${eventColor}-transparent avatar-rounded profile-timeline-avatar">
                    <i class="${eventIcon}"></i>
                </span>
                <p class="mb-2">
                    <b>User</b> ${eventText}
                    <span class="badge bg-${eventColor} ms-2">${
        login.event
      }</span>
                    <span class="float-end fs-11 text-muted">
                        <i class="ri-time-line me-1"></i>${formatDateTime(
                          login.created_at
                        )}
                    </span>
                </p>
                <p class="text-muted fs-12 mb-2">
                    <i class="ri-shield-keyhole-line me-1 text-info"></i>Method: <strong>${loginMethod}</strong>
                    ${
                      login.login_attempts !== null &&
                      login.login_attempts !== undefined
                        ? `<span class="ms-3"><i class="ri-error-warning-line me-1 text-warning"></i>Attempts: <strong>${login.login_attempts}</strong></span>`
                        : ""
                    }
                </p>
                <p class="text-muted fs-12 mb-0">
                    <i class="ri-map-pin-line me-1 text-success"></i>IP: <code class="bg-light px-2 py-1 rounded fs-11">${
                      login.ip_address || "N/A"
                    }</code>
                    ${
                      login.user_agent
                        ? `<i class="ri-computer-line ms-3 me-1 text-primary"></i>${truncateUserAgent(
                            login.user_agent
                          )}`
                        : ""
                    }
                </p>
            </div>
        </li>`;
    });

    html += `</ul>`;
    container.innerHTML = html;
  }
  /**
   * Format audit value for display (handle different types)
   */
  /**
   * Format audit value for display (handle different types)
   */
  function formatAuditValue(value) {
    if (value === null || value === undefined) {
      return '<span class="badge bg-secondary-transparent text-secondary">null</span>';
    }

    if (typeof value === "boolean") {
      return value
        ? '<span class="badge bg-success fs-12 px-3 py-2"><i class="ri-check-line me-1"></i>True</span>'
        : '<span class="badge bg-danger fs-12 px-3 py-2"><i class="ri-close-line me-1"></i>False</span>';
    }

    if (typeof value === "number") {
      return `<span class="fw-semibold text-primary">${value}</span>`;
    }

    if (typeof value === "object") {
      // Format objects/arrays nicely
      return `<code class="bg-light px-2 py-1 rounded d-inline-block text-wrap">${JSON.stringify(
        value
      )}</code>`;
    }

    // Check if it's a date string
    if (typeof value === "string" && value.match(/^\d{4}-\d{2}-\d{2}/)) {
      return `<span class="text-primary"><i class="ri-calendar-line me-1"></i>${formatDateTime(
        value
      )}</span>`;
    }

    // Regular string
    return `<span class="text-dark">${value}</span>`;
  }
  // ========================================================================
  // EDIT PROFILE MODAL
  // ========================================================================

  /**
   * ✅ NEW: Show Edit Profile Modal
   */
  function showEditProfileModal() {
    if (!cachedProfileData) return;

    const data = cachedProfileData;

    // Populate form fields
    document.getElementById("editFirstname").value = data.firstname || "";
    document.getElementById("editLastname").value = data.lastname || "";
    document.getElementById("editEmail").value = data.email || "";
    document.getElementById("editPhone").value = data.phone || "";
    document.getElementById("editUsername").value = data.username || "";
    document.getElementById("editPosition").value = data.position || "";

    // Show modal
    const modal = new bootstrap.Modal(
      document.getElementById("editProfileModal")
    );
    modal.show();
  }

  /**
   * ✅ NEW: Handle Edit Profile Form Submit
   */
  async function handleEditProfileSubmit(e) {
    e.preventDefault();

    const formData = {
      firstname: document.getElementById("editFirstname")?.value,
      lastname: document.getElementById("editLastname")?.value,
      email: document.getElementById("editEmail")?.value,
      phone: document.getElementById("editPhone")?.value,
      username: document.getElementById("editUsername")?.value,
      position: document.getElementById("editPosition")?.value,
    };

    try {
      showLoader("Updating profile...");

      const result = await updateProfile(formData);

      hideLoader();
      showToast("Profile updated successfully!", "success");

      // Close modal
      const modal = bootstrap.Modal.getInstance(
        document.getElementById("editProfileModal")
      );
      if (modal) modal.hide();

      // Reload profile data
      await loadProfileData();
      renderPersonalInfo();
    } catch (error) {
      hideLoader();
      showToast(error.message || "Failed to update profile", "error");
    }
  }

  // ========================================================================
  // FORM HANDLERS
  // ========================================================================

  /**
   * ✅ FIXED: Handle Change Password Form Submit
   */
  /**
   * ✅ UPDATED: Handle Change Password Form Submit with Spinner
   */
  async function handleChangePasswordSubmit(e) {
    e.preventDefault();

    const newPassword = document.getElementById("newPassword")?.value;
    const confirmPassword = document.getElementById("confirmPassword")?.value;

    // Validate passwords
    if (newPassword !== confirmPassword) {
      showToast("New passwords do not match", "error");
      return;
    }

    if (newPassword.length < 8) {
      showToast("Password must be at least 8 characters", "error");
      return;
    }

    // Get submit button
    const submitButton = e.target.querySelector('button[type="submit"]');

    try {
      // ✅ Show spinner on button
      showButtonSpinner(submitButton, "Changing Password...");

      const result = await changePassword({
        new_password: newPassword,
        new_password_confirmation: confirmPassword,
      });

      // ✅ Hide spinner
      hideButtonSpinner(submitButton);

      showToast("Password changed successfully!", "success");

      // Close modal
      const modal = bootstrap.Modal.getInstance(
        document.getElementById("changePasswordModal")
      );
      if (modal) modal.hide();

      // Reset form
      e.target.reset();

      // ✅ Reload profile data and update Account Status tab
      await loadProfileData();
      renderAccountStatus();
    } catch (error) {
      // ✅ Hide spinner on error
      hideButtonSpinner(submitButton);
      showToast(error.message || "Failed to change password", "error");
    }
  }

  // ========================================================================
  // EVENT LISTENERS
  // ========================================================================

  /**
   * Setup All Event Listeners
   */
  /**
   * Setup All Event Listeners
   */
  /**
   * Setup All Event Listeners
   */
  function setupEventListeners() {
    // Tab click handlers
    const tabs = [
      "personal-info",
      "account-status",
      "assignments",
      "security",
      "activity-log",
      "login-history",
    ];
    tabs.forEach((tabName) => {
      const tabEl = document.getElementById(`${tabName}-tab`);
      if (tabEl) {
        tabEl.addEventListener("click", () => {
          activeTab = tabName;
          handleTabChange(tabName);
        });
      }
    });

    // Activity log filter buttons
    const applyFiltersBtn = document.getElementById("applyActivityFilters");
    if (applyFiltersBtn) {
      applyFiltersBtn.addEventListener("click", loadActivityLog);
    }

    const clearFiltersBtn = document.getElementById("clearActivityFilters");
    if (clearFiltersBtn) {
      clearFiltersBtn.addEventListener("click", () => {
        document.getElementById("auditTypeFilter").value = "all";
        document.getElementById("auditFromDate").value = "";
        document.getElementById("auditToDate").value = "";
        document.getElementById("auditLimitSelect").value = "50";
        loadActivityLog();
      });
    }

    // ✅ AUTO-APPLY FILTERS when dropdowns/dates change
    const auditTypeFilter = document.getElementById("auditTypeFilter");
    const auditFromDate = document.getElementById("auditFromDate");
    const auditToDate = document.getElementById("auditToDate");
    const auditLimitSelect = document.getElementById("auditLimitSelect");

    if (auditTypeFilter) {
      auditTypeFilter.addEventListener("change", loadActivityLog);
    }

    if (auditFromDate) {
      auditFromDate.addEventListener("change", loadActivityLog);
    }

    if (auditToDate) {
      auditToDate.addEventListener("change", loadActivityLog);
    }

    if (auditLimitSelect) {
      auditLimitSelect.addEventListener("change", loadActivityLog);
    }

    // Login history limit change
    const loginLimitSelect = document.getElementById("loginHistoryLimitSelect");
    if (loginLimitSelect) {
      loginLimitSelect.addEventListener("change", loadLoginHistory);
    }

    // Change password form
    const changePasswordForm = document.getElementById("changePasswordForm");
    if (changePasswordForm) {
      changePasswordForm.addEventListener("submit", handleChangePasswordSubmit);
    }

    // ✅ Edit profile form
    const editProfileForm = document.getElementById("editProfileForm");
    if (editProfileForm) {
      editProfileForm.addEventListener("submit", handleEditProfileSubmit);
    }

    // ✅ Edit profile button clicks - Prevent ALL redirections
    document
      .querySelectorAll('a[href="profile-settings/edit"]')
      .forEach((link) => {
        link.addEventListener("click", (e) => {
          e.preventDefault(); // Stop navigation
          e.stopPropagation(); // Stop event bubbling
          showEditProfileModal();
          return false;
        });
      });

    // ✅ Also catch any buttons with Edit Profile text
    document.querySelectorAll("button").forEach((btn) => {
      if (btn.textContent.includes("Edit Profile")) {
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          showEditProfileModal();
          return false;
        });
      }
    });
  }

  /**
   * ✅ UPDATED: Show Edit Profile Modal with PRE-FILLED DATA
   */
  function showEditProfileModal() {
    if (!cachedProfileData) {
      showToast("Profile data not loaded yet. Please wait...", "warning");
      return;
    }

    const data = cachedProfileData;

    // ✅ PRE-FILL all form fields with current data
    const firstnameField = document.getElementById("editFirstname");
    const lastnameField = document.getElementById("editLastname");
    const emailField = document.getElementById("editEmail");
    const phoneField = document.getElementById("editPhone");
    const usernameField = document.getElementById("editUsername");
    const positionField = document.getElementById("editPosition");

    if (firstnameField) firstnameField.value = data.firstname || "";
    if (lastnameField) lastnameField.value = data.lastname || "";
    if (emailField) emailField.value = data.email || "";
    if (phoneField) phoneField.value = data.phone || "";
    if (usernameField) usernameField.value = data.username || "";
    if (positionField) positionField.value = data.position || "";

    console.log("✅ Edit Profile Modal: Pre-filled with data", data);

    // Show modal
    const modalElement = document.getElementById("editProfileModal");
    if (modalElement) {
      const modal = new bootstrap.Modal(modalElement);
      modal.show();
    } else {
      console.error("❌ Edit Profile Modal not found in DOM!");
      showToast(
        "Edit Profile modal not found. Please refresh the page.",
        "error"
      );
    }
  }

  /**
   * Handle Tab Change
   */
  function handleTabChange(tabName) {
    switch (tabName) {
      case "personal-info":
        renderPersonalInfo();
        break;
      case "account-status":
        renderAccountStatus();
        break;
      case "assignments":
        renderAssignments();
        break;
      case "security":
        loadPasswordHistory();
        break;
      case "activity-log":
        loadActivityLog();
        break;
      case "login-history":
        loadLoginHistory();
        break;
    }
  }

  // ========================================================================
  // UTILITY FUNCTIONS
  // ========================================================================
  /**
   * Show spinner on button and disable it
   */
  /**
   * Format field name to be more readable
   */
  function formatFieldName(fieldName) {
    if (!fieldName) return "Unknown Field";

    // Convert snake_case to Title Case
    return fieldName
      .split("_")
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(" ");
  }
  function showButtonSpinner(buttonElement, loadingText = "Loading...") {
    if (!buttonElement) return;

    // Store original content
    buttonElement.setAttribute("data-original-html", buttonElement.innerHTML);

    // Show spinner
    buttonElement.innerHTML = `
      <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
      ${loadingText}
    `;
    buttonElement.disabled = true;
  }

  /**
   * Hide spinner on button and re-enable it
   */
  function hideButtonSpinner(buttonElement) {
    if (!buttonElement) return;

    // Restore original content
    const originalHtml = buttonElement.getAttribute("data-original-html");
    if (originalHtml) {
      buttonElement.innerHTML = originalHtml;
    }
    buttonElement.disabled = false;
  }
  function updateElementText(elementId, text) {
    const element = document.getElementById(elementId);
    if (element) {
      element.textContent = text;
    }
  }

  function getInitials(fullName) {
    if (!fullName) return "?";
    const names = fullName.trim().split(" ");
    if (names.length === 1) return names[0].charAt(0).toUpperCase();
    return (
      names[0].charAt(0) + names[names.length - 1].charAt(0)
    ).toUpperCase();
  }

  function getStatusBadgeClass(status) {
    const statusMap = {
      active: "bg-success",
      inactive: "bg-secondary",
      suspended: "bg-danger",
    };
    return statusMap[status?.toLowerCase()] || "bg-warning";
  }

  function getEventIcon(event) {
    const icons = {
      login: "ri-login-circle-line",
      logout: "ri-logout-circle-line",
      updated: "ri-refresh-line",
      created: "ri-add-circle-line",
      deleted: "ri-delete-bin-line",
      password_changed: "ri-key-2-line",
      password: "ri-key-2-line",
      profile_updated: "ri-user-settings-line",
      status_changed: "ri-shield-check-line",
    };
    return icons[event?.toLowerCase()] || "ri-record-circle-line";
  }

  function getEventColor(event) {
    const colors = {
      login: "success",
      logout: "secondary",
      updated: "primary",
      created: "info",
      deleted: "danger",
      password_changed: "warning",
      password: "warning",
      profile_updated: "info",
      status_changed: "warning",
    };
    return colors[event?.toLowerCase()] || "primary";
  }

  function truncateUserAgent(userAgent) {
    if (!userAgent) return "N/A";

    const chromeMatch = userAgent.match(/Chrome\/([\d.]+)/);
    const firefoxMatch = userAgent.match(/Firefox\/([\d.]+)/);
    const safariMatch = userAgent.match(/Safari\/([\d.]+)/);
    const edgeMatch = userAgent.match(/Edg\/([\d.]+)/);

    if (edgeMatch) return `Edge ${edgeMatch[1]}`;
    if (chromeMatch) return `Chrome ${chromeMatch[1]}`;
    if (firefoxMatch) return `Firefox ${firefoxMatch[1]}`;
    if (safariMatch) return `Safari ${safariMatch[1]}`;

    return userAgent.substring(0, 50) + "...";
  }

  function formatDate(dateString) {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function formatDateTime(dateString) {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    return date.toLocaleString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function formatRelativeTime(dateString) {
    if (!dateString) return "N/A";

    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return "Just now";
    if (diffMins < 60) return `${diffMins} min${diffMins > 1 ? "s" : ""} ago`;
    if (diffHours < 24)
      return `${diffHours} hour${diffHours > 1 ? "s" : ""} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? "s" : ""} ago`;

    return formatDateTime(dateString);
  }
  /**
   * Show Loading Spinner with Better Design
   */
  function showLoader(message = "Loading...") {
    const spinnerHTML = `
      <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
              <span class="visually-hidden">${message}</span>
          </div>
          <p class="text-muted fw-medium">${message}</p>
      </div>`;

    // Add to all containers
    const containers = [
      "activeAssignmentsContainer",
      "assignmentHistoryContainer",
      "passwordHistoryContainer",
      "loginHistoryContainer",
    ];

    containers.forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = spinnerHTML;
    });

    // Activity log gets timeline placeholder
    const activityContainer = document.getElementById("activityLogContainer");
    if (activityContainer) {
      activityContainer.innerHTML = `<li>${spinnerHTML}</li>`;
    }
  }
  function hideLoader() {
    // Loader handled per-tab
  }

  /**
   * Show Toast Notification (Updated with better styling)
   */
  function showToast(message, type = "info") {
    console.log(`[${type.toUpperCase()}] ${message}`);

    const bgColor =
      {
        success: "bg-success",
        error: "bg-danger",
        warning: "bg-warning",
        info: "bg-info",
      }[type] || "bg-info";

    const icon =
      {
        success: "ri-checkbox-circle-line",
        error: "ri-close-circle-line",
        warning: "ri-error-warning-line",
        info: "ri-information-line",
      }[type] || "ri-information-line";

    const toast = document.createElement("div");
    toast.className = `toast align-items-center text-white ${bgColor} border-0`;
    toast.setAttribute("role", "alert");
    toast.style.cssText =
      "position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;";

    toast.innerHTML = `
      <div class="d-flex">
          <div class="toast-body">
              <i class="${icon} me-2"></i>${message}
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>`;

    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();

    setTimeout(() => toast.remove(), 3500);
  }

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.ProfilePage = {
    viewAuditDetails: viewAuditDetails,
    loadActivityLog: loadActivityLog,
    loadLoginHistory: loadLoginHistory,
    loadPasswordHistory: loadPasswordHistory,
    showEditProfileModal: showEditProfileModal,
  };

  // ========================================================================
  // BOOTSTRAP
  // ========================================================================

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeProfilePage);
  } else {
    initializeProfilePage();
  }
})();
