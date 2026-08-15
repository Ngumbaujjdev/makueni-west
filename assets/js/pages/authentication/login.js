/**
 * Login Page Handler
 *
 * Handles both login methods:
 * 1. Employee Code Login (6-digit code only)
 * 2. Credentials Login (Email/Username + Password)
 *
 * Fetches and caches modules after successful authentication
 *
 * Dependencies: app.js, constants.js, toast.js
 */

(function () {
  ("use strict");

  // Wait for DOM to be fully loaded
  document.addEventListener("DOMContentLoaded", function () {
    initializeLoginPage();
  });

  /**
   * Initialize login page functionality
   */
  function initializeLoginPage() {
    // ✅ Check if user is already logged in (but don't force redirect)
    if (isUserLoggedIn()) {
      // Show info message with option to go to dashboard
      const currentRole = JSON.parse(
        localStorage.getItem("mwd_current_role") || "{}"
      );
      const userName =
        JSON.parse(localStorage.getItem("mwd_user_data") || "{}").firstname ||
        "User";

      Toast.confirm(
        `<strong>${userName}</strong>, you are already logged in. Do you want to go to your dashboard?`,
        function () {
          // User clicked "Yes" - go to dashboard
          redirectToDashboard();
        },
        function () {
          // User clicked "No" - stay on login page (maybe wants to login as different user)
          console.log("User chose to stay on login page");
        },
        {
          title: "Already Logged In",
          confirmText: "Go to Dashboard",
          cancelText: "Stay Here",
          type: "info",
        }
      );
    }

    // Initialize form handlers
    initializeCodeLogin();
    initializeCredentialsLogin();
    initializeUIEnhancements();
  }

  /**
   * Check if user is already logged in
   */
  function isUserLoggedIn() {
    const token = localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN);
    const sessionExpiry = localStorage.getItem(
      Constants.STORAGE_KEYS.SESSION_EXPIRY
    );

    if (token && sessionExpiry) {
      const expiryTime = new Date(sessionExpiry).getTime();
      const currentTime = new Date().getTime();

      return currentTime < expiryTime;
    }

    return false;
  }

  /**
   * Initialize Employee Code Login
   */
  function initializeCodeLogin() {
    const form = document.getElementById("codeLoginForm");
    const codeInput = document.getElementById("employee-code");

    if (!form || !codeInput) return;

    // Auto-format employee code input (numbers only)
    codeInput.addEventListener("input", function (e) {
      this.value = this.value.replace(/[^0-9]/g, "");
    });

    // Handle form submission
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      handleCodeLogin();
    });
  }

  /**
   * Initialize Credentials Login
   */
  function initializeCredentialsLogin() {
    const form = document.getElementById("credentialsLoginForm");

    if (!form) return;

    // Handle form submission
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      handleCredentialsLogin();
    });
  }

  /**
   * Handle Employee Code Login
   */
  async function handleCodeLogin() {
    const codeInput = document.getElementById("employee-code");
    const submitBtn = document.querySelector(
      '#codeLoginForm button[type="submit"]'
    );

    // Get and validate employee code
    const employeeCode = codeInput.value.trim();

    if (!validateEmployeeCode(employeeCode)) {
      Toast.error("Please enter a valid 6-digit employee code");
      codeInput.focus();
      return;
    }

    // Show loading state
    const originalBtnText = submitBtn.innerHTML;
    setButtonLoading(submitBtn, true);

    try {
      // Call API
      const response = await fetch(
        `${AppConfig.API_BASE_URL}${Constants.API_ENDPOINTS.LOGIN_CODE}`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: {
            "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
            Accept: Constants.HEADERS.ACCEPT_JSON,
          },
          body: JSON.stringify({
            employee_code: employeeCode,
          }),
        }
      );

      const data = await response.json();

      if (response.ok && data.success) {
        // Login successful
        await handleLoginSuccess(data.data);
      } else {
        // Login failed
        handleLoginError(data);
      }
    } catch (error) {
      console.error("Login error:", error);
      Toast.error(Constants.MESSAGES.NETWORK_ERROR);
    } finally {
      setButtonLoading(submitBtn, false, originalBtnText);
    }
  }

  /**
   * Handle Credentials Login (Email/Username + Password)
   */
  async function handleCredentialsLogin() {
    const identifierInput = document.getElementById("signin-identifier");
    const passwordInput = document.getElementById("signin-password");
    const rememberMeCheckbox = document.getElementById("remember-me");
    const submitBtn = document.querySelector(
      '#credentialsLoginForm button[type="submit"]'
    );

    // Get and validate inputs
    const identifier = identifierInput.value.trim();
    const password = passwordInput.value;
    const rememberMe = rememberMeCheckbox.checked;

    if (!validateCredentials(identifier, password)) {
      return;
    }

    // Show loading state
    const originalBtnText = submitBtn.innerHTML;
    setButtonLoading(submitBtn, true);

    try {
      // Call API
      const response = await fetch(
        `${AppConfig.API_BASE_URL}${Constants.API_ENDPOINTS.LOGIN}`,
        {
          method: Constants.HTTP_METHODS.POST,
          headers: {
            "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
            Accept: Constants.HEADERS.ACCEPT_JSON,
          },
          body: JSON.stringify({
            identifier: identifier,
            password: password,
          }),
        }
      );

      const data = await response.json();

      if (response.ok && data.success) {
        // Save remember me preference
        if (rememberMe) {
          localStorage.setItem(Constants.STORAGE_KEYS.REMEMBER_ME, "true");
        }

        // Login successful
        await handleLoginSuccess(data.data);
      } else {
        // Check for password expired (status 409)
        if (
          response.status === Constants.HTTP_STATUS.CONFLICT &&
          data.data?.requires_password_change
        ) {
          handlePasswordExpired(data.data);
        } else {
          // Login failed
          handleLoginError(data);
        }
      }
    } catch (error) {
      console.error("Login error:", error);
      Toast.error(Constants.MESSAGES.NETWORK_ERROR);
    } finally {
      setButtonLoading(submitBtn, false, originalBtnText);
    }
  }

  /**
   * Handle successful login
   */
  async function handleLoginSuccess(data) {
    try {
      // Extract data from response
      const {
        token,
        user,
        permissions,
        territorial_roles,
        current_role,
        password_warning,
      } = data;

      // Store in localStorage
      localStorage.setItem(Constants.STORAGE_KEYS.AUTH_TOKEN, token);
      localStorage.setItem(
        Constants.STORAGE_KEYS.USER_DATA,
        JSON.stringify(user)
      );
      localStorage.setItem(
        Constants.STORAGE_KEYS.PERMISSIONS,
        JSON.stringify(permissions)
      );
      localStorage.setItem(
        Constants.STORAGE_KEYS.TERRITORIAL_ROLES,
        JSON.stringify(territorial_roles)
      );
      localStorage.setItem(
        Constants.STORAGE_KEYS.CURRENT_ROLE,
        JSON.stringify(current_role)
      );
      localStorage.setItem(
        Constants.STORAGE_KEYS.LAST_LOGIN,
        new Date().toISOString()
      );

      // Calculate and store session expiry
      const expiryTime = new Date(
        Date.now() + AppConfig.SESSION_TIMEOUT * 60 * 1000
      );
      localStorage.setItem(
        Constants.STORAGE_KEYS.SESSION_EXPIRY,
        expiryTime.toISOString()
      );

      // ✅ NEW: Fetch and cache modules with complete nested hierarchy
      console.log("📦 Fetching user modules...");
      const modulesLoaded = await fetchAndCacheModules(token, current_role);

      if (!modulesLoaded) {
        console.warn("⚠️ Failed to load modules, but continuing with login");
      } else {
        console.log("✅ Modules loaded and cached successfully");
      }

      // Sync to PHP session
      const syncSuccess = await syncSessionToPHP({
        token: token,
        user: user,
        permissions: permissions,
        territorial_roles: territorial_roles,
        current_role: current_role,
      });

      if (!syncSuccess) {
        console.warn(
          "Failed to sync session to PHP, but continuing with localStorage"
        );
      }

      // Show password warning if applicable
      if (password_warning) {
        Toast.warning(password_warning.message, { duration: 5000 });
        // Still redirect after showing warning
        setTimeout(() => redirectToDashboard(), 2000);
      } else {
        // Show success and redirect
        Toast.success(Constants.MESSAGES.LOGIN_SUCCESS);
        setTimeout(() => redirectToDashboard(), 1000);
      }
    } catch (error) {
      console.error("Error handling login success:", error);
      Toast.error(
        "Login successful but there was an error. Please try refreshing the page."
      );
    }
  }

  /**
   * ✅ Fetch and cache user modules from backend
   * - Global Admins: Get ALL diocese modules (no filtering)
   * - Regular Roles: Get ONLY modules with permissions (filtered)
   */
  async function fetchAndCacheModules(token, currentRole) {
    try {
      // Check if user is Global Admin by checking for 'global' territory type
      const userData = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA) || '{}');
      const hasGlobalTerritory = userData.active_assignments?.some(
        assignment => assignment.territory?.territory_type === 'global'
      );
      const isGlobalAdmin = hasGlobalTerritory || false;
      
      // ✅ Use different endpoints based on user type
      const endpoint = isGlobalAdmin 
        ? `${AppConfig.API_BASE_URL}/modules`  // Global Admin: ALL modules
        : `${AppConfig.API_BASE_URL}/modules/for-role`;  // Regular: Filtered by permissions
      
      console.log(`📡 Fetching modules as ${isGlobalAdmin ? 'Global Admin' : 'Regular Role'} from: ${endpoint}`);
      
      const response = await fetch(endpoint, {
        method: Constants.HTTP_METHODS.GET,
        headers: {
          "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
          Accept: Constants.HEADERS.ACCEPT_JSON,
          Authorization: `Bearer ${token}`,
        },
      });

      if (!response.ok) {
        console.error("Failed to fetch modules:", response.status);
        
        // Handle no primary assignment error (only for regular roles)
        if (response.status === 404 && !isGlobalAdmin) {
          console.error("No primary assignment found for user");
        }
        
        return false;
      }

      const result = await response.json();

      if (result.success && result.data) {
        const {
          module_groups,
          territory_scope,
          total_modules,
          total_groups,
          user_assignment,
          filtered_by_permissions,
          is_global_admin,
        } = result.data;

        // Store modules with metadata
        const modulesCache = {
          module_groups: module_groups,
          territory_scope: territory_scope,
          total_modules: total_modules,
          total_groups: total_groups || 0,
          cached_at: new Date().toISOString(),
          role_assignment_id: user_assignment?.assignment_id || currentRole?.assignment_id || null,
          role_id: user_assignment?.role?.id || null,
          role_name: user_assignment?.role?.name || null,
          filtered_by_permissions: filtered_by_permissions !== undefined ? filtered_by_permissions : !isGlobalAdmin,
          is_global_admin: is_global_admin || isGlobalAdmin,
        };

        // Cache in localStorage with role-specific key
        const roleId = user_assignment?.role?.id || currentRole?.role_id || "global";
        const cacheKey = `modules_${territory_scope}_role_${roleId}`;
        localStorage.setItem(cacheKey, JSON.stringify(modulesCache));

        // Also store as "current modules" for easy access
        localStorage.setItem(
          "mwd_current_modules",
          JSON.stringify(modulesCache)
        );

        const roleInfo = isGlobalAdmin 
          ? 'Global Administrator (ALL modules)' 
          : `${user_assignment?.role?.name || 'role'} (filtered by permissions)`;
        
        console.log(`✅ Cached ${total_groups} groups with ${total_modules} modules for ${roleInfo} (${territory_scope})`);
        console.log(`🔒 Filtered by permissions: ${modulesCache.filtered_by_permissions}`);
        console.log(`📊 Module groups:`, {
          total_groups: total_groups,
          total_modules: total_modules,
          territory_scope: territory_scope,
          is_global_admin: isGlobalAdmin,
          role_name: user_assignment?.role?.name || 'Global Admin',
          group_names: module_groups.map((g) => g.name),
        });

        return true;
      }

      return false;
    } catch (error) {
      console.error("Error fetching modules:", error);
      return false;
    }
  }

  /**
   * Sync session data to PHP backend
   */
  async function syncSessionToPHP(sessionData) {
    try {
      // ✅ FIXED: Absolute path without .php extension
      const response = await fetch(
        "/makueni-west/authentication/ajax/sync-session",
        {
          method: Constants.HTTP_METHODS.POST,
          headers: {
            "Content-Type": Constants.HEADERS.CONTENT_TYPE_JSON,
          },
          body: JSON.stringify(sessionData),
        }
      );

      const result = await response.json();
      return result.success === true;
    } catch (error) {
      console.error("Session sync error:", error);
      return false;
    }
  }

  /**
   * Handle password expired scenario
   */
  function handlePasswordExpired(data) {
    // Store user_id for password change page
    sessionStorage.setItem("password_change_user_id", data.user_id);

    // Show message
    Toast.warning(Constants.MESSAGES.PASSWORD_EXPIRED, { duration: 4000 });

    // Redirect to force password change page
    setTimeout(() => {
      window.location.href = AppConfig.REDIRECT_URLS.FORCE_PASSWORD_CHANGE;
    }, 2000);
  }

  /**
   * Handle login errors
   */
  function handleLoginError(data) {
    const message = data.message || Constants.MESSAGES.LOGIN_FAILED;
    Toast.error(message);
  }

  /**
   * ✅ FIXED: Redirect to appropriate dashboard based on user's territorial level
   * Checks for global admin FIRST, then uses territory_scope from modules
   */
  function redirectToDashboard() {
    try {
      // Get current role and user data from localStorage
      const currentRoleData = localStorage.getItem(
        Constants.STORAGE_KEYS.CURRENT_ROLE
      );
      const userData = localStorage.getItem(Constants.STORAGE_KEYS.USER_DATA);
      const modulesData = localStorage.getItem("mwd_current_modules");

      if (!currentRoleData || !userData) {
        console.error("No current role or user data found in localStorage");
        window.location.href = "/makueni-west/errors/no-dashboard.php";
        return;
      }

      const currentRole = JSON.parse(currentRoleData);
      const user = JSON.parse(userData);
      const modules = modulesData ? JSON.parse(modulesData) : null;

      // ✅ CRITICAL FIX: Check modules territory_scope FIRST (this is most reliable)
      // For global admins, territory_scope will be "diocese" (which is correct!)
      const territoryScope =
        modules?.territory_scope ||
        currentRole.territory_type ||
        currentRole.territory?.territory_type;
      const isGlobalAdmin =
        modules?.is_global_admin || user.super_admin_config?.global_access;

      console.log("Territory type detected:", territoryScope);
      console.log("Is global admin:", isGlobalAdmin);

      // Determine redirect URL based on territory scope
      let redirectUrl;

      switch (territoryScope) {
        case "diocese":
        case "global": // Just in case
          redirectUrl = "/makueni-west/diocese/dashboard/";
          break;

        case "region":
          redirectUrl = "/makueni-west/region/dashboard/";
          break;

        case "subregion":
          redirectUrl = "/makueni-west/region/dashboard/";
          break;

        case "church":
          redirectUrl = "/makueni-west/church/dashboard/";
          break;

        default:
          console.error("Unknown territory type:", territoryScope);
          window.location.href = "/makueni-west/errors/no-dashboard.php";
          return;
      }

      // Log redirect for debugging
      console.log("Redirecting to dashboard:", {
        isGlobalAdmin: isGlobalAdmin,
        territoryScope: territoryScope,
        redirectUrl: redirectUrl,
      });

      // Perform redirect
      window.location.href = redirectUrl;
    } catch (error) {
      console.error("Error determining redirect URL:", error);
      window.location.href = "/makueni-west/errors/no-dashboard.php";
    }
  }

  /**
   * Validate employee code
   */
  function validateEmployeeCode(code) {
    return Constants.VALIDATION.EMPLOYEE_CODE_REGEX.test(code);
  }

  /**
   * Validate credentials
   */
  function validateCredentials(identifier, password) {
    if (!identifier) {
      Toast.error("Please enter your email or username");
      document.getElementById("signin-identifier").focus();
      return false;
    }

    if (!password) {
      Toast.error("Please enter your password");
      document.getElementById("signin-password").focus();
      return false;
    }

    if (password.length < AppConfig.PASSWORD_MIN_LENGTH) {
      Toast.error(
        `Password must be at least ${AppConfig.PASSWORD_MIN_LENGTH} characters`
      );
      document.getElementById("signin-password").focus();
      return false;
    }

    return true;
  }

  /**
   * Set button loading state
   */
  function setButtonLoading(button, isLoading, originalText = "") {
    if (isLoading) {
      button.disabled = true;
      button.innerHTML = `
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                ${Constants.UI.PROCESSING_TEXT}
            `;
    } else {
      button.disabled = false;
      button.innerHTML = originalText;
    }
  }

  /**
   * Initialize UI enhancements
   */
  function initializeUIEnhancements() {
    // Focus on employee code input when code tab is shown
    document
      .getElementById("code-tab")
      ?.addEventListener("shown.bs.tab", function () {
        document.getElementById("employee-code")?.focus();
      });

    // Focus on identifier input when credentials tab is shown
    document
      .getElementById("credentials-tab")
      ?.addEventListener("shown.bs.tab", function () {
        document.getElementById("signin-identifier")?.focus();
      });

    // Auto-focus on first input on page load
    setTimeout(() => {
      document.getElementById("employee-code")?.focus();
    }, 500);
  }

  /**
   * Password visibility toggle
   */
  window.createpassword = function (inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector("i");

    if (input.type === "password") {
      input.type = "text";
      icon.classList.remove("ri-eye-off-line");
      icon.classList.add("ri-eye-line");
    } else {
      input.type = "password";
      icon.classList.remove("ri-eye-line");
      icon.classList.add("ri-eye-off-line");
    }
  };
})();
