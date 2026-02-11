/**
 * ============================================================================
 * USER MANAGEMENT - UTILITY FUNCTIONS
 * ============================================================================
 * Helper functions for formatting, validation, and utilities
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // FORMATTERS
  // ========================================================================

  /**
   * Get initials from full name
   */
  function getInitials(fullName) {
    if (!fullName) return "?";
    const names = fullName.trim().split(" ");
    if (names.length === 1) return names[0].charAt(0).toUpperCase();
    return (
      names[0].charAt(0) + names[names.length - 1].charAt(0)
    ).toUpperCase();
  }

  /**
   * Get status badge HTML
   */
  function getStatusBadge(status) {
    const badges = {
      active: '<span class="badge bg-success">Active</span>',
      inactive: '<span class="badge bg-secondary">Inactive</span>',
      suspended: '<span class="badge bg-danger">Suspended</span>',
    };
    return (
      badges[status?.toLowerCase()] ||
      '<span class="badge bg-warning">Unknown</span>'
    );
  }

  /**
   * Get territory badge HTML
   */
  function getTerritoryBadge(territory) {
    const badges = {
      diocese: '<span class="badge bg-primary">Diocese</span>',
      region: '<span class="badge bg-info">Region</span>',
      subregion: '<span class="badge bg-success">Sub-Region</span>',
      church: '<span class="badge bg-warning">Church</span>',
    };
    return badges[territory?.toLowerCase()] || territory;
  }

  /**
   * Get action badge HTML
   */
  function getActionBadge(action) {
    const badges = {
      create: '<span class="badge bg-success">Create</span>',
      read: '<span class="badge bg-info">Read</span>',
      update: '<span class="badge bg-warning">Update</span>',
      delete: '<span class="badge bg-danger">Delete</span>',
      approve: '<span class="badge bg-primary">Approve</span>',
      export: '<span class="badge bg-secondary">Export</span>',
    };
    return badges[action?.toLowerCase()] || action;
  }

  /**
   * Capitalize first letter
   */
  function capitalize(str) {
    if (!str) return "";
    return str.charAt(0).toUpperCase() + str.slice(1);
  }

  /**
   * Format date to readable string
   */
  function formatDate(dateString) {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  // ========================================================================
  // GENERATORS
  // ========================================================================

  /**
   * Generate random 6-digit employee code
   */
  function generateEmployeeCode() {
    return String(Math.floor(100000 + Math.random() * 900000));
  }

  /**
   * Generate default password
   */
  function generatePassword() {
    const year = new Date().getFullYear();
    return `Diocese@${year}`;
  }

  // ========================================================================
  // UTILITIES
  // ========================================================================

  /**
   * Debounce function for search inputs
   */
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * Copy text to clipboard
   */
  async function copyToClipboard(text) {
    try {
      await navigator.clipboard.writeText(text);
      Toast.success("Copied to clipboard!");
      return true;
    } catch (error) {
      console.error("Failed to copy:", error);
      Toast.error("Failed to copy to clipboard");
      return false;
    }
  }

  // ========================================================================
  // TABLE UTILITIES
  // ========================================================================

  /**
   * Show loading spinner in table
   */
  function showTableLoader(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (tbody) {
      const colCount =
        tbody.closest("table")?.querySelector("thead tr")?.children.length || 7;
      tbody.innerHTML = `
        <tr>
          <td colspan="${colCount}" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2 mb-0">Loading data...</p>
          </td>
        </tr>`;
    }
  }

  /**
   * Show error message in table
   */
  function showTableError(tbodyId, message) {
    const tbody = document.getElementById(tbodyId);
    if (tbody) {
      const colCount =
        tbody.closest("table")?.querySelector("thead tr")?.children.length || 7;
      tbody.innerHTML = `
        <tr>
          <td colspan="${colCount}" class="text-center py-5">
            <i class="ri-error-warning-line fs-48 text-danger mb-3 d-block"></i>
            <p class="text-danger mb-0">${message}</p>
          </td>
        </tr>`;
    }
  }

  // ========================================================================
  // EXPOSE UTILITIES
  // ========================================================================

  window.UserManagementUtils = {
    // Formatters
    getInitials,
    getStatusBadge,
    getTerritoryBadge,
    getActionBadge,
    capitalize,
    formatDate,

    // Generators
    generateEmployeeCode,
    generatePassword,

    // Utilities
    debounce,
    copyToClipboard,
    showTableLoader,
    showTableError,
  };
})();
