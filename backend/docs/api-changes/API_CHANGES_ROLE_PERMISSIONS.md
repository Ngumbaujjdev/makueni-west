# Role Permissions API Changes

**Date:** 2025-12-15  
**Version:** 2.0  
**Status:** ✅ Implemented

---

## Overview

The Role Permissions API has been simplified to use **permission IDs** instead of the complex module/submodule/action structure. This change makes the API more efficient, easier to use, and reduces payload size.

---

## 🔄 What Changed

### Previous Implementation (DEPRECATED)

The old API required sending complex nested objects with module IDs, submodule IDs, and action arrays:

```json
{
  "permissions": [
    {
      "module_id": 1,
      "submodule_id": 2,
      "sub_submodule_id": null,
      "actions": ["create", "read", "update", "delete"]
    },
    {
      "module_id": 1,
      "submodule_id": 3,
      "sub_submodule_id": 5,
      "actions": ["read", "export"]
    }
  ]
}
```

### New Implementation (CURRENT)

The new API accepts a simple array of permission IDs:

```json
{
  "permissions": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
}
```

---

## 📋 API Endpoint Details

### Update Role Permissions

**Endpoint:** `PUT /api/roles/{roleId}/permissions`

**Authentication:** Required (Bearer Token)

**Request Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "permissions": [1, 2, 3, 4, 5]
}
```

**Validation Rules:**
- `permissions` - **required** | array
- `permissions.*` - **required** | integer | exists in `permissions` table

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Role permissions updated successfully",
  "data": {
    "id": 5,
    "name": "Regional Administrator",
    "territory_level": "region",
    "territory_level_name": "Region",
    "description": "Administrator for regional operations",
    "is_active": true,
    "permissions": [
      {
        "id": 1,
        "name": "users.view.read",
        "action": "read",
        "module": {
          "id": 1,
          "name": "Users"
        },
        "submodule": {
          "id": 2,
          "title": "View"
        }
      }
      // ... more permissions
    ]
  }
}
```

**Error Responses:**

**400 Bad Request** - Validation Error:
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "permissions.0": ["The selected permission is invalid."],
    "permissions.2": ["The permissions.2 must be an integer."]
  }
}
```

**403 Forbidden** - Insufficient Permissions:
```json
{
  "success": false,
  "message": "You do not have permission to update permissions for this role"
}
```

**404 Not Found** - Role Not Found:
```json
{
  "success": false,
  "message": "Role not found"
}
```

---

## 🔧 Frontend Implementation Guide

### Step 1: Fetch Role Details with Available Permissions

When loading the edit permissions page, fetch the role details to get all available permissions:

**Request:**
```javascript
GET /api/roles/{roleId}
```

**Response includes:**
```json
{
  "success": true,
  "message": "Role details retrieved successfully",
  "data": {
    "id": 5,
    "name": "Regional Administrator",
    "modules": [
      {
        "id": 1,
        "name": "Users",
        "icon": "users",
        "submodules": [
          {
            "id": 2,
            "title": "View Users",
            "actions": ["create", "read", "update", "delete", "approve", "export"],
            "permissions": ["read", "update"]  // Currently assigned actions
          }
        ]
      }
    ]
  }
}
```

### Step 2: Build Permission ID Mapping

Create a mapping between UI toggles and permission IDs:

```javascript
// Example: Build permission map from role details
const permissionMap = {};

