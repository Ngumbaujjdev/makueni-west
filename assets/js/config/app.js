/**
 * Application Configuration
 *
 * Central configuration file for the Makueni West Diocese Management System
 * Contains API endpoints, application settings, and environment configurations
 */

const AppConfig = {
  // Backend API Configuration
  API_BASE_URL: "http://127.0.0.1:8000/api",

  // Frontend Base URL
  FRONTEND_BASE_URL: "/makueni-west",

  // Application Information
  APP_NAME: "Makueni West Diocese Management System",
  APP_SHORT_NAME: "MWD Management",
  DIOCESE_ACRONYM: "CCI-MWD",

  // Session Configuration
  SESSION_TIMEOUT: 480, // 8 hours in minutes (matches backend)
  SESSION_WARNING_TIME: 5, // Show warning 5 minutes before timeout

  // API Request Configuration
  REQUEST_TIMEOUT: 30000, // 30 seconds
  MAX_RETRIES: 3,

  // Pagination
  DEFAULT_PAGE_SIZE: 20,
  PAGE_SIZE_OPTIONS: [10, 20, 50, 100],

  // File Upload Configuration
  MAX_FILE_SIZE: 10240, // 10MB in KB (matches backend)
  ALLOWED_FILE_TYPES: [
    "pdf",
    "doc",
    "docx",
    "xls",
    "xlsx",
    "jpg",
    "jpeg",
    "png",
  ],

  // Diocese Configuration
  TIMEZONE: "Africa/Nairobi",
  CURRENCY: "KES",
  CURRENCY_SYMBOL: "KSh",
  DATE_FORMAT: "DD/MM/YYYY",
  DATETIME_FORMAT: "DD/MM/YYYY HH:mm",

  // Territorial Levels (matches backend hierarchy)
  TERRITORY_LEVELS: {
    GLOBAL: "global",
    DIOCESE: "diocese",
    REGION: "region",
    SUBREGION: "subregion",
    CHURCH: "church",
  },

  // Assignment Types
  ASSIGNMENT_TYPES: {
    PRIMARY: "primary",
    SECONDARY: "secondary",
    TEMPORARY: "temporary",
  },

  // User Status
  USER_STATUS: {
    ACTIVE: "active",
    INACTIVE: "inactive",
    SUSPENDED: "suspended",
  },

  // Password Configuration
  PASSWORD_MIN_LENGTH: 8,
  PASSWORD_EXPIRY_DAYS: 180, // 6 months
  PASSWORD_EXPIRY_WARNING_DAYS: 14,

  // Employee Code Configuration
  EMPLOYEE_CODE_LENGTH: 6,

  // Permission Actions
  PERMISSION_ACTIONS: {
    CREATE: "create",
    READ: "read",
    UPDATE: "update",
    DELETE: "delete",
    APPROVE: "approve",
    EXPORT: "export",
    IMPORT: "import",
  },

  // Toast Notification Duration
  TOAST_DURATION: {
    SUCCESS: 3000,
    ERROR: 5000,
    WARNING: 4000,
    INFO: 3000,
  },

  // Development/Debug Mode
  DEBUG_MODE: true, // Set to false in production
  LOG_API_REQUESTS: true, // Set to false in production

  // Redirect URLs (ABSOLUTE PATHS)
  REDIRECT_URLS: {
    // Dynamic - determined by territory type
    AFTER_LOGIN: null,

    // Static redirects (ABSOLUTE)
    AFTER_LOGOUT: "/makueni-west/",
    FORCE_PASSWORD_CHANGE:
      "/makueni-west/authentication/force-password-change",
    UNAUTHORIZED: "/makueni-west/errors/403",

    // Territory-specific dashboards (ABSOLUTE PATHS)
    DASHBOARDS: {
      GLOBAL: "/makueni-west/diocese/dashboard/",
      DIOCESE: "/makueni-west/diocese/dashboard/",
      REGION: "/makueni-west/region/dashboard/",
      SUBREGION: "/makueni-west/region/dashboard/",
      CHURCH: "/makueni-west/church/dashboard/",
    },
  },

  // Error Pages (ABSOLUTE PATHS)
  ERROR_PAGES: {
    NOT_FOUND: "/makueni-west/errors/404.php",
    FORBIDDEN: "/makueni-west/errors/403.php",
    SERVER_ERROR: "/makueni-west/errors/500.php",
    SESSION_EXPIRED:
      "/makueni-west/errors/session-expired.php",
    NO_DASHBOARD: "/makueni-west/errors/no-dashboard.php",
  },
};

// Freeze config to prevent modifications
Object.freeze(AppConfig);
Object.freeze(AppConfig.TERRITORY_LEVELS);
Object.freeze(AppConfig.ASSIGNMENT_TYPES);
Object.freeze(AppConfig.USER_STATUS);
Object.freeze(AppConfig.PERMISSION_ACTIONS);
Object.freeze(AppConfig.TOAST_DURATION);
Object.freeze(AppConfig.REDIRECT_URLS);
Object.freeze(AppConfig.REDIRECT_URLS.DASHBOARDS);
Object.freeze(AppConfig.ERROR_PAGES);

// Export for use in other files
if (typeof module !== "undefined" && module.exports) {
  module.exports = AppConfig;
}
