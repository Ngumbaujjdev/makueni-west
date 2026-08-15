/**
 * Authentication Helper Functions
 *
 * Handles auto-refresh of user assignments, modules, and session checks
 */

/**
 * Auto-refresh user assignments and modules on page load
 * Checks for new roles/permissions without logging out
 */
async function autoRefreshAssignments() {
  try {
    const token = localStorage.getItem("mwd_auth_token");
    const sessionExpiry = localStorage.getItem("mwd_session_expiry");

    // Check if user is logged in and session is valid
    if (!token || !sessionExpiry) {
      return false;
    }

    const expiryTime = new Date(sessionExpiry).getTime();
    const currentTime = new Date().getTime();

    // If session expired, don't refresh
    if (currentTime >= expiryTime) {
      return false;
    }

    // Check last refresh time (don't refresh more than once per 30 seconds)
    const lastRefresh = localStorage.getItem("mwd_last_assignment_refresh");
    if (lastRefresh) {
      const lastRefreshTime = new Date(lastRefresh).getTime();
      const timeSinceRefresh = currentTime - lastRefreshTime;

      // If refreshed less than 30 seconds ago, skip
      if (timeSinceRefresh < 30000) {  // ✅ Changed from 60000ms (1 min) to 30000ms (30 sec)
        console.log(`⏭️ Skipping auto-refresh (last refresh ${Math.round(timeSinceRefresh / 1000)}s ago, minimum 30s)`);
        return true;
      }
    }

    console.log("Auto-refreshing user assignments and modules...");

    // Call backend to get fresh user data with assignments
    const response = await fetch("http://127.0.0.1:8004/api/user", {
      method: "GET",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
    });

    if (!response.ok) {
      // If 401, session expired - logout
      if (response.status === 401) {
        console.log("Session expired during auto-refresh");
        clearAuthData();
        window.location.href = "/makueni-west/";
        return false;
      }
      throw new Error("Failed to fetch user data");
    }

    const userData = await response.json();
    
    console.log("🔍 Raw userData from /api/user:", userData);
    console.log("🔍 userData.active_assignments:", userData.active_assignments);

    // Update localStorage with fresh user data
    localStorage.setItem("mwd_user_data", JSON.stringify(userData));

    // Get current role and permissions
    const currentRole = JSON.parse(
      localStorage.getItem("mwd_current_role") || "{}"
    );
    
    console.log("📋 Current role from localStorage:", currentRole);
    console.log("📋 User active assignments:", userData.active_assignments);
    
    // ✅ CRITICAL FIX: Always use PRIMARY assignment, not just first assignment
    let assignmentId = currentRole.assignment_id;
    
    // If no current role stored, find the PRIMARY assignment
    if (!assignmentId && userData.active_assignments) {
      const primaryAssignment = userData.active_assignments.find(
        assignment => assignment.assignment_type === 'primary'
      );
      assignmentId = primaryAssignment?.id || userData.active_assignments[0]?.id;
      
      console.log("⚠️ No assignment_id in currentRole, using PRIMARY assignment:", {
        primaryAssignment: primaryAssignment,
        selectedId: assignmentId
      });
    } else {
      console.log("✅ Using assignment_id from currentRole:", assignmentId);
      
      // ✅ EXTRA CHECK: Verify this assignment is still valid and is PRIMARY
      if (userData.active_assignments) {
        const selectedAssignment = userData.active_assignments.find(a => a.id === assignmentId);
        if (selectedAssignment) {
          console.log("📌 Selected assignment details:", {
            id: selectedAssignment.id,
            role: selectedAssignment.role?.name,
            territory: selectedAssignment.territory?.name,
            assignment_type: selectedAssignment.assignment_type,
            is_primary: selectedAssignment.assignment_type === 'primary'
          });
          
          // ✅ CRITICAL: If selected assignment is NOT primary, switch to primary
          if (selectedAssignment.assignment_type !== 'primary') {
            console.warn("⚠️ WARNING: Selected assignment is NOT primary! Switching to primary...");
            const primaryAssignment = userData.active_assignments.find(
              assignment => assignment.assignment_type === 'primary'
            );
            if (primaryAssignment) {
              assignmentId = primaryAssignment.id;
              console.log("✅ Switched to PRIMARY assignment:", primaryAssignment.id);
            }
          }
        } else {
          console.error("❌ Assignment ID not found in active assignments! Using primary...");
          const primaryAssignment = userData.active_assignments.find(
            assignment => assignment.assignment_type === 'primary'
          );
          assignmentId = primaryAssignment?.id || userData.active_assignments[0]?.id;
        }
      }
    }

    if (assignmentId) {
      // Switch to current role to get fresh permissions
      const roleResponse = await fetch(
        "http://127.0.0.1:8004/api/auth/switch-role",
        {
          method: "POST",
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            assignment_id: assignmentId,
          }),
        }
      );

      if (roleResponse.ok) {
        const roleData = await roleResponse.json();

        // Update permissions and current role
        const newPermissions = roleData.data.permissions || [];
        localStorage.setItem(
          "mwd_permissions",
          JSON.stringify(newPermissions)
        );
        localStorage.setItem("mwd_current_role", JSON.stringify(roleData.data));
        
        console.log(`🔐 Permissions refreshed for ${roleData.data.role?.name || 'role'}: ${newPermissions.length} permissions loaded`);

        // Extract territorial roles
        const territorialRoles =
          userData.active_assignments?.map((assignment) => ({
            assignment_id: assignment.id,
            role_name: assignment.role?.name,
            territory_name: assignment.territory?.name,
            territory_type: assignment.territory?.territory_type,
            assignment_type: assignment.assignment_type,
            is_primary: assignment.is_primary || false,
          })) || [];

        localStorage.setItem(
          "mwd_territorial_roles",
          JSON.stringify(territorialRoles)
        );

        // ✅ NEW: Refresh modules with updated role
        console.log("📦 Refreshing modules...");
        await refreshModules(token, roleData.data);

        // Re-sync to PHP session
        await fetch(
          "/makueni-west/authentication/ajax/sync-session",
          {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            body: JSON.stringify({
              token: token,
              user: userData,
              permissions: roleData.data.permissions || [],
              territorial_roles: territorialRoles,
              current_role: roleData.data,
            }),
          }
        );

        // Update last refresh timestamp
        localStorage.setItem(
          "mwd_last_assignment_refresh",
          new Date().toISOString()
        );

        console.log("✅ User assignments and modules refreshed successfully");
        return true;
      }
    }

    return true;
  } catch (error) {
    console.error("Error during auto-refresh:", error);
    return false;
  }
}

/**
 * ✅ Refresh modules from backend
 * - Global Admins: Get ALL diocese modules (no filtering)
 * - Regular Roles: Get ONLY modules with permissions (filtered)
 */
async function refreshModules(token, currentRole) {
  try {
    // Check if user is Global Admin by checking for 'global' territory type
    const userData = JSON.parse(localStorage.getItem("mwd_user_data") || '{}');
    const hasGlobalTerritory = userData.active_assignments?.some(
      assignment => assignment.territory?.territory_type === 'global'
    );
    const isGlobalAdmin = hasGlobalTerritory || false;
    
    // ✅ Use different endpoints based on user type
    const endpoint = isGlobalAdmin 
      ? "http://127.0.0.1:8004/api/modules"  // Global Admin: ALL modules
      : "http://127.0.0.1:8004/api/modules/for-role";  // Regular: Filtered by permissions
    
    console.log(`📡 Refreshing modules as ${isGlobalAdmin ? 'Global Admin' : 'Regular Role'} from: ${endpoint}`);
    
    const response = await fetch(endpoint, {
      method: "GET",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
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
      localStorage.setItem("mwd_current_modules", JSON.stringify(modulesCache));

      const roleInfo = isGlobalAdmin 
        ? 'Global Administrator (ALL modules)' 
        : `${user_assignment?.role?.name || 'role'} (filtered by permissions)`;
      
      console.log(
        `✅ Refreshed ${total_groups} groups with ${total_modules} modules for ${roleInfo} (${territory_scope})`
      );
      console.log(`🔒 Filtered by permissions: ${modulesCache.filtered_by_permissions}`);
      
      return true;
    }

    return false;
  } catch (error) {
    console.error("Error refreshing modules:", error);
    return false;
  }
}

/**
 * Get cached modules for current user
 * Returns null if no modules cached
 */
function getCachedModules() {
  try {
    const cachedData = localStorage.getItem("mwd_current_modules");

    if (!cachedData) {
      console.warn("No cached modules found");
      return null;
    }

    const modulesCache = JSON.parse(cachedData);

    // Check if cache is still valid (less than 5 minutes old)
    const cachedAt = new Date(modulesCache.cached_at).getTime();
    const now = new Date().getTime();
    const cacheAge = (now - cachedAt) / 1000 / 60; // in minutes

    if (cacheAge > 5) {
      console.warn(
        "Module cache is stale (>5 minutes old), consider refreshing"
      );
    }

    return modulesCache;
  } catch (error) {
    console.error("Error retrieving cached modules:", error);
    return null;
  }
}

/**
 * Get modules for a specific territory level
 * Useful when user switches roles
 */
function getCachedModulesForTerritory(
  territoryScope,
  assignmentId = "default"
) {
  try {
    const cacheKey = `modules_${territoryScope}_${assignmentId}`;
    const cachedData = localStorage.getItem(cacheKey);

    if (!cachedData) {
      return null;
    }

    return JSON.parse(cachedData);
  } catch (error) {
    console.error("Error retrieving cached modules for territory:", error);
    return null;
  }
}

/**
 * Clear all authentication data
 */
function clearAuthData() {
  localStorage.removeItem("mwd_auth_token");
  localStorage.removeItem("mwd_user_data");
  localStorage.removeItem("mwd_permissions");
  localStorage.removeItem("mwd_territorial_roles");
  localStorage.removeItem("mwd_current_role");
  localStorage.removeItem("mwd_session_expiry");
  localStorage.removeItem("mwd_last_login");
  localStorage.removeItem("mwd_remember_me");
  localStorage.removeItem("mwd_last_assignment_refresh");
  localStorage.removeItem("mwd_current_modules");

  // Clear cached modules
  Object.keys(localStorage).forEach((key) => {
    if (key.startsWith("modules_")) {
      localStorage.removeItem(key);
    }
  });

  sessionStorage.clear();
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
  const token = localStorage.getItem("mwd_auth_token");
  const sessionExpiry = localStorage.getItem("mwd_session_expiry");

  if (token && sessionExpiry) {
    const expiryTime = new Date(sessionExpiry).getTime();
    const currentTime = new Date().getTime();
    return currentTime < expiryTime;
  }

  return false;
}

/**
 * Force refresh modules manually
 * Useful when user performs an action that might change their permissions
 */
async function forceRefreshModules() {
  try {
    const token = localStorage.getItem("mwd_auth_token");
    const currentRole = JSON.parse(
      localStorage.getItem("mwd_current_role") || "{}"
    );

    if (!token) {
      console.error("No auth token found");
      return false;
    }

    console.log("🔄 Force refreshing modules...");
    const success = await refreshModules(token, currentRole);

    if (success) {
      console.log("✅ Modules force-refreshed successfully");
    } else {
      console.error("❌ Failed to force-refresh modules");
    }

    return success;
  } catch (error) {
    console.error("Error force-refreshing modules:", error);
    return false;
  }
}

// Make functions globally available
window.autoRefreshAssignments = autoRefreshAssignments;
window.refreshModules = refreshModules;
window.getCachedModules = getCachedModules;
window.getCachedModulesForTerritory = getCachedModulesForTerritory;
window.forceRefreshModules = forceRefreshModules;
window.clearAuthData = clearAuthData;
window.isAuthenticated = isAuthenticated;

// ✅ AUTO-RUN: Call auto-refresh when any page loads
document.addEventListener("DOMContentLoaded", function () {
  // Only run auto-refresh if user is authenticated
  if (isAuthenticated()) {
    autoRefreshAssignments();
  }
});
