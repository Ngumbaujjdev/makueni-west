/**
 * Logout Handler
 */

async function handleLogout() {
  try {
    // Get user info for goodbye message
    const userData = localStorage.getItem("mwd_user_data");
    let userName = "User";

    if (userData) {
      try {
        const user = JSON.parse(userData);
        userName = user.firstname || user.full_name || "User";
      } catch (e) {
        console.error("Error parsing user data:", e);
      }
    }

    // ✅ Show confirmation toast with actions
    Toast.confirm(
      `<strong>${userName}</strong>, are you sure you want to logout?`,
      async function () {
        // User clicked "Yes, Continue" - proceed with logout
        await performLogout(userName);
      },
      function () {
        // User clicked "Cancel" - do nothing
        console.log("Logout cancelled by user");
      },
      {
        title: "Logout Confirmation",
        confirmText: "Yes, Logout",
        cancelText: "Stay Logged In",
        type: "warning",
      }
    );
  } catch (error) {
    console.error("Logout error:", error);
    window.location.href = "/makueni-west/logout";
  }
}

/**
 * Perform actual logout
 */
async function performLogout(userName) {
  try {
    const token = localStorage.getItem("mwd_auth_token");

    // Call backend API to invalidate token
    if (token) {
      try {
        await fetch(`${window.AppConfig.API_BASE_URL}/auth/logout`, {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: "application/json",
            "Content-Type": "application/json",
          },
        });
      } catch (apiError) {
        console.warn("Backend logout failed, continuing with local logout");
      }
    }

    // Clear all localStorage
    localStorage.removeItem("mwd_auth_token");
    localStorage.removeItem("mwd_user_data");
    localStorage.removeItem("mwd_permissions");
    localStorage.removeItem("mwd_territorial_roles");
    localStorage.removeItem("mwd_current_role");
    localStorage.removeItem("mwd_session_expiry");
    localStorage.removeItem("mwd_last_login");
    localStorage.removeItem("mwd_remember_me");

    // Clear cached modules
    Object.keys(localStorage).forEach((key) => {
      if (key.startsWith("modules_")) {
        localStorage.removeItem(key);
      }
    });

    // Clear sessionStorage
    sessionStorage.clear();

    // ✅ Show goodbye message
    Toast.success(
      `Goodbye <strong>${userName}</strong>! You have been logged out successfully.`,
      {
        duration: 2500,
        title: "Logged Out",
      }
    );

    // ✅ Redirect to index after 2.5 seconds
    setTimeout(() => {
      window.location.href = "/makueni-west/";
    }, 2500);
  } catch (error) {
    console.error("Logout error:", error);
    window.location.href = "/makueni-west/logout";
  }
}

// Make globally available
window.handleLogout = handleLogout;