roleData.modules.forEach(module => {
  module.submodules.forEach(submodule => {
    submodule.actions.forEach(action => {
      // Create a unique key for each permission toggle
      const key = `${module.id}_${submodule.id}_${action}`;
      
      // Find the permission ID from the permissions table
      // You'll need to fetch all permissions or calculate IDs
      const permissionId = findPermissionId(module.id, submodule.id, action);
      
      permissionMap[key] = {
        id: permissionId,
        isAssigned: submodule.permissions.includes(action)
      };
    });
  });
});
```

### Step 3: Collect Selected Permission IDs

When the user toggles permissions, collect the IDs:

```javascript
// Example: Collect selected permission IDs
function getSelectedPermissionIds() {
  const selectedIds = [];
  
  // Loop through all permission checkboxes/toggles
  document.querySelectorAll('.permission-toggle:checked').forEach(toggle => {
    const permissionId = parseInt(toggle.dataset.permissionId);
    selectedIds.push(permissionId);
  });
  
  return selectedIds;
}
```

### Step 4: Submit Updated Permissions

Send the array of permission IDs to the API:

```javascript
// Example: Update role permissions
async function updateRolePermissions(roleId) {
  const selectedPermissionIds = getSelectedPermissionIds();
  
  try {
    const response = await fetch(`/api/roles/${roleId}/permissions`, {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        permissions: selectedPermissionIds
      })
    });
    
    const result = await response.json();
    
    if (result.success) {
      showSuccessMessage('Permissions updated successfully');
    } else {
      showErrorMessage(result.message);
    }
  } catch (error) {
    showErrorMessage('Failed to update permissions');
  }
}
```

---

## 📊 Getting Permission IDs

### Option 1: Fetch All Permissions (Recommended)

Fetch the complete permissions list to build your mapping:

**Request:**
```javascript
GET /api/permissions
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "users.view.read",
      "module_id": 1,
      "submodule_id": 2,
      "sub_submodule_id": null,
      "action": "read"
    },
    {
      "id": 2,
      "name": "users.view.create",
      "module_id": 1,
      "submodule_id": 2,
      "sub_submodule_id": null,
      "action": "create"
    }
    // ... more permissions
  ]
}
```

### Option 2: Use Role Details Response

The role details endpoint already includes permission information in the modules structure. You can build your permission map from this data.

---

## ✅ Migration Checklist for Frontend

- [ ] Update API call to use new payload format (array of IDs)
- [ ] Remove code that builds module/submodule/action objects
- [ ] Fetch all permissions to create ID mapping
- [ ] Update permission toggle handlers to work with IDs
- [ ] Test with various permission combinations
- [ ] Update error handling for new validation messages
- [ ] Remove old API payload building logic

---

## 🎯 Benefits of New Approach

1. **Simpler Payload** - Array of integers instead of nested objects
2. **Smaller Size** - Reduced network bandwidth usage
3. **Faster Processing** - Backend uses efficient `sync()` operation
4. **Easier Debugging** - Simple array is easier to inspect
5. **Better Performance** - Single database operation instead of multiple queries

---

## 💡 Example Complete Flow

```javascript
// 1. Fetch role details
const roleResponse = await fetch(`/api/roles/${roleId}`);
const roleData = await roleResponse.json();

// 2. Fetch all permissions for mapping
const permissionsResponse = await fetch('/api/permissions');
const allPermissions = await permissionsResponse.json();

// 3. Create permission lookup
const permissionLookup = {};
allPermissions.data.forEach(perm => {
  const key = `${perm.module_id}_${perm.submodule_id}_${perm.action}`;
  permissionLookup[key] = perm.id;
});

// 4. When user toggles a permission
function onPermissionToggle(moduleId, submoduleId, action, isChecked) {
  const key = `${moduleId}_${submoduleId}_${action}`;
  const permissionId = permissionLookup[key];
  
  if (isChecked) {
    selectedPermissions.add(permissionId);
  } else {
    selectedPermissions.delete(permissionId);
  }
}

// 5. Save permissions
async function savePermissions() {
  const response = await fetch(`/api/roles/${roleId}/permissions`, {
    method: 'PUT',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      permissions: Array.from(selectedPermissions)
    })
  });
  
  return await response.json();
}
```

---

## 🔍 Testing Examples

### Test Case 1: Assign Multiple Permissions
```bash
curl -X PUT http://localhost:8000/api/roles/5/permissions \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"permissions": [1, 2, 3, 4, 5]}'
```

### Test Case 2: Remove All Permissions
```bash
curl -X PUT http://localhost:8000/api/roles/5/permissions \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"permissions": []}'
```

### Test Case 3: Invalid Permission ID
```bash
curl -X PUT http://localhost:8000/api/roles/5/permissions \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"permissions": [999999]}'
```

---

## 📞 Support

If you have questions about implementing these changes, contact the backend team or refer to:
- API Documentation: `/api/documentation`
- Postman Collection: `Role Management.postman_collection.json`

---

**Last Updated:** 2025-12-15  
**Backend Version:** Laravel 10.x  
**API Version:** v1
