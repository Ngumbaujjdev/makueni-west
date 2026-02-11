# Backend API - Role Permissions System ✅

**Date:** 2025-12-15  
**Status:** ✅ Fully Implemented and Tested  
**For:** Frontend Team

---

## 🎯 Summary

The role permissions API is **working perfectly**. You can now save and load permissions using permission IDs.

---

## 📡 API Endpoints

### 1. Get Permissions for Role Management

**Endpoint:** `GET /api/permissions/for-role-management`

**Purpose:** Fetch all available permissions for a territory level with IDs

**Required Parameter:** `territory_scope` (diocese, region, subregion, or church)

**Example Request:**
```javascript
GET /api/permissions/for-role-management?territory_scope=church
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "territory_scope": "church",
    "modules": [
      {
        "id": 37,
        "name": "Growth",
        "icon": "chart-line",
        "submodules": [
          {
            "id": 165,
            "title": "Demographics Tracking",
            "permissions": [
              {"id": 888, "action": "create", "territory_scope": "church"},
              {"id": 889, "action": "read", "territory_scope": "church"}
            ]
          }
        ]
      }
    ],
    "total_permissions": 238
  }
}
```

---

### 2. Update Role Permissions

**Endpoint:** `PUT /api/roles/{roleId}/permissions`

**Purpose:** Save selected permissions for a role

**Request Body:**
```json
{
  "permissions": [888, 889, 890, 891, 908, 909, 910, 911]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Role permissions updated successfully",
  "data": {
    "id": 11,
    "name": "Associate Pastor",
    "permissions": [
      {"id": 888, "action": "create", "name": "..."},
      {"id": 889, "action": "read", "name": "..."}
    ]
  }
}
```

---

## 💻 Frontend Implementation

### Step 1: Load Permissions

```javascript
async function loadPermissions(role) {
  const response = await fetch(
    `/api/permissions/for-role-management?territory_scope=${role.territory_level}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    }
  );
  
  const data = await response.json();
  return data.data; // {territory_scope, modules, total_permissions}
}
```

### Step 2: Render Checkboxes

```javascript
function renderPermissions(permissionsData, assignedIds) {
  permissionsData.modules.forEach(module => {
    module.submodules.forEach(submodule => {
      const permissions = submodule.permissions || [];
      
      permissions.forEach(permission => {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.dataset.permissionId = permission.id; // ✅ Set ID
        checkbox.checked = assignedIds.includes(permission.id);
        
        // Add to DOM
      });
    });
  });
}
```

### Step 3: Save Permissions

```javascript
async function savePermissions(roleId) {
  // Collect selected permission IDs
  const selectedIds = Array.from(
    document.querySelectorAll('.permission-toggle:checked')
  ).map(cb => parseInt(cb.dataset.permissionId));
  
  const response = await fetch(`/api/roles/${roleId}/permissions`, {
    method: 'PUT',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      permissions: selectedIds
    })
  });
  
  return await response.json();
}
```

---

## ✅ Verified Working

**Test Results:**
- ✅ Sent 12 permission IDs: `[888, 889, 890, 891, 908, 909, 910, 911, 924, 925, 926, 927]`
- ✅ API returned HTTP 200 success
- ✅ Database confirmed: 12 permissions saved
- ✅ Permissions persist after page refresh

---

## 🔑 Key Points

1. **Send permission IDs** (integers) not objects
2. **Use PUT method** not POST
3. **Include territory_scope** parameter when fetching permissions
4. **Permissions persist** to database automatically
5. **No complex payload** needed - just an array of IDs

---

## 📋 Complete Example

```javascript
// 1. Load role
const role = await fetchRole(11);

// 2. Get available permissions
const permissionsData = await loadPermissions(role);

// 3. Get assigned permission IDs
const assignedIds = role.permissions.map(p => p.id);

// 4. Render UI
renderPermissions(permissionsData, assignedIds);

// 5. Save on button click
saveButton.onclick = async () => {
  const result = await savePermissions(role.id);
  if (result.success) {
    alert('Permissions saved!');
  }
};
```

---

## 🚨 Error Handling

**400 - Validation Error:**
```json
{
  "success": false,
  "errors": {
    "permissions.0": ["The selected permission is invalid."]
  }
}
```

**403 - Forbidden:**
```json
{
  "success": false,
  "message": "You do not have permission to update permissions for this role"
}
```

---

## ✅ Ready to Use

The backend is fully functional and tested. No changes needed to your current implementation if you're already sending permission IDs!

**Questions?** Contact the backend team.

---

**Last Updated:** 2025-12-15  
**Backend Version:** Laravel 10.x  
**API Version:** v1
