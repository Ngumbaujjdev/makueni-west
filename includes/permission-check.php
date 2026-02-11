<?php
/**
 * Permission Check
 * 
 * Functions to check user permissions for specific actions
 * Super Admins (Global Access) bypass all permission checks
 */

/**
 * Check if user has global access (super admin)
 * 
 * @return bool
 */
function hasGlobalAccess() {
    if (!isSessionActive()) {
        return false;
    }
    
    // Check if user has global territory type
    $currentRole = $_SESSION['current_role'] ?? null;
    
    if ($currentRole && isset($currentRole['territory_type'])) {
        return $currentRole['territory_type'] === 'global';
    }
    
    // Alternative: Check territory object
    if ($currentRole && isset($currentRole['territory']['territory_type'])) {
        return $currentRole['territory']['territory_type'] === 'global';
    }
    
    return false;
}

/**
 * Check if user has a specific permission
 * 
 * @param string $permission Permission name (e.g., 'diocese.dashboard.read')
 * @return bool
 */
function hasPermission($permission) {
    if (!isSessionActive()) {
        return false;
    }
    
    // ✅ SUPER ADMIN BYPASS: Global access users have ALL permissions
    if (hasGlobalAccess()) {
        return true;
    }
    
    $permissions = $_SESSION['permissions'] ?? [];
    return in_array($permission, $permissions);
}

/**
 * Check if user has any of the given permissions
 * 
 * @param array $permissions Array of permission names
 * @return bool
 */
function hasAnyPermission($permissions) {
    if (!isSessionActive()) {
        return false;
    }
    
    // ✅ SUPER ADMIN BYPASS
    if (hasGlobalAccess()) {
        return true;
    }
    
    $userPermissions = $_SESSION['permissions'] ?? [];
    
    foreach ($permissions as $permission) {
        if (in_array($permission, $userPermissions)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Check if user has all of the given permissions
 * 
 * @param array $permissions Array of permission names
 * @return bool
 */
function hasAllPermissions($permissions) {
    if (!isSessionActive()) {
        return false;
    }
    
    // ✅ SUPER ADMIN BYPASS
    if (hasGlobalAccess()) {
        return true;
    }
    
    $userPermissions = $_SESSION['permissions'] ?? [];
    
    foreach ($permissions as $permission) {
        if (!in_array($permission, $userPermissions)) {
            return false;
        }
    }
    
    return true;
}

/**
 * Require specific permission or redirect to 403 error page
 * 
 * @param string $permission Required permission
 * @param string $redirectUrl Custom redirect URL (optional, defaults to 403 error page)
 */
function requirePermission($permission, $redirectUrl = null) {
    // ✅ SUPER ADMIN BYPASS: Allow all access
    if (hasGlobalAccess()) {
        return; // Allow access
    }
    
    // Check permission
    if (!hasPermission($permission)) {
        // Store the required permission in session for error page display
        $_SESSION['required_permission'] = $permission;
        $_SESSION['attempted_url'] = $_SERVER['REQUEST_URI'] ?? '';
        
        // Redirect to custom URL or default 403 error page
        if ($redirectUrl) {
            header("Location: $redirectUrl");
        } else {
            header("Location: /makueni-west/errors/403");
        }
        exit;
    }
}

/**
 * Get user's current territorial level
 * 
 * @return string|null (global, diocese, region, subregion, church)
 */
function getCurrentTerritoryLevel() {
    if (!isSessionActive()) {
        return null;
    }
    
    return $_SESSION['current_territory_type'] ?? null;
}

/**
 * Check if user can access a specific territorial level
 * 
 * @param string $requiredLevel Required territorial level
 * @return bool
 */
function canAccessTerritoryLevel($requiredLevel) {
    if (!isSessionActive()) {
        return false;
    }
    
    // Super admin can access all levels
    if (hasGlobalAccess()) {
        return true;
    }
    
    $userLevel = getCurrentTerritoryLevel();
    
    // Define hierarchy (higher index = higher access)
    $hierarchy = ['church', 'subregion', 'region', 'diocese', 'global'];
    
    $userIndex = array_search($userLevel, $hierarchy);
    $requiredIndex = array_search($requiredLevel, $hierarchy);
    
    if ($userIndex === false || $requiredIndex === false) {
        return false;
    }
    
    // User can access their level and below
    return $userIndex >= $requiredIndex;
}