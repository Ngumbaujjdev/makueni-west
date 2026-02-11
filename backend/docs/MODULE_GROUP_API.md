# Module Group API Documentation

## Overview
Complete CRUD API for managing Module Groups with territory-based filtering.

---

## Base URL
```
/api/module-groups
```

---

## Endpoints

### 1. Get All Module Groups
**GET** `/api/module-groups`

Get all module groups with optional filtering.

#### Query Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| territory_scope | string | No | Filter by territory (diocese, region, subregion, church) |
| include_inactive | boolean | No | Include inactive groups (default: false) |

#### Example Request
```bash
GET /api/module-groups?territory_scope=diocese
GET /api/module-groups?include_inactive=true
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module groups retrieved successfully",
  "data": {
    "module_groups": [
      {
        "id": 1,
        "name": "Financial Management",
        "slug": "financial-management",
        "icon": "ri-money-dollar-line",
        "order": 1,
        "territory_scope": "diocese",
        "description": "Financial and accounting modules",
        "is_active": true,
        "modules_count": 5,
        "created_at": "2025-01-06T10:00:00.000000Z",
        "updated_at": "2025-01-06T10:00:00.000000Z"
      }
    ],
    "total_count": 1
  }
}
```

---

### 2. Get Module Groups by Territory
**GET** `/api/module-groups/territory/{territoryScope}`

Get all active module groups for a specific territory.

#### URL Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| territoryScope | string | Yes | Territory type (diocese, region, subregion, church) |

#### Example Request
```bash
GET /api/module-groups/territory/diocese
GET /api/module-groups/territory/church
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module groups retrieved successfully",
  "data": {
    "module_groups": [
      {
        "id": 1,
        "name": "Financial Management",
        "slug": "financial-management",
        "icon": "ri-money-dollar-line",
        "order": 1,
        "territory_scope": "diocese",
        "description": "Financial and accounting modules",
        "is_active": true,
        "modules_count": 5
      }
    ],
    "territory_scope": "diocese",
    "total_count": 1
  }
}
```

---

### 3. Create Module Group
**POST** `/api/module-groups`

Create a new module group.

#### Request Body
```json
{
  "name": "Financial Management",
  "slug": "financial-management",
  "icon": "ri-money-dollar-line",
  "order": 1,
  "territory_scope": "diocese",
  "description": "Financial and accounting modules",
  "is_active": true
}
```

#### Validation Rules
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255, unique |
| slug | string | No | max:255, unique (auto-generated if not provided) |
| icon | string | No | max:100 |
| order | integer | No | min:0 (auto-generated if not provided) |
| territory_scope | string | Yes | in:diocese,region,subregion,church |
| description | string | No | max:1000 |
| is_active | boolean | No | default: true |

#### Success Response (201)
```json
{
  "success": true,
  "status": 201,
  "message": "Module group created successfully",
  "data": {
    "module_group": {
      "id": 1,
      "name": "Financial Management",
      "slug": "financial-management",
      "icon": "ri-money-dollar-line",
      "order": 1,
      "territory_scope": "diocese",
      "description": "Financial and accounting modules",
      "is_active": true
    }
  }
}
```

---

### 4. Get Single Module Group
**GET** `/api/module-groups/{moduleGroup}`

Get a specific module group with all its modules.

#### URL Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| moduleGroup | integer | Yes | Module group ID |

#### Example Request
```bash
GET /api/module-groups/1
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module group retrieved successfully",
  "data": {
    "id": 1,
    "name": "Financial Management",
    "slug": "financial-management",
    "icon": "ri-money-dollar-line",
    "order": 1,
    "territory_scope": "diocese",
    "description": "Financial and accounting modules",
    "is_active": true,
    "modules_count": 2,
    "modules": [
      {
        "id": 1,
        "name": "Budget Management",
        "icon": "ri-file-list-line",
        "number": 1,
        "description": "Manage church budgets",
        "is_active": true,
        "submodules_count": 3,
        "permissions_count": 12
      }
    ],
    "created_at": "2025-01-06T10:00:00.000000Z",
    "updated_at": "2025-01-06T10:00:00.000000Z"
  }
}
```

---

### 5. Update Module Group
**PUT** `/api/module-groups/{moduleGroup}`

Update an existing module group.

#### URL Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| moduleGroup | integer | Yes | Module group ID |

