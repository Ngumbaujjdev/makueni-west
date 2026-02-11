# Role Permissions Endpoint - API Documentation

**Date:** 2025-12-15  
**Version:** 2.0  
**Status:** ✅ Implemented

---

## New Endpoint: Get Permissions for Role Management

### Overview

A dedicated endpoint that returns permissions as **objects with IDs** instead of action strings, specifically designed for role permission management UI.

---

## Endpoint Details

**URL:** `GET /api/permissions/for-role-management`

**Authentication:** Required (Bearer Token)

**Query Parameters:**
- `territory_scope` - **required** | string | One of: `diocese`, `region`, `subregion`, `church`

---

## Request Example

```bash
GET /api/permissions/for-role-management?territory_scope=church
Authorization: Bearer {token}
Accept: application/json
```

---

## Response Structure

### Success Response (200 OK)

```json
{
  "success": true,
  "message": "Permissions for role management retrieved successfully",
  "data": {
    "territory_scope": "church",
    "modules": [
      {
        "id": 4,
        "name": "Members",
        "icon": "users",
        "submodules": [
          {
            "id": 15,
            "title": "Member Registration",
            "permissions": [
              {
                "id": 215,
                "action": "create",
                "territory_scope": "church",
                "name": "members.registration.create"
              },
              {
                "id": 216,
                "action": "read",
                "territory_scope": "church",
                "name": "members.registration.read"
              },
              {
                "id": 217,
                "action": "update",
                "territory_scope": "church",
                "name": "members.registration.update"
              }
            ]
          },
          {
            "id": 16,
            "title": "Member List",
            "permissions": [
              {
                "id": 220,
                "action": "read",
                "territory_scope": "church",
                "name": "members.list.read"
              },
              {
                "id": 221,
                "action": "export",
                "territory_scope": "church",
                "name": "members.list.export"
              }
            ]
          }
        ]
      }
    ],
    "total_permissions": 150
  }
}
```

### With Sub-Submodules

```json
{
  "success": true,
  "data": {
    "territory_scope": "church",
    "modules": [
      {
        "id": 1,
        "name": "Finance",
        "icon": "dollar-sign",
        "submodules": [
          {
            "id": 5,
            "title": "Transactions",
            "sub_submodules": [
              {
                "id": 12,
                "title": "Income",
                "permissions": [
                  {
                    "id": 45,
                    "action": "create",
                    "territory_scope": "church",
                    "name": "finance.transactions.income.create"
                  },
                  {
                    "id": 46,
                    "action": "read",
                    "territory_scope": "church",
                    "name": "finance.transactions.income.read"
                  }
                ]
              },
              {
                "id": 13,
                "title": "Expenses",
                "permissions": [
                  {
                    "id": 50,
                    "action": "create",
                    "territory_scope": "church",
                    "name": "finance.transactions.expenses.create"
                  }
                ]
              }
            ]
          }
        ]
      }
    ],
    "total_permissions": 85
  }
}
```

---

## Error Responses

### 400 Bad Request - Missing Territory Scope

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "territory_scope": ["The territory scope field is required."]
  }
}
```

### 400 Bad Request - Invalid Territory Scope

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "territory_scope": ["The selected territory scope is invalid."]
  }
}
```

### 403 Forbidden - Insufficient Permissions

```json
{
  "success": false,
  "message": "You do not have permission to view permissions for this territory scope"
}
```

---

## Frontend Integration Guide

### Step 1: Fetch Permissions for Role's Territory Level

```javascript
async function loadPermissionsForRole(role) {
  const territoryScope = role.territory_level; // e.g., "church"
  
  try {
    const response = await fetch(
      `/api/permissions/for-role-management?territory_scope=${territoryScope}`,
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      }
    );
    
    const result = await response.json();
    
    if (result.success) {
      return result.data;
    } else {
      throw new Error(result.message);
    }
  } catch (error) {
    console.error('Failed to load permissions:', error);
    throw error;
  }
}
```

### Step 2: Build Permission Map

```javascript
function buildPermissionMap(permissionsData) {
  const permissionMap = new Map();
  
  permissionsData.modules.forEach(module => {
    module.submodules.forEach(submodule => {
      if (submodule.sub_submodules) {
        // Has sub-submodules
        submodule.sub_submodules.forEach(subSub => {
          subSub.permissions.forEach(permission => {
            permissionMap.set(permission.id, {
              id: permission.id,
              action: permission.action,
              name: permission.name,
              module: module.name,
              submodule: submodule.title,
              subSubmodule: subSub.title
            });
          });
        });
      } else {
        // Regular submodule
        submodule.permissions.forEach(permission => {
          permissionMap.set(permission.id, {
            id: permission.id,
            action: permission.action,
            name: permission.name,
            module: module.name,
            submodule: submodule.title
          });
        });
      }
    });
  });
  
  return permissionMap;
}
```

### Step 3: Render Checkboxes with Permission IDs

