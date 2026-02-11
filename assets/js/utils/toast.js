/**
 * Toast Notification System
 *
 * Displays Bootstrap 5 toast notifications
 * Supports: success, error, warning, info, primary, secondary, confirm (with actions)
 */

const Toast = (function () {
  "use strict";

  // Toast configuration
  const config = {
    position: "top-0 end-0", // top-right corner
    duration: 3000, // 3 seconds default
    appName: "MWD System",
    logoUrl: "assets/images/brand-logos/toggle-dark.png",
  };

  // Toast types mapping to Bootstrap classes
  const toastTypes = {
    success: { bg: "bg-success", icon: "ri-checkbox-circle-line" },
    error: { bg: "bg-danger", icon: "ri-error-warning-line" },
    warning: { bg: "bg-warning", icon: "ri-alert-line" },
    info: { bg: "bg-info", icon: "ri-information-line" },
    primary: { bg: "bg-primary", icon: "ri-notification-line" },
    secondary: { bg: "bg-secondary", icon: "ri-notification-line" },
  };

  /**
   * Initialize toast container if it doesn't exist
   */
  function initToastContainer() {
    let container = document.querySelector(".toast-container");

    if (!container) {
      container = document.createElement("div");
      container.className = `toast-container position-fixed ${config.position} p-3`;
      container.style.zIndex = "9999";
      document.body.appendChild(container);
    }

    return container;
  }

  /**
   * Create toast HTML element
   */
  function createToastElement(message, type = "info", title = null) {
    const toastConfig = toastTypes[type] || toastTypes.info;
    const toastId = `toast-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 9)}`;
    const toastTitle = title || config.appName;

    const toastHTML = `
      <div id="${toastId}" class="toast colored-toast ${toastConfig.bg} text-fixed-white" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header ${toastConfig.bg} text-fixed-white">
          <i class="${toastConfig.icon} me-2"></i>
          <strong class="me-auto">${toastTitle}</strong>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          ${message}
        </div>
      </div>
    `;

    const tempDiv = document.createElement("div");
    tempDiv.innerHTML = toastHTML.trim();
    return tempDiv.firstChild;
  }

  /**
   * ✅ NEW: Create confirmation toast with action buttons
   */
  function createConfirmToastElement(message, options = {}) {
    const toastId = `toast-confirm-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 9)}`;
    const toastTitle = options.title || "Confirmation Required";
    const confirmText = options.confirmText || "Yes, Continue";
    const cancelText = options.cancelText || "Cancel";
    const type = options.type || "warning";
    const toastConfig = toastTypes[type] || toastTypes.warning;

    const toastHTML = `
      <div id="${toastId}" class="toast colored-toast ${toastConfig.bg} text-fixed-white" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
        <div class="toast-header ${toastConfig.bg} text-fixed-white">
          <i class="${toastConfig.icon} me-2"></i>
          <strong class="me-auto">${toastTitle}</strong>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          ${message}
          <div class="mt-3 pt-2 border-top border-light">
            <button type="button" class="btn btn-sm btn-light me-2" data-action="confirm">
              <i class="ri-check-line me-1"></i>${confirmText}
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" data-action="cancel">
              ${cancelText}
            </button>
          </div>
        </div>
      </div>
    `;

    const tempDiv = document.createElement("div");
    tempDiv.innerHTML = toastHTML.trim();
    return tempDiv.firstChild;
  }

  /**
   * Show toast notification
   */
  function show(message, type = "info", options = {}) {
    const container = initToastContainer();
    const toastElement = createToastElement(
      message,
      type,
      options.title || null
    );

    // Add to container
    container.appendChild(toastElement);

    // Initialize Bootstrap toast
    const bsToast = new bootstrap.Toast(toastElement, {
      autohide: options.autohide !== false,
      delay: options.duration || config.duration,
    });

    // Show toast
    bsToast.show();

    // Remove from DOM after hidden
    toastElement.addEventListener("hidden.bs.toast", function () {
      toastElement.remove();
    });

    return bsToast;
  }

  /**
   * ✅ NEW: Show confirmation toast with action buttons
   */
  function confirm(message, onConfirm, onCancel, options = {}) {
    const container = initToastContainer();
    const toastElement = createConfirmToastElement(message, options);

    // Add to container
    container.appendChild(toastElement);

    // Initialize Bootstrap toast (no autohide for confirmation)
    const bsToast = new bootstrap.Toast(toastElement, {
      autohide: false,
    });

    // Handle confirm button
    const confirmBtn = toastElement.querySelector('[data-action="confirm"]');
    confirmBtn.addEventListener("click", function () {
      bsToast.hide();
      if (onConfirm && typeof onConfirm === "function") {
        onConfirm();
      }
    });

    // Handle cancel button
    const cancelBtn = toastElement.querySelector('[data-action="cancel"]');
    cancelBtn.addEventListener("click", function () {
      bsToast.hide();
      if (onCancel && typeof onCancel === "function") {
        onCancel();
      }
    });

    // Handle close button (X)
    const closeBtn = toastElement.querySelector(".btn-close");
    closeBtn.addEventListener("click", function () {
      if (onCancel && typeof onCancel === "function") {
        onCancel();
      }
    });

    // Show toast
    bsToast.show();

    // Remove from DOM after hidden
    toastElement.addEventListener("hidden.bs.toast", function () {
      toastElement.remove();
    });

    return bsToast;
  }

  /**
   * Show success toast
   */
  function success(message, options = {}) {
    return show(message, "success", options);
  }

  /**
   * Show error toast
   */
  function error(message, options = {}) {
    return show(message, "error", {
      duration: 5000, // Errors stay longer
      ...options,
    });
  }

  /**
   * Show warning toast
   */
  function warning(message, options = {}) {
    return show(message, "warning", {
      duration: 4000,
      ...options,
    });
  }

  /**
   * Show info toast
   */
  function info(message, options = {}) {
    return show(message, "info", options);
  }

  /**
   * Show primary toast
   */
  function primary(message, options = {}) {
    return show(message, "primary", options);
  }

  /**
   * Show secondary toast
   */
  function secondary(message, options = {}) {
    return show(message, "secondary", options);
  }

  /**
   * Hide all toasts
   */
  function hideAll() {
    const toasts = document.querySelectorAll(".toast");
    toasts.forEach((toastEl) => {
      const bsToast = bootstrap.Toast.getInstance(toastEl);
      if (bsToast) {
        bsToast.hide();
      }
    });
  }

  // Public API
  return {
    show,
    confirm, // ✅ NEW
    success,
    error,
    warning,
    info,
    primary,
    secondary,
    hideAll,
    config,
  };
})();

// Export for use in other files
if (typeof module !== "undefined" && module.exports) {
  module.exports = Toast;
}
