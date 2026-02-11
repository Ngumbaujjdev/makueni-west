/**
 * ============================================================================
 * USER MANAGEMENT - STATUS ACTIONS
 * ============================================================================
 * Handles user status changes: Activate, Deactivate, Suspend
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // USER STATUS ACTIONS
  // ========================================================================

  /**
   * Activate user
   */
  async function activateUser(userId, userName) {
    Toast.confirm(`Activate user "${userName}"?`, async () => {
      try {
        // Use the faster status-only endpoint
        const response = await APIHandler.updateUserStatus(userId, "active");

        if (response.success) {
          Toast.success("User activated successfully!");

          // Reload users table
          if (typeof UserManagementTable !== "undefined") {
            await UserManagementTable.loadUsersTab(true);
          }
        } else {
          Toast.error(response.message || "Failed to activate user");
        }
      } catch (error) {
        console.error("Error activating user:", error);
        Toast.error("Failed to activate user");
      }
    });
  }

  /**
   * Deactivate user
   */
  async function deactivateUser(userId, userName) {
    Toast.confirm(
      `Deactivate user "${userName}"? They will not be able to login.`,
      async () => {
        try {
          // Use the faster status-only endpoint
          const response = await APIHandler.updateUserStatus(userId, "inactive");

          if (response.success) {
            Toast.success("User deactivated successfully!");

            // Reload users table
            if (typeof UserManagementTable !== "undefined") {
              await UserManagementTable.loadUsersTab(true);
            }
          } else {
            Toast.error(response.message || "Failed to deactivate user");
          }
        } catch (error) {
          console.error("Error deactivating user:", error);
          Toast.error("Failed to deactivate user");
        }
      }
    );
  }

  /**
   * Suspend user
   */
  async function suspendUser(userId, userName) {
    Toast.confirm(
      `Suspend user "${userName}"? They will be locked out immediately.`,
      async () => {
        try {
          const response = await APIHandler.updateUser(userId, {
            status: "suspended",
          });

          if (response.success) {
            Toast.success("User suspended successfully!");

            // Reload users table
            if (typeof UserManagementTable !== "undefined") {
              await UserManagementTable.loadUsersTab(true);
            }
          } else {
            Toast.error(response.message || "Failed to suspend user");
          }
        } catch (error) {
          console.error("Error suspending user:", error);
          Toast.error("Failed to suspend user");
        }
      }
    );
  }

  // ========================================================================
  // ACCOUNT SECURITY ACTIONS
  // ========================================================================

  /**
   * Unlock user account (reset failed login attempts)
   */
  async function unlockAccount(userId) {
    Toast.confirm("Unlock this user account?", async () => {
      try {
        const response = await APIHandler.updateUser(userId, {
          failed_login_attempts: 0,
        });

        if (response.success) {
          Toast.success("Account unlocked successfully!");
        } else {
          Toast.error(response.message || "Failed to unlock account");
        }
      } catch (error) {
        console.error("Error unlocking account:", error);
        Toast.error("Failed to unlock account");
      }
    });
  }

  /**
   * Force password change on next login
   */
  async function forcePasswordChange(userId) {
    Toast.confirm(
      "Force this user to change password on next login?",
      async () => {
        try {
          const response = await APIHandler.updateUser(userId, {
            must_change_password: true,
          });

          if (response.success) {
            Toast.success("User will be required to change password!");
          } else {
            Toast.error(response.message || "Failed to force password change");
          }
        } catch (error) {
          console.error("Error forcing password change:", error);
          Toast.error("Failed to force password change");
        }
      }
    );
  }

  // ========================================================================
  // DELETE USER (Currently not used - blocked if has assignments)
  // ========================================================================

  /**
   * Delete user
   */
  async function deleteUser(userId, userName) {
    Toast.confirm(
      `⚠️ DELETE user "${userName}" permanently? This cannot be undone.`,
      async () => {
        try {
          const response = await APIHandler.deleteUser(userId);

          if (response.success) {
            Toast.success("User deleted successfully!");

            // Reload users table
            if (typeof UserManagementTable !== "undefined") {
              await UserManagementTable.loadUsersTab(true);
            }
          } else {
            Toast.error(response.message || "Failed to delete user");
          }
        } catch (error) {
          console.error("Error deleting user:", error);
          Toast.error("Failed to delete user");
        }
      }
    );
  }

  // ========================================================================
  // EXPOSE ACTIONS MODULE
  // ========================================================================

  window.UserManagementActions = {
    activateUser,
    deactivateUser,
    suspendUser,
    unlockAccount,
    forcePasswordChange,
    deleteUser,
  };
})();