```javascript
function renderPermissionCheckboxes(permissionsData, assignedPermissionIds) {
  const container = document.getElementById('permissions-container');
  
  permissionsData.modules.forEach(module => {
    const moduleDiv = document.createElement('div');
    moduleDiv.className = 'module-section';
    
    const moduleHeader = document.createElement('h3');
    moduleHeader.textContent = module.name;
    moduleDiv.appendChild(moduleHeader);
    
    module.submodules.forEach(submodule => {
      const submoduleDiv = document.createElement('div');
      submoduleDiv.className = 'submodule-section';
      
      const submoduleTitle = document.createElement('h4');
      submoduleTitle.textContent = submodule.title;
      submoduleDiv.appendChild(submoduleTitle);
      
      // Get permissions array (either direct or from sub-submodules)
      const permissions = submodule.permissions || 
        (submodule.sub_submodules?.flatMap(ss => ss.permissions) || []);
      
      permissions.forEach(permission => {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'permission-toggle';
        checkbox.dataset.permissionId = permission.id; // ✅ Set permission ID
        checkbox.checked = assignedPermissionIds.includes(permission.id);
        
        const label = document.createElement('label');
        label.textContent = permission.action;
        label.prepend(checkbox);
        
        submoduleDiv.appendChild(label);
      });
      
      moduleDiv.appendChild(submoduleDiv);
    });
    
    container.appendChild(moduleDiv);
  });
}
```

### Step 4: Collect and Save Selected Permissions

```javascript
async function saveRolePermissions(roleId) {
  const selectedIds = [];
  
  document.querySelectorAll('.permission-toggle:checked').forEach(checkbox => {
    const permissionId = parseInt(checkbox.dataset.permissionId);
    if (!isNaN(permissionId)) {
      selectedIds.push(permissionId);
    }
  });
  
  console.log('📤 Sending permission IDs:', selectedIds);
  
  try {
    const response = await fetch(`/api/roles/${roleId}/permissions`, {
      method: 'PUT',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        permissions: selectedIds
      })
    });
    
    const result = await response.json();
    
    if (result.success) {
      console.log('✅ Permissions saved successfully');
      showSuccessMessage('Permissions updated successfully');
    } else {
      throw new Error(result.message);
    }
  } catch (error) {
    console.error('❌ Failed to save permissions:', error);
    showErrorMessage('Failed to update permissions');
  }
}
```

---

## Complete Example Flow

```javascript
// 1. Load role details
const role = await fetchRoleDetails(roleId);
console.log('Role:', role.name, '| Territory:', role.territory_level);

// 2. Fetch permissions for this territory level
const permissionsData = await loadPermissionsForRole(role);
console.log('✅ Loaded', permissionsData.total_permissions, 'permissions');

// 3. Build permission map for quick lookup
const permissionMap = buildPermissionMap(permissionsData);

// 4. Get assigned permission IDs from role
const assignedPermissionIds = role.permissions.map(p => p.id);
console.log('✅ Loaded assigned permission IDs:', assignedPermissionIds);

// 5. Render UI with checkboxes
renderPermissionCheckboxes(permissionsData, assignedPermissionIds);

// 6. Save on button click
document.getElementById('save-btn').addEventListener('click', () => {
  saveRolePermissions(roleId);
});
```

---

## Key Differences from `/grouped` Endpoint

| Feature | `/grouped` | `/for-role-management` |
|---------|-----------|------------------------|
| **Permissions Format** | `["create", "read"]` (strings) | `[{id: 215, action: "create"}]` (objects) |
| **Territory Scope** | Optional parameter | **Required** parameter |
| **Permission IDs** | ❌ Not included | ✅ Included |
| **Validation** | None | Validates territory_scope |
| **Use Case** | General grouping | Role permission UI |

---

## Testing

### Test 1: Church Level Permissions

```bash
curl -X GET "http://localhost:8000/api/permissions/for-role-management?territory_scope=church" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Expected:** Returns church-level permissions with IDs

### Test 2: Diocese Level Permissions

```bash
curl -X GET "http://localhost:8000/api/permissions/for-role-management?territory_scope=diocese" \
  -H "Authorization: Bearer {token}"
```

**Expected:** Returns diocese-level permissions (different IDs)

### Test 3: Missing Parameter

```bash
curl -X GET "http://localhost:8000/api/permissions/for-role-management" \
  -H "Authorization: Bearer {token}"
```

**Expected:** 400 error with validation message

---

## Browser Console Debugging

```javascript
// Check if permissions loaded correctly
console.log('Territory Scope:', permissionsData.territory_scope);
console.log('Total Permissions:', permissionsData.total_permissions);
console.log('Modules:', permissionsData.modules.length);

// Check first permission object
const firstModule = permissionsData.modules[0];
const firstSubmodule = firstModule.submodules[0];
const firstPermission = firstSubmodule.permissions?.[0] || 
  firstSubmodule.sub_submodules?.[0]?.permissions?.[0];

console.log('Sample Permission:', firstPermission);
// Should show: {id: 215, action: "create", territory_scope: "church", name: "..."}

// Verify IDs are integers
console.log('ID is integer:', Number.isInteger(firstPermission.id));
```

---

**Last Updated:** 2025-12-15  
**Endpoint:** `GET /api/permissions/for-role-management`  
**Version:** 2.0
