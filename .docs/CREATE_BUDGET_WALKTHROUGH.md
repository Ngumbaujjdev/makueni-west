# Create Budget Page - Complete Walkthrough

## 📋 Table of Contents

1. [Overview](#overview)
2. [What You Already Have](#what-you-already-have)
3. [What You Need to Create](#what-you-need-to-create)
4. [Page Structure Breakdown](#page-structure-breakdown)
5. [Step-by-Step Implementation Guide](#step-by-step-implementation-guide)
6. [Frontend Components](#frontend-components)
7. [JavaScript Functionality](#javascript-functionality)
8. [API Integration](#api-integration)
9. [Testing Checklist](#testing-checklist)

---

## Overview

The **Create Budget** page allows users to create new budgets by:

1. Entering basic budget information (name, year, type, territory, dates)
2. Adding budget line items (income and expense items)
3. Viewing real-time totals
4. Saving as draft or creating the budget

### Current Status

✅ **You have:**

- Budget Overview page (`all-budgets.php`)
- Budget Details page (`budget-details.php`)
- Backend API endpoints for budget creation
- Template structure and styling

❌ **You need:**

- Create Budget page (`create-budget.php`)
- JavaScript file for form handling (`create-budget.js`)
- Modal for adding line items

---

## What You Already Have

### 1. Backend API Endpoints

Your backend already has these endpoints ready:

```
POST /api/budgets                    - Create new budget
GET  /api/budget-types               - Get budget types
GET  /api/budget-lines               - Get budget line templates
GET  /api/budget-categories          - Get budget categories
GET  /api/dioceses                   - Get dioceses
GET  /api/regions                    - Get regions
GET  /api/churches                   - Get churches
```

### 2. Template Structure

From your `all-budgets.php`, you're using:

- Bootstrap 5 framework
- Custom card components
- Flatpickr for date picking
- Toast notifications
- Modal dialogs

### 3. Existing JavaScript Patterns

From `budget-list.js`, you have:

- API calling with fetch
- Token authentication
- Toast notifications
- Table management

---

## What You Need to Create

### Files to Create:

1. **PHP Page**

   - Location: `diocese/budget-management/budget-overview/create-budget.php`
   - Purpose: Main form page

2. **JavaScript File**
   - Location: `assets/js/pages/budget-management/create-budget.js`
   - Purpose: Handle form logic, API calls, line items

---

## Page Structure Breakdown

### Visual Layout

```
┌─────────────────────────────────────────────────────────┐
│  Header: Create New Budget                              │
│  Breadcrumb: Home > Budget Management > Create Budget   │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  STEP 1: BASIC INFORMATION                              │
│  ┌──────────────┬──────────────┐                        │
│  │ Budget Name  │ Fiscal Year  │                        │
│  ├──────────────┼──────────────┤                        │
│  │ Budget Type  │ Territory    │                        │
│  ├──────────────┴──────────────┤                        │
│  │ Start Date   │ End Date     │                        │
│  ├──────────────────────────────┤                       │
│  │ Description (textarea)       │                        │
│  └──────────────────────────────┘                        │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  STEP 2: BUDGET LINES                [+ Add Line Item]  │
│  ┌─────────────────────────────────────────────────────┐│
│  │ # │ Type   │ Line Name │ Category │ Amount │ Action ││
│  ├───┼────────┼───────────┼──────────┼────────┼────────┤│
│  │ 1 │ Income │ Tithes    │ Income   │ 50,000 │ Delete ││
│  │ 2 │ Expense│ Salaries  │ Staff    │ 30,000 │ Delete ││
│  └─────────────────────────────────────────────────────┘│
│                                                          │
│  Total Income:  KES 50,000                              │
│  Total Expense: KES 30,000                              │
│  Net Budget:    KES 20,000                              │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  [Cancel]              [Save as Draft] [Create Budget]  │
└─────────────────────────────────────────────────────────┘
```

---

## Step-by-Step Implementation Guide

### STEP 1: Create the PHP Page

#### 1.1 Page Header & Authentication

```php
<?php
// Session and auth check
require_once __DIR__ . '/../../../includes/session-manager.php';
require_once __DIR__ . '/../../../includes/auth-check.php';
require_once __DIR__ . '/../../../includes/permission-check.php';

// Check permission
requirePermission('diocese.budgetmanagement.budgetoverview.createbudget.create');

$user = getAuthUser();
?>
```

**Why?**

- Ensures only authorized users can access
- Follows your existing security pattern
- Gets user data for created_by field

#### 1.2 HTML Structure

Follow your existing template pattern:

```html
<!DOCTYPE html>
<html lang="en" dir="ltr" data-nav-layout="vertical">
  <head>
    <!-- Same head as all-budgets.php -->
    <title>Create Budget - Diocese Budget Management</title>

    <!-- Add Flatpickr for date picking -->
    <link rel="stylesheet" href=".../flatpickr/flatpickr.min.css" />
  </head>
</html>
```

#### 1.3 Page Content Structure

```html
<body>
  <?php include 'includes/start-switcher.php' ?>
  <?php include 'includes/loader.php' ?>

  <div class="page">
    <?php include 'includes/header.php' ?>
    <?php include 'includes/sidebar.php' ?>

    <div class="main-content app-content">
      <!-- Your form goes here -->
    </div>

    <?php include 'includes/footer.php' ?>
  </div>
</body>
```

---

### STEP 2: Build the Form

#### 2.1 Basic Information Section

**Fields Needed:**

| Field          | Type     | Required | Notes                              |
| -------------- | -------- | -------- | ---------------------------------- |
| Budget Name    | Text     | Yes      | e.g., "Diocese Annual Budget 2026" |
| Fiscal Year    | Select   | Yes      | 2024-2028                          |
| Budget Type    | Select   | Yes      | Loaded from API                    |
| Territory Type | Select   | Yes      | Diocese/Region/Church              |
| Territory      | Select   | Yes      | Loaded based on type               |
| Start Date     | Date     | Yes      | Flatpickr                          |
| End Date       | Date     | Yes      | Flatpickr                          |
| Description    | Textarea | No       | Optional notes                     |

**HTML Example:**

```html
<div class="card custom-card">
  <div class="card-header">
    <div class="card-title">
      <i class="ri-information-line me-2"></i>Step 1: Basic Information
    </div>
  </div>
  <div class="card-body">
    <div class="row gy-3">
      <!-- Budget Name -->
      <div class="col-xl-6">
        <label class="form-label fw-semibold">
          Budget Name <span class="text-danger">*</span>
        </label>
        <input
          type="text"
          class="form-control"
          id="budgetName"
          name="name"
          placeholder="e.g., Diocese Annual Budget 2026"
          required
        />
      </div>

      <!-- Fiscal Year -->
      <div class="col-xl-6">
        <label class="form-label fw-semibold">
          Fiscal Year <span class="text-danger">*</span>
        </label>
        <select class="form-select" id="fiscalYear" name="fiscal_year" required>
          <option value="">Select Year</option>
          <option value="2026" selected>2026</option>
          <option value="2027">2027</option>
        </select>
      </div>

      <!-- More fields... -->
    </div>
  </div>
</div>
```

#### 2.2 Budget Lines Section

**Features:**

- Table to display added line items
- Button to open "Add Line Item" modal
- Real-time totals calculation
- Delete functionality

**HTML Structure:**

```html
<div class="card custom-card">
  <div class="card-header justify-content-between">
    <div class="card-title">
      <i class="ri-list-check me-2"></i>Step 2: Budget Lines
    </div>
    <button type="button" class="btn btn-sm btn-primary" id="addLineItemBtn">
      <i class="ri-add-line me-1"></i>Add Line Item
    </button>
  </div>
  <div class="card-body">
    <table class="table table-bordered" id="budgetLinesTable">
      <thead class="table-primary">
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Budget Line</th>
          <th>Category</th>
          <th>Budgeted Amount</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="budgetLinesTableBody">
        <!-- Line items added here via JavaScript -->
      </tbody>
      <tfoot class="table-light">
        <tr>
          <td colspan="4" class="text-end fw-semibold">Total Income:</td>
          <td class="fw-semibold text-success" id="totalIncome">KES 0.00</td>
          <td></td>
        </tr>
        <tr>
          <td colspan="4" class="text-end fw-semibold">Total Expense:</td>
          <td class="fw-semibold text-danger" id="totalExpense">KES 0.00</td>
          <td></td>
        </tr>
        <tr>
          <td colspan="4" class="text-end fw-semibold">Net Budget:</td>
          <td class="fw-semibold text-primary" id="netBudget">KES 0.00</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
```

---

### STEP 3: Add Line Item Modal

**Purpose:** Allow users to add income/expense line items

**Modal Structure:**

```html
<div class="modal fade" id="addLineItemModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">
          <i class="ri-add-circle-line me-2"></i>Add Budget Line Item
        </h6>
        <button
          type="button"
          class="btn-close"
          data-bs-dismiss="modal"
        ></button>
      </div>
      <div class="modal-body">
        <form id="addLineItemForm">
          <!-- Line Type (Income/Expense) -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">
              Type <span class="text-danger">*</span>
            </label>
            <select class="form-select" id="lineType" required>
              <option value="">Select Type</option>
              <option value="income">Income</option>
              <option value="expense">Expense</option>
            </select>
          </div>

          <!-- Budget Line (from templates) -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">
              Budget Line <span class="text-danger">*</span>
            </label>
            <select class="form-select" id="budgetLine" required>
              <option value="">Select line type first</option>
            </select>
          </div>

          <!-- Budgeted Amount -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">
              Budgeted Amount <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <span class="input-group-text">KES</span>
              <input
                type="number"
                class="form-control"
                id="budgetedAmount"
                placeholder="0.00"
                step="0.01"
                min="0"
                required
              />
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="button" class="btn btn-primary" id="saveLineItemBtn">
          <i class="ri-add-line me-1"></i>Add Line Item
        </button>
      </div>
    </div>
  </div>
</div>
```

---

### STEP 4: JavaScript Functionality

#### 4.1 Module Structure

Create `create-budget.js` with this pattern:

```javascript
const CreateBudgetManagement = (function () {
  "use strict";

  // State variables
  let budgetLines = [];
  let lineItemCounter = 0;
  let budgetTypes = [];
  let budgetLineTemplates = [];

  // DOM elements
  let elements = {};

  function init() {
    cacheDOM();
    bindEvents();
    loadInitialData();
  }

  function cacheDOM() {
    elements = {
      form: document.getElementById("createBudgetForm"),
      budgetName: document.getElementById("budgetName"),
      // ... cache all form elements
    };
  }

  function bindEvents() {
    elements.form.addEventListener("submit", handleFormSubmit);
    elements.addLineItemBtn.addEventListener("click", openModal);
    // ... bind all events
  }

  return {
    init: init,
  };
})();
```

#### 4.2 Key Functions to Implement

**1. Load Initial Data**

```javascript
async function loadInitialData() {
  await Promise.all([
    loadBudgetTypes(),
    loadBudgetLines(),
    loadBudgetCategories(),
    loadTerritories(),
  ]);
}

async function loadBudgetTypes() {
  const response = await fetch(`${API_BASE_URL}/budget-types`, {
    headers: {
      Authorization: `Bearer ${getAuthToken()}`,
      "Content-Type": "application/json",
    },
  });

  const result = await response.json();
  budgetTypes = result.data;

  // Populate dropdown
  elements.budgetType.innerHTML = '<option value="">Select Type</option>';
  budgetTypes.forEach((type) => {
    const option = document.createElement("option");
    option.value = type.id;
    option.textContent = type.name;
    elements.budgetType.appendChild(option);
  });
}
```

**2. Handle Territory Type Change**

```javascript
function handleTerritoryTypeChange(e) {
  const selectedType = e.target.value;

  // Clear territory dropdown
  elements.territory.innerHTML = '<option value="">Select Territory</option>';

  if (!selectedType) {
    elements.territory.disabled = true;
    return;
  }

  // Load territories based on type
  // If "App\Models\Diocese" -> load dioceses
  // If "App\Models\Region" -> load regions
  // etc.

  const typeKey = selectedType.split("\\").pop().toLowerCase();
  loadTerritoriesByType(typeKey);
}
```

**3. Add Line Item**

```javascript
function handleSaveLineItem() {
  // Validate form
  if (!elements.addLineItemForm.checkValidity()) {
    elements.addLineItemForm.classList.add("was-validated");
    return;
  }

  // Create line item object
  const lineItem = {
    id: ++lineItemCounter,
    line_type: elements.lineType.value,
    budget_line_id: elements.budgetLine.value,
    name: getSelectedLineName(),
    budgeted_amount: parseFloat(elements.budgetedAmount.value),
    notes: elements.lineNotes.value,
  };

  // Add to array
  budgetLines.push(lineItem);

  // Add to table
  addLineItemToTable(lineItem);

  // Update totals
  updateTotals();

  // Close modal
  bootstrap.Modal.getInstance(
    document.getElementById("addLineItemModal")
  ).hide();
}
```

**4. Update Totals**

```javascript
function updateTotals() {
  const income = budgetLines
    .filter((line) => line.line_type === "income")
    .reduce((sum, line) => sum + line.budgeted_amount, 0);

  const expense = budgetLines
    .filter((line) => line.line_type === "expense")
    .reduce((sum, line) => sum + line.budgeted_amount, 0);

  const net = income - expense;

  elements.totalIncome.textContent = `KES ${formatCurrency(income)}`;
  elements.totalExpense.textContent = `KES ${formatCurrency(expense)}`;
  elements.netBudget.textContent = `KES ${formatCurrency(net)}`;
}
```

**5. Submit Form**

```javascript
async function handleFormSubmit(e) {
  e.preventDefault();

  // Validate
  if (!elements.form.checkValidity()) {
    elements.form.classList.add("was-validated");
    return;
  }

  if (budgetLines.length === 0) {
    showToast("Please add at least one budget line item", "warning");
    return;
  }

  // Prepare data
  const formData = {
    name: elements.budgetName.value,
    budget_type_id: parseInt(elements.budgetType.value),
    territory_type: elements.territoryType.value,
    territory_id: parseInt(elements.territory.value),
    fiscal_year: parseInt(elements.fiscalYear.value),
    start_date: elements.startDate.value,
    end_date: elements.endDate.value,
    description: elements.description.value || null,
    status: "draft",
    line_items: budgetLines.map((line) => ({
      budget_line_id: line.budget_line_id,
      line_type: line.line_type,
      name: line.name,
      budgeted_amount: line.budgeted_amount,
      notes: line.notes || null,
    })),
  };

  // Submit to API
  const response = await fetch(`${API_BASE_URL}/budgets`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${getAuthToken()}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(formData),
  });

  const result = await response.json();

  if (response.ok) {
    showToast("Budget created successfully!", "success");
    // Redirect to budget details
    window.location.href = `/makueni-west/diocese/budget-management/budget-overview/budget-detailsid=${result.data.id}`;
  } else {
    showToast(result.message || "Failed to create budget", "error");
  }
}
```

---

## Frontend Components

### 1. Form Validation

**Bootstrap Validation:**

```javascript
// Add 'was-validated' class to show errors
form.classList.add("was-validated");

// Check validity
if (!form.checkValidity()) {
  return;
}
```

**HTML Validation Attributes:**

```html
<input type="text" required />
<input type="number" min="0" step="0.01" required />
<select required></select>
```

### 2. Date Pickers

**Initialize Flatpickr:**

```javascript
flatpickr("#startDate", {
  dateFormat: "Y-m-d",
  onChange: function (selectedDates, dateStr) {
    // Set minimum date for end date
    flatpickr("#endDate").set("minDate", dateStr);
  },
});

flatpickr("#endDate", {
  dateFormat: "Y-m-d",
});
```

### 3. Dynamic Dropdowns

**Pattern:**

```javascript
// When type changes, load related data
typeSelect.addEventListener("change", async (e) => {
  const type = e.target.value;

  // Clear dependent dropdown
  dependentSelect.innerHTML = '<option value="">Loading...</option>';

  // Load data
  const data = await loadData(type);

  // Populate dropdown
  dependentSelect.innerHTML = '<option value="">Select...</option>';
  data.forEach((item) => {
    const option = document.createElement("option");
    option.value = item.id;
    option.textContent = item.name;
    dependentSelect.appendChild(option);
  });
});
```

---

## API Integration

### Request Format

**Create Budget:**

```http
POST /api/budgets
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Diocese Annual Budget 2026",
  "budget_type_id": 1,
  "territory_type": "App\\Models\\Diocese",
  "territory_id": 1,
  "fiscal_year": 2026,
  "start_date": "2026-01-01",
  "end_date": "2026-12-31",
  "description": "Annual budget for 2026",
  "status": "draft",
  "line_items": [
    {
      "budget_line_id": 5,
      "line_type": "income",
      "name": "Tithes and Offerings",
      "budgeted_amount": 500000.00,
      "notes": "Monthly collections"
    },
    {
      "budget_line_id": 12,
      "line_type": "expense",
      "name": "Staff Salaries",
      "budgeted_amount": 300000.00,
      "notes": null
    }
  ]
}
```

### Response Format

**Success (201 Created):**

```json
{
  "success": true,
  "status": 201,
  "message": "Budget created successfully",
  "data": {
    "id": 15,
    "name": "Diocese Annual Budget 2026",
    "slug": "diocese-annual-budget-2026",
    "fiscal_year": 2026,
    "status": "draft",
    "total_income_budgeted": "500000.00",
    "total_expense_budgeted": "300000.00",
    "net_income_budgeted": "200000.00",
    "created_at": "2026-01-15T17:00:00.000000Z"
  }
}
```

**Error (422 Validation Error):**

```json
{
  "success": false,
  "status": 422,
  "message": "Validation failed",
  "errors": {
    "name": ["The name field is required."],
    "fiscal_year": ["The fiscal year must be between 2000 and 2100."]
  }
}
```

---

## Testing Checklist

### ✅ Form Validation

- [ ] All required fields show error when empty
- [ ] Fiscal year accepts only valid years
- [ ] Start date must be before end date
- [ ] Amount fields accept only positive numbers
- [ ] Territory dropdown enables only after type selection

### ✅ Line Items

- [ ] Can add income line items
- [ ] Can add expense line items
- [ ] Can delete line items
- [ ] Totals update correctly
- [ ] Cannot submit without at least one line item
- [ ] Row numbers update after deletion

### ✅ API Integration

- [ ] Budget types load correctly
- [ ] Budget lines load correctly
- [ ] Territories load based on type
- [ ] Budget creates successfully
- [ ] Redirects to budget details after creation
- [ ] Shows error messages on failure

### ✅ User Experience

- [ ] Loading states show during API calls
- [ ] Success/error toasts display
- [ ] Form resets after successful submission
- [ ] Cancel button works
- [ ] Modal opens and closes correctly

---

## Quick Start Guide

### 1. Create the Files

**PHP Page:**

```
Location: diocese/budget-management/budget-overview/create-budget.php
Copy structure from: all-budgets.php
```

**JavaScript:**

```
Location: assets/js/pages/budget-management/create-budget.js
Pattern: Similar to budget-list.js
```

### 2. Update Navigation

Add link to create budget in `all-budgets.php`:

```html
<button
  class="btn btn-primary btn-wave"
  onclick="window.location.href='create-budget.php'"
>
  <i class="ri-add-line me-1"></i>Create New Budget
</button>
```

### 3. Test the Flow

1. Navigate to All Budgets page
2. Click "Create New Budget"
3. Fill in basic information
4. Add line items
5. Submit form
6. Verify budget appears in list

---

## Summary

### What the Create Budget Page Does:

1. **Collects Basic Info**: Name, year, type, territory, dates
2. **Manages Line Items**: Add/remove income and expense items
3. **Calculates Totals**: Real-time income, expense, and net calculations
4. **Validates Data**: Client-side and server-side validation
5. **Creates Budget**: Submits to API and redirects to details

### Key Features:

- ✅ Two-step form (Basic Info + Line Items)
- ✅ Dynamic dropdowns (territory based on type)
- ✅ Modal for adding line items
- ✅ Real-time total calculations
- ✅ Form validation
- ✅ API integration
- ✅ Success/error handling
- ✅ Responsive design

### Files Created:

1. `create-budget.php` - Main page
2. `create-budget.js` - JavaScript logic

### Next Steps:

1. Review the created files
2. Test the functionality
3. Customize styling if needed
4. Add any additional fields
5. Deploy and test with real data

---

## Need Help?

If you encounter issues:

1. **Check Browser Console** - Look for JavaScript errors
2. **Check Network Tab** - Verify API calls
3. **Check Backend Logs** - See server errors
4. **Verify Permissions** - Ensure user has create permission
5. **Test API Directly** - Use Postman to test endpoints

---

**Created:** 2026-01-15  
**Version:** 1.0  
**Author:** Techfinity Softwares