#### Request Body
```json
{
  "name": "Updated Financial Management",
  "slug": "updated-financial-management",
  "icon": "ri-money-dollar-line",
  "order": 2,
  "territory_scope": "diocese",
  "description": "Updated description",
  "is_active": true
}
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module group updated successfully",
  "data": {
    "id": 1,
    "name": "Updated Financial Management",
    "slug": "updated-financial-management",
    "icon": "ri-money-dollar-line",
    "order": 2,
    "territory_scope": "diocese",
    "description": "Updated description",
    "is_active": true,
    "created_at": "2025-01-06T10:00:00.000000Z",
    "updated_at": "2025-01-06T11:00:00.000000Z"
  }
}
```

---

### 6. Update Module Group Order
**PATCH** `/api/module-groups/{moduleGroup}/order`

Update the display order of a module group.

#### URL Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| moduleGroup | integer | Yes | Module group ID |

#### Request Body
```json
{
  "order": 5
}
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module group order updated successfully",
  "data": null
}
```

---

### 7. Delete Module Group
**DELETE** `/api/module-groups/{moduleGroup}`

Delete a module group (only if it has no modules).

#### URL Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| moduleGroup | integer | Yes | Module group ID |

#### Example Request
```bash
DELETE /api/module-groups/1
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module group deleted successfully",
  "data": null
}
```

#### Error Response (400) - Has Modules
```json
{
  "success": false,
  "status": 400,
  "message": "Cannot delete module group. It has 5 module(s). Please delete or reassign modules first.",
  "data": null
}
```

---

## Module Controller - Get Module Groups Endpoint

### Get Module Groups for Selection
**GET** `/api/modules/groups`

Get active module groups for dropdown selection (used when creating/editing modules).

#### Query Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| territory_level | string | No | Filter by territory (diocese, region, subregion, church) |

#### Example Request
```bash
GET /api/modules/groups?territory_level=diocese
```

#### Success Response (200)
```json
{
  "success": true,
  "status": 200,
  "message": "Module groups retrieved successfully",
  "data": [
    {
      "id": 1,
      "name": "Financial Management",
      "slug": "financial-management",
      "icon": "ri-money-dollar-line",
      "order": 1,
      "territory_scope": "diocese",
      "description": "Financial and accounting modules",
      "is_active": true,
      "modules_count": 5
    }
  ]
}
```

---

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "status": 422,
  "message": "Validation error",
  "errors": {
    "name": ["The name field is required."],
    "territory_scope": ["The territory scope must be one of: diocese, region, subregion, church."]
  }
}
```

### Not Found (404)
```json
{
  "success": false,
  "status": 404,
  "message": "Module group not found",
  "data": null
}
```

### Server Error (500)
```json
{
  "success": false,
  "status": 500,
  "message": "Failed to retrieve module groups",
  "error": "Database connection error"
}
```

---

## Testing with cURL

### Create a Module Group
```bash
curl -X POST http://localhost:8000/api/module-groups \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "name": "Financial Management",
    "territory_scope": "diocese",
    "icon": "ri-money-dollar-line",
    "order": 1,
    "description": "Financial and accounting modules"
  }'
```

### Get All Module Groups
```bash
curl -X GET http://localhost:8000/api/module-groups \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Get Module Groups by Territory
```bash
curl -X GET http://localhost:8000/api/module-groups/territory/diocese \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update Module Group
```bash
curl -X PUT http://localhost:8000/api/module-groups/1 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "name": "Updated Financial Management",
    "territory_scope": "diocese",
    "is_active": true
  }'
```

### Delete Module Group
```bash
curl -X DELETE http://localhost:8000/api/module-groups/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Integration with Module Creation

When creating a module, you can now pass the `module_group_id`:

```bash
curl -X POST http://localhost:8000/api/modules \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "module_group_id": 1,
    "name": "Budget Management",
    "icon": "ri-file-list-line",
    "number": 1,
    "description": "Manage church budgets",
    "is_active": true
  }'
```

---

## Notes

1. **Auto-Generated Fields**:
   - `slug`: Auto-generated from `name` if not provided
   - `order`: Auto-generated as next available number if not provided

2. **Territory Filtering**:
   - Module groups can be filtered by `territory_scope`
   - Valid values: `diocese`, `region`, `subregion`, `church`

3. **Deletion Constraints**:
   - Cannot delete a module group if it has associated modules
   - Must delete or reassign modules first

4. **Ordering**:
   - Groups are ordered by `order` field, then by `name`
   - Use the PATCH `/order` endpoint to update display order

5. **Authentication**:
   - All endpoints require authentication via Sanctum token
   - Include `Authorization: Bearer YOUR_TOKEN` header
