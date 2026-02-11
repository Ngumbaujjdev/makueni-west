/**
 * Application Constants
 *
 * Defines constant values used throughout the application
 * Includes storage keys, API endpoints, messages, and system-wide constants
 */

const Constants = {
  // ===== LOCAL STORAGE KEYS =====
  STORAGE_KEYS: {
    AUTH_TOKEN: "mwd_auth_token",
    USER_DATA: "mwd_user_data",
    PERMISSIONS: "mwd_permissions",
    TERRITORIAL_ROLES: "mwd_territorial_roles",
    CURRENT_ROLE: "mwd_current_role",
    REMEMBER_ME: "mwd_remember_me",
    LAST_LOGIN: "mwd_last_login",
    SESSION_EXPIRY: "mwd_session_expiry",
  },

  // ===== API ENDPOINTS =====
  API_ENDPOINTS: {
    // Authentication
    LOGIN: "/auth/login",
    LOGIN_CODE: "/auth/login-code",
    LOGOUT: "/auth/logout",
    SWITCH_ROLE: "/auth/switch-role",
    PROFILE_UPDATE: "/auth/profile",
    SUPPORT_REQUEST: "/auth/support",

    // Password Management
    FORCE_PASSWORD_CHANGE: "/auth/force-password-change",
    CHANGE_PASSWORD: "/password-reset/change-password",
    CHANGE_EMPLOYEE_CODE: "/password-reset/change-employee-code",
    REQUEST_PASSWORD_RESET: "/password-reset/request",
    VERIFY_RESET_TOKEN: "/password-reset/verify-token",
    RESET_WITH_TOKEN: "/password-reset/reset-with-token",

    // Users
    USERS: "/users",
    USER_DETAIL: "/users/:id",

    // Roles & Permissions
    ROLES: "/roles",
    ROLE_DETAIL: "/roles/:id",
    ROLE_PERMISSIONS: "/roles/:id/permissions",
    ROLE_USERS: "/roles/:id/users",
    PERMISSIONS: "/permissions",
    PERMISSIONS_GROUPED: "/permissions/grouped",

    // Territories
    TERRITORIES: "/territories",
    TERRITORY_DETAIL: "/territories/:id",
    TERRITORY_HIERARCHY: "/territories/hierarchy/tree",
    TERRITORIES_BY_TYPE: "/territories/by-type/list",

    // User Assignments
    USER_ASSIGNMENTS: "/user-assignments",
    USER_ASSIGNMENT_DETAIL: "/user-assignments/:id",
    USER_ASSIGNMENTS_BY_USER: "/user-assignments/user/:userId",
    USER_ASSIGNMENTS_BY_TERRITORY: "/user-assignments/territory/:territoryId",

    // Modules
    MODULES: "/modules",
    MODULE_DETAIL: "/modules/:id",
    MODULE_SUBMODULES: "/modules/:id/submodules",
  },

  // ===== HTTP METHODS =====
  HTTP_METHODS: {
    GET: "GET",
    POST: "POST",
    PUT: "PUT",
    DELETE: "DELETE",
    PATCH: "PATCH",
  },

  // ===== HTTP STATUS CODES =====
  HTTP_STATUS: {
    OK: 200,
    CREATED: 201,
    NO_CONTENT: 204,
    BAD_REQUEST: 400,
    UNAUTHORIZED: 401,
    FORBIDDEN: 403,
    NOT_FOUND: 404,
    CONFLICT: 409,
    UNPROCESSABLE_ENTITY: 422,
    SERVER_ERROR: 500,
  },

  // ===== RESPONSE MESSAGES =====
  MESSAGES: {
    // Success Messages
    LOGIN_SUCCESS: "Login successful! Redirecting...",
    LOGOUT_SUCCESS: "Logged out successfully",
    PROFILE_UPDATE_SUCCESS: "Profile updated successfully",
    PASSWORD_CHANGE_SUCCESS: "Password changed successfully",
    ROLE_SWITCH_SUCCESS: "Role switched successfully",

    // Error Messages
    LOGIN_FAILED: "Login failed. Please check your credentials.",
    INVALID_EMPLOYEE_CODE: "Invalid employee code. Please try again.",
    SESSION_EXPIRED: "Your session has expired. Please login again.",
    UNAUTHORIZED_ACCESS: "You do not have permission to access this resource.",
    NETWORK_ERROR: "Network error. Please check your connection.",
    SERVER_ERROR: "Server error. Please try again later.",
    VALIDATION_ERROR: "Please check your input and try again.",

    // Password Messages
    PASSWORD_EXPIRED:
      "Your password has expired. Please change it to continue.",
    PASSWORD_EXPIRING_SOON:
      "Your password will expire soon. Please consider changing it.",
    WEAK_PASSWORD: "Password is too weak. Please use a stronger password.",
    PASSWORD_MISMATCH: "Passwords do not match.",

    // Account Messages
    ACCOUNT_LOCKED: "Account locked. Please contact administrator.",
    ACCOUNT_INACTIVE: "Account is inactive. Please contact administrator.",

    // Validation Messages
    REQUIRED_FIELD: "This field is required",
    INVALID_EMAIL: "Please enter a valid email address",
    INVALID_CODE: "Please enter a valid 6-digit code",
    MIN_LENGTH: "Minimum length is :length characters",
    MAX_LENGTH: "Maximum length is :length characters",
  },

  // ===== VALIDATION RULES =====
  VALIDATION: {
    EMAIL_REGEX: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    EMPLOYEE_CODE_REGEX: /^\d{6}$/,
    PHONE_REGEX: /^(\+254|0)[17]\d{8}$/,
    USERNAME_REGEX: /^[a-zA-Z0-9._-]{3,20}$/,
  },

  // ===== UI CONSTANTS =====
  UI: {
    LOADING_TEXT: "Please wait...",
    PROCESSING_TEXT: "Processing...",
    SUBMITTING_TEXT: "Submitting...",
    SAVING_TEXT: "Saving...",
    DELETING_TEXT: "Deleting...",
    LOADING_SPINNER:
      '<i class="ri-loader-4-line spinner-border spinner-border-sm"></i>',

    // Toast Types
    TOAST_TYPES: {
      SUCCESS: "success",
      ERROR: "error",
      WARNING: "warning",
      INFO: "info",
    },

    // Button States
    BTN_LOADING_CLASS: "btn-loading",
    BTN_DISABLED_CLASS: "disabled",
  },

  // ===== REQUEST HEADERS =====
  HEADERS: {
    CONTENT_TYPE_JSON: "application/json",
    CONTENT_TYPE_FORM: "application/x-www-form-urlencoded",
    ACCEPT_JSON: "application/json",
    AUTHORIZATION_PREFIX: "Bearer ",
  },

  // ===== EVENTS =====
  EVENTS: {
    AUTH_TOKEN_UPDATED: "auth:token:updated",
    USER_LOGGED_IN: "auth:user:logged-in",
    USER_LOGGED_OUT: "auth:user:logged-out",
    ROLE_SWITCHED: "auth:role:switched",
    SESSION_EXPIRED: "auth:session:expired",
    PERMISSION_CHANGED: "auth:permission:changed",
  },

  // ===== TERRITORIAL CONSTANTS =====
  TERRITORY: {
    HIERARCHY_ORDER: ["global", "diocese", "region", "subregion", "church"],
    LEVEL_NAMES: {
      global: "Global",
      diocese: "Diocese",
      region: "Region",
      subregion: "Sub-Region",
      church: "Church",
    },
  },

  // ===== TERRITORY LEVELS =====
  TERRITORY_LEVELS: {
    GLOBAL: "global",
    DIOCESE: "diocese",
    REGION: "region",
    SUBREGION: "subregion",
    CHURCH: "church",
  },

  // ===== REDIRECT URLS =====
  REDIRECT_URLS: {
    DASHBOARDS: {
      DIOCESE: "/makueni-west/diocese/dashboard/",
      REGION: "/makueni-west/region/dashboard/",
      SUBREGION: "/makueni-west/region/dashboard/",
      CHURCH: "/makueni-west/church/dashboard/",
    },
    FORCE_PASSWORD_CHANGE:
      "/makueni-west/authentication/force-password-change.php",
    LOGIN: "/makueni-west/",
  },

  // ===== ERROR PAGES =====
  ERROR_PAGES: {
    NO_DASHBOARD: "/makueni-west/errors/no-dashboard.php",
    UNAUTHORIZED: "/makueni-west/errors/unauthorized.php",
    NOT_FOUND: "/makueni-west/errors/404.php",
  },

  // ===== DATE/TIME FORMATS =====
  DATE_FORMATS: {
    DISPLAY: "DD/MM/YYYY",
    DISPLAY_WITH_TIME: "DD/MM/YYYY HH:mm",
    API: "YYYY-MM-DD",
    API_WITH_TIME: "YYYY-MM-DD HH:mm:ss",
    RELATIVE: "relative", // e.g., "2 hours ago"
  },
};

// Freeze constants to prevent modifications
Object.freeze(Constants);
Object.freeze(Constants.STORAGE_KEYS);
Object.freeze(Constants.API_ENDPOINTS);
Object.freeze(Constants.HTTP_METHODS);
Object.freeze(Constants.HTTP_STATUS);
Object.freeze(Constants.MESSAGES);
Object.freeze(Constants.VALIDATION);
Object.freeze(Constants.UI);
Object.freeze(Constants.UI.TOAST_TYPES);
Object.freeze(Constants.HEADERS);
Object.freeze(Constants.EVENTS);
Object.freeze(Constants.TERRITORY);
Object.freeze(Constants.TERRITORY_LEVELS);
Object.freeze(Constants.REDIRECT_URLS);
Object.freeze(Constants.REDIRECT_URLS.DASHBOARDS);
Object.freeze(Constants.ERROR_PAGES);
Object.freeze(Constants.DATE_FORMATS);

// Export for use in other files
if (typeof module !== "undefined" && module.exports) {
  module.exports = Constants;
}
