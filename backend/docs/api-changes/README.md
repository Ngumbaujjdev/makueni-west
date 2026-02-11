# API Changes Documentation

This folder contains documentation for API changes and updates that affect frontend integration.

## 📁 Contents

### [API_CHANGES_ROLE_PERMISSIONS.md](./API_CHANGES_ROLE_PERMISSIONS.md)
**Date:** 2025-12-15  
**Version:** 2.0  
**Status:** ✅ Implemented

Complete documentation for the Role Permissions API changes. The API has been simplified to use permission IDs instead of complex module/submodule/action structures.

**Key Changes:**
- Simplified request payload (array of permission IDs)
- Updated validation rules
- Improved performance with direct sync operations
- Reduced payload size

**Affected Endpoints:**
- `PUT /api/roles/{roleId}/permissions`

---

### [PERMISSIONS_FOR_ROLE_MANAGEMENT.md](./PERMISSIONS_FOR_ROLE_MANAGEMENT.md)
**Date:** 2025-12-15  
**Version:** 2.0  
**Status:** ✅ Implemented

Documentation for the new dedicated endpoint that returns permissions with IDs for role permission management UI.

**Key Features:**
- Returns permissions as objects with `id`, `action`, `territory_scope`
- Required `territory_scope` parameter for filtering
- Grouped by module → submodule → sub-submodule structure
- Designed specifically for role permission assignment UI

**New Endpoint:**
- `GET /api/permissions/for-role-management?territory_scope={scope}`

---

## 📋 How to Use This Documentation

1. **For Frontend Developers:**
   - Read the specific API change document
   - Follow the implementation guide
   - Use the code examples provided
   - Test with the provided cURL commands

2. **For Project Managers:**
   - Check the "What Changed" section for overview
   - Review the "Benefits" section
   - Use the migration checklist to track progress

3. **For QA/Testing:**
   - Use the testing examples section
   - Verify all error responses
   - Test edge cases mentioned in the documentation

---

## 🔔 Notification Process

When new API changes are documented:
1. Document is added to this folder
2. README.md is updated with new entry
3. Frontend team is notified
4. Migration checklist is tracked

---

## 📞 Contact

For questions about API changes:
- **Backend Team Lead:** [Your Name]
- **API Documentation:** `/api/documentation`
- **Postman Collection:** Available in project repository

---

**Last Updated:** 2025-12-15
