/**
 * ============================================================================
 * EDIT USER FORM WITH TABS
 * ============================================================================
 * Handles the tab-based form for editing existing users with territory assignment
 * ============================================================================
 */

(function () {
  "use strict";

  // Choices.js instances
  let territoryTypeChoice, territoryChoice, roleChoice;

  // Secondary assignments tracking
  let secondaryAssignments = [];
  let secondaryCounter = 0;

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  document.addEventListener("DOMContentLoaded", function () {
    initializeChoices();
    setupEventListeners();
    setupTabListeners();
    setupInputValidationListeners();
    
    // Load existing user data
    loadUserData();
  });

  // ========================================================================
  // CHOICES.JS INITIALIZATION (Searchable Dropdowns)
  // ========================================================================

  function initializeChoices() {
    // Territory Type dropdown
    territoryTypeChoice = new Choices("#territoryType", {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    // Territory dropdown
    territoryChoice = new Choices("#territory", {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    // Role dropdown
    roleChoice = new Choices("#role", {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });
  }

  // ========================================================================
  // EVENT LISTENERS
  // ========================================================================

  function setupEventListeners() {
    // Form submission
    document
      .getElementById("addUserWizardForm")
      .addEventListener("submit", handleSubmit);

    // Generate buttons
    document
      .getElementById("generateCodeBtn")
      .addEventListener("click", generateEmployeeCode);
    document
      .getElementById("generatePasswordBtn")
      .addEventListener("click", generatePassword);
    document
      .getElementById("togglePasswordBtn")
      .addEventListener("click", togglePassword);

    // Territory type change
    document
      .getElementById("territoryType")
      .addEventListener("change", handleTerritoryTypeChange);

    // Territory change
    document
      .getElementById("territory")
      .addEventListener("change", handleTerritoryChange);

    // Navigation buttons
    setupNavigationButtons();

    // Primary assignment Change/Cancel buttons
    document.getElementById("changePrimaryBtn")?.addEventListener("click", handleChangePrimary);
    document.getElementById("cancelPrimaryBtn")?.addEventListener("click", handleCancelPrimaryChange);

    // Secondary assignment button
    document
      .getElementById("addSecondaryBtn")
      .addEventListener("click", addSecondaryAssignment);
  }

  /**
   * Handle Change Primary Assignment button
   */
  function handleChangePrimary() {
    // Hide card, show form
    document.getElementById("primaryAssignmentCard").style.display = "none";
    document.getElementById("primaryAssignmentForm").style.display = "block";
    document.getElementById("cancelPrimaryBtnContainer").style.display = "block";

    // Add required attributes back (they were removed when card was shown)
    document.getElementById("territoryType").setAttribute("required", "required");
    document.getElementById("territory").setAttribute("required", "required");
    document.getElementById("role").setAttribute("required", "required");

    // Clear form
    territoryTypeChoice.setChoiceByValue("");
    territoryChoice.clearChoices();
    territoryChoice.setChoices([{ value: "", label: "Select territory type first...", selected: true }], "value", "label", false);
    territoryChoice.disable();
    roleChoice.clearChoices();
    roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
    roleChoice.disable();
  }

  /**
   * Handle Cancel Primary Assignment Change
   */
  function handleCancelPrimaryChange() {
    // Show card, hide form
    document.getElementById("primaryAssignmentCard").style.display = "block";
    document.getElementById("primaryAssignmentForm").style.display = "none";
    document.getElementById("cancelPrimaryBtnContainer").style.display = "none";

    // Remove required attributes (form is hidden, so validation should not apply)
    document.getElementById("territoryType").removeAttribute("required");
    document.getElementById("territory").removeAttribute("required");
    document.getElementById("role").removeAttribute("required");
  }

  /**
   * Setup navigation button event listeners
   */
  function setupNavigationButtons() {
    const basicInfoTab = document.querySelector('a[href="#basic-info"]');
    const territoryTab = document.querySelector('a[href="#territory-assignment"]');
    const reviewTab = document.querySelector('a[href="#review-confirm"]');

    // Next to Territory Assignment
    const nextToTerritoryBtn = document.getElementById("nextToTerritoryBtn");
    if (nextToTerritoryBtn) {
      nextToTerritoryBtn.addEventListener("click", function () {
        if (validateBasicInfo()) {
          const tab = new bootstrap.Tab(territoryTab);
          tab.show();
        }
      });
    }

    // Back to Basic Info
    const backToBasicBtn = document.getElementById("backToBasicBtn");
    if (backToBasicBtn) {
      backToBasicBtn.addEventListener("click", function () {
        const tab = new bootstrap.Tab(basicInfoTab);
        tab.show();
      });
    }

    // Next to Review
    const nextToReviewBtn = document.getElementById("nextToReviewBtn");
    if (nextToReviewBtn) {
      nextToReviewBtn.addEventListener("click", function () {
        if (validateAssignment()) {
          const tab = new bootstrap.Tab(reviewTab);
          tab.show();
        }
      });
    }

    // Back to Territory Assignment
    const backToTerritoryBtn = document.getElementById("backToTerritoryBtn");
    if (backToTerritoryBtn) {
      backToTerritoryBtn.addEventListener("click", function () {
        const tab = new bootstrap.Tab(territoryTab);
        tab.show();
      });
    }
  }

  // ========================================================================
  // TAB LISTENERS
  // ========================================================================

  function setupTabListeners() {
    // Get all tab links
    const basicInfoTab = document.querySelector('a[href="#basic-info"]');
    const territoryTab = document.querySelector('a[href="#territory-assignment"]');
    const reviewTab = document.querySelector('a[href="#review-confirm"]');

    // Prevent default tab switching and validate first
    if (territoryTab) {
      territoryTab.addEventListener("click", function (e) {
        // Validate basic info before allowing navigation to territory assignment
        if (!validateBasicInfo()) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
      });
    }

    if (reviewTab) {
      reviewTab.addEventListener("click", function (e) {
        // Validate both basic info and territory assignment before review
        if (!validateBasicInfo()) {
          e.preventDefault();
          e.stopPropagation();
          // Switch to basic info tab
          const tab = new bootstrap.Tab(basicInfoTab);
          tab.show();
          return false;
        }
        if (!validateAssignment()) {
          e.preventDefault();
          e.stopPropagation();
          // Switch to territory assignment tab
          const tab = new bootstrap.Tab(territoryTab);
          tab.show();
          return false;
        }
      });

      // Populate review when Review tab is shown
      reviewTab.addEventListener("shown.bs.tab", function () {
        populateReview();
      });
    }
  }
  // ========================================================================
  // LOAD EXISTING USER DATA
  // ========================================================================

  async function loadUserData() {
    try {
      // Get user ID from global variable (set in PHP)
      if (typeof EDIT_USER_ID === 'undefined' || !EDIT_USER_ID) {
        Toast.error("User ID not found");
        setTimeout(() => {
          window.location.href = "/makueni-west/diocese/settings/admin/users";
        }, 2000);
        return;
      }

      console.log("📥 Loading user data for ID:", EDIT_USER_ID);
      console.log("📥 EDIT_USER_ID type:", typeof EDIT_USER_ID);
      console.log("📥 EDIT_USER_ID value:", EDIT_USER_ID);

      // Fetch user data
      const response = await APIHandler.getUser(EDIT_USER_ID);

      if (!response || !response.success) {
        throw new Error(response?.message || "Failed to load user data");
      }

      const user = response.data;
      console.log("✅ User data loaded:", user);

      // Populate basic info
      document.getElementById("firstName").value = user.firstname || "";
      document.getElementById("lastName").value = user.lastname || "";
      document.getElementById("email").value = user.email || "";
      document.getElementById("phone").value = user.phone || "";
      document.getElementById("position").value = user.position || "";
      document.getElementById("employeeCode").value = user.employee_code || "";

      // Make employee code readonly
      document.getElementById("employeeCode").readOnly = true;
      document.getElementById("generateCodeBtn").disabled = true;

      // Make password optional
      const passwordInput = document.getElementById("password");
      passwordInput.placeholder = "Leave blank to keep current password";
      passwordInput.value = "";
      passwordInput.required = false;
      document.getElementById("generatePasswordBtn").innerHTML = '<i class="ri-refresh-line"></i> Generate New';
      document.getElementById("mustChangePassword").checked = false;

      // Load assignments if they exist
      // Backend returns 'active_assignments', not 'territorial_assignments'
      const assignments = user.active_assignments || user.territorial_assignments || [];
      console.log("📋 User assignments:", assignments);
      
      // ✅ STORE ORIGINAL ASSIGNMENTS FOR COMPARISON
      window.originalUserAssignments = JSON.parse(JSON.stringify(assignments)); // Deep copy
      
      if (assignments && assignments.length > 0) {
        const primaryAssignment = assignments.find(a => a.assignment_type === 'primary' || a.is_primary);
        const secondaryAssignmentsList = assignments.filter(a => a.assignment_type === 'secondary' && !a.is_primary);

        console.log("📌 Primary assignment:", primaryAssignment);
        console.log("📌 Secondary assignments:", secondaryAssignmentsList);

        // Load primary assignment
        if (primaryAssignment && primaryAssignment.territory) {
          await loadPrimaryAssignment(primaryAssignment);
        }

        // Load secondary assignments
        for (const assignment of secondaryAssignmentsList) {
          await loadSecondaryAssignment(assignment);
        }
      } else {
        console.warn("⚠️ No assignments found for user");
        // Initialize empty array if no assignments
        window.originalUserAssignments = [];
      }

      Toast.success("User data loaded successfully");

    } catch (error) {
      console.error("Error loading user data:", error);
      Toast.error(error.message || "Failed to load user data");
      setTimeout(() => {
        window.location.href = "/makueni-west/diocese/settings/admin/users";
      }, 3000);
    }
  }

  async function loadPrimaryAssignment(assignment) {
    // Get assignment details
    const territoryType = assignment.territory.type || assignment.territory.territory_type;
    const territoryName = assignment.territory.name;
    const roleName = assignment.role.name;

    console.log("Displaying primary assignment:", { territoryType, territoryName, roleName });

    // Populate display card
    document.getElementById("primaryTerritoryType").textContent = territoryType.charAt(0).toUpperCase() + territoryType.slice(1);
    document.getElementById("primaryTerritoryName").textContent = territoryName;
    document.getElementById("primaryRoleName").textContent = roleName;

    // Show card, hide form
    document.getElementById("primaryAssignmentCard").style.display = "block";
    document.getElementById("primaryAssignmentForm").style.display = "none";

    // Remove required attributes (form is hidden, so validation should not apply)
    document.getElementById("territoryType").removeAttribute("required");
    document.getElementById("territory").removeAttribute("required");
    document.getElementById("role").removeAttribute("required");

    // Store assignment data for later use
    window.currentPrimaryAssignment = assignment;
  }

  async function loadSecondaryAssignment(assignment) {
    // Get assignment details
    const territoryType = assignment.territory.type || assignment.territory.territory_type;
    const territoryName = assignment.territory.name;
    const roleName = assignment.role.name;
    const assignmentId = assignment.id;

    console.log("Displaying secondary assignment:", { assignmentId, territoryType, territoryName, roleName });

    // Create display card HTML
    const cardHTML = `
      <div class="secondary-assignment-card card border-0 shadow-sm mb-3" data-assignment-id="${assignmentId}">
        <div class="card-header bg-gradient-secondary text-white d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center">
            <i class="ri-user-add-line fs-5 me-2"></i>
            <span class="fw-semibold">Secondary Assignment</span>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-light me-1 change-secondary-btn">
              <i class="ri-edit-line me-1"></i>Change
            </button>
            <button type="button" class="btn btn-sm btn-danger remove-secondary-btn">
              <i class="ri-delete-bin-line me-1"></i>Remove
            </button>
          </div>
        </div>
        <div class="card-body bg-light">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="d-flex align-items-start">
                <i class="ri-map-pin-line text-secondary fs-5 me-2 mt-1"></i>
                <div>
                  <p class="mb-1 text-muted small">Territory Type</p>
                  <p class="fw-semibold mb-0">${territoryType.charAt(0).toUpperCase() + territoryType.slice(1)}</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex align-items-start">
                <i class="ri-building-line text-success fs-5 me-2 mt-1"></i>
                <div>
                  <p class="mb-1 text-muted small">Territory</p>
                  <p class="fw-semibold mb-0">${territoryName}</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex align-items-start">
                <i class="ri-user-star-line text-info fs-5 me-2 mt-1"></i>
                <div>
                  <p class="mb-1 text-muted small">Role</p>
                  <p class="fw-semibold mb-0">${roleName}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    // Hide "no assignments" message
    document.getElementById("noSecondaryMsg").style.display = "none";

    // Add card to container
    document.getElementById("secondaryAssignmentsContainer").insertAdjacentHTML("beforeend", cardHTML);

    // Hide "Add Secondary" button (user can only have one secondary assignment)
    document.getElementById("addSecondaryBtn").style.display = "none";

    // Store assignment data
    if (!window.currentSecondaryAssignments) {
      window.currentSecondaryAssignments = [];
    }
    window.currentSecondaryAssignments.push(assignment);

    // Add event listeners for this card
    setupSecondaryCardListeners(assignmentId);
  }

  function setupSecondaryCardListeners(assignmentId) {
    const card = document.querySelector(`.secondary-assignment-card[data-assignment-id="${assignmentId}"]`);
    if (!card) return;

    // Change button
    const changeBtn = card.querySelector(".change-secondary-btn");
    changeBtn.addEventListener("click", function() {
      // Find the assignment data
      const assignment = window.currentSecondaryAssignments.find(a => a.id === assignmentId);
      if (!assignment) {
        Toast.error("Assignment data not found");
        return;
      }

      // Hide the card
      card.style.display = "none";

      // Create an editable form for this assignment
      const formHTML = `
        <div class="secondary-assignment-form border rounded p-3 mb-3" data-assignment-id="${assignmentId}">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0"><i class="ri-edit-line me-2"></i>Edit Secondary Assignment</h6>
          </div>
          <div class="row gy-3">
            <div class="col-md-4">
              <label class="form-label">Territory Type</label>
              <select class="form-control sec-territory-type" id="secTerritoryType-${assignmentId}">
                <option value="">Select territory type...</option>
                <option value="diocese">Diocese</option>
                <option value="region">Region</option>
                <option value="church">Church</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Territory</label>
              <select class="form-control sec-territory" id="secTerritory-${assignmentId}" disabled>
                <option value="">Select territory type first...</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Role</label>
              <select class="form-control sec-role" id="secRole-${assignmentId}" disabled>
                <option value="">Select territory first...</option>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <button type="button" class="btn btn-primary btn-sm save-secondary-btn">
              <i class="ri-save-line me-1"></i>Save Changes
            </button>
            <button type="button" class="btn btn-secondary btn-sm cancel-secondary-btn">
              <i class="ri-close-line me-1"></i>Cancel
            </button>
          </div>
        </div>
      `;

      // Insert form after the card
      card.insertAdjacentHTML("afterend", formHTML);

      // Initialize Choices.js for the form
      const typeChoice = new Choices(`#secTerritoryType-${assignmentId}`, {
        searchEnabled: true,
        itemSelectText: "",
        shouldSort: false,
      });

      const territoryChoice = new Choices(`#secTerritory-${assignmentId}`, {
        searchEnabled: true,
        itemSelectText: "",
        shouldSort: false,
      });

      const roleChoice = new Choices(`#secRole-${assignmentId}`, {
        searchEnabled: true,
        itemSelectText: "",
        shouldSort: false,
      });

      // Add event listeners for cascading dropdowns
      document.getElementById(`secTerritoryType-${assignmentId}`).addEventListener("change", async function() {
        await handleSecondaryTerritoryTypeChange(assignmentId, typeChoice, territoryChoice, roleChoice);
      });

      document.getElementById(`secTerritory-${assignmentId}`).addEventListener("change", async function() {
        await handleSecondaryTerritoryChange(assignmentId, typeChoice, territoryChoice, roleChoice);
      });

      // Setup Save and Cancel buttons
      const form = document.querySelector(`.secondary-assignment-form[data-assignment-id="${assignmentId}"]`);
      
      form.querySelector(".save-secondary-btn").addEventListener("click", function() {
        const territoryType = document.getElementById(`secTerritoryType-${assignmentId}`).value;
        const territoryId = document.getElementById(`secTerritory-${assignmentId}`).value;
        const roleId = document.getElementById(`secRole-${assignmentId}`).value;

        if (!territoryType || !territoryId || !roleId) {
          Toast.error("Please select territory type, territory, and role");
          return;
        }

        // Get territory and role names from Choices.js
        const territoryName = territoryChoice._currentState.choices.find(c => c.value == territoryId)?.label || "";
        const roleName = roleChoice._currentState.choices.find(c => c.value == roleId)?.label || "";

        // Update the assignment data
        const assignmentIndex = window.currentSecondaryAssignments.findIndex(a => a.id === assignmentId);
        if (assignmentIndex !== -1) {
          window.currentSecondaryAssignments[assignmentIndex] = {
            id: assignmentId,
            territory: { id: territoryId, name: territoryName, type: territoryType },
            role: { id: roleId, name: roleName }
          };
        }

        // Update the card display
        card.querySelector(".col-md-4:nth-child(1) .fw-semibold").textContent = territoryType.charAt(0).toUpperCase() + territoryType.slice(1);
        card.querySelector(".col-md-4:nth-child(2) .fw-semibold").textContent = territoryName;
        card.querySelector(".col-md-4:nth-child(3) .fw-semibold").textContent = roleName;

        // Remove form, show card
        form.remove();
        card.style.display = "block";

        Toast.success("Secondary assignment updated");
      });

      form.querySelector(".cancel-secondary-btn").addEventListener("click", function() {
        // Remove form, show card
        form.remove();
        card.style.display = "block";
      });
    });

    // Remove button
    const removeBtn = card.querySelector(".remove-secondary-btn");
    removeBtn.addEventListener("click", function() {
      // Get assignment details for confirmation message
      const assignment = window.currentSecondaryAssignments.find(a => a.id === assignmentId);
      const territoryName = assignment?.territory?.name || "this assignment";
      
      // Show confirmation toast with custom options
      Toast.confirm(
        `Are you sure you want to remove the secondary assignment for <strong>${territoryName}</strong>?`,
        function () {
          // User clicked "Confirm" - proceed with removal
          // Remove from DOM
          card.remove();

          // Remove from stored data
          window.currentSecondaryAssignments = window.currentSecondaryAssignments.filter(a => a.id !== assignmentId);

          // Show "Add Secondary" button again
          document.getElementById("addSecondaryBtn").style.display = "inline-block";

          // Show "no assignments" message if no more secondary assignments
          const remainingCards = document.querySelectorAll(".secondary-assignment-card");
          if (remainingCards.length === 0) {
            document.getElementById("noSecondaryMsg").style.display = "block";
          }

          Toast.success("Secondary assignment removed");
        },
        function () {
          // User clicked "Cancel" - do nothing
          console.log("User cancelled assignment removal");
        },
        {
          title: "Remove Assignment",
          confirmText: "Confirm",
          cancelText: "Cancel",
          type: "warning",
        }
      );
    });
  }


  // ========================================================================
  // VALIDATION
  // ========================================================================

  function validateBasicInfo() {
    // Clear previous validation states
    clearValidationErrors();

    const firstName = document.getElementById("firstName").value.trim();
    const lastName = document.getElementById("lastName").value.trim();
    const email = document.getElementById("email").value.trim();
    const employeeCode = document.getElementById("employeeCode").value.trim();
    const password = document.getElementById("password").value.trim();

    let isValid = true;
    let firstErrorField = null;

    if (!firstName) {
      markFieldAsInvalid("firstName");
      Toast.error("First name is required");
      isValid = false;
      if (!firstErrorField) firstErrorField = "firstName";
    }

    if (!lastName) {
      markFieldAsInvalid("lastName");
      Toast.error("Last name is required");
      isValid = false;
      if (!firstErrorField) firstErrorField = "lastName";
    }

    if (!email) {
      markFieldAsInvalid("email");
      Toast.error("Email is required");
      isValid = false;
      if (!firstErrorField) firstErrorField = "email";
    } else if (!isValidEmail(email)) {
      markFieldAsInvalid("email");
      Toast.error("Please enter a valid email address");
      isValid = false;
      if (!firstErrorField) firstErrorField = "email";
    }

    if (!employeeCode) {
      markFieldAsInvalid("employeeCode");
      Toast.error("Please generate an employee code");
      isValid = false;
      if (!firstErrorField) firstErrorField = "employeeCode";
    }

    // Password is optional for edit mode (only required if changing password)
    // Skip password validation

    // Focus on first error field
    if (firstErrorField) {
      document.getElementById(firstErrorField).focus();
    }

    return isValid;
  }

  function validateAssignment() {
    // Check if primary assignment form is visible or card is visible
    const primaryFormVisible = document.getElementById("primaryAssignmentForm").style.display !== "none";
    
    if (primaryFormVisible) {
      // Form is visible - validate form fields
      const territoryType = document.getElementById("territoryType").value;
      const territory = document.getElementById("territory").value;
      const role = document.getElementById("role").value;

      let isValid = true;
      let firstErrorField = null;

      if (!territoryType) {
        markFieldAsInvalid("territoryType");
        Toast.error("Please select a territory type");
        isValid = false;
        if (!firstErrorField) firstErrorField = "territoryType";
      }

      if (!territory) {
        markFieldAsInvalid("territory");
        Toast.error("Please select a territory");
        isValid = false;
        if (!firstErrorField) firstErrorField = "territory";
      }

      if (!role) {
        markFieldAsInvalid("role");
        Toast.error("Please select a role");
        isValid = false;
        if (!firstErrorField) firstErrorField = "role";
      }

      // Focus on first error field
      if (firstErrorField) {
        const field = document.getElementById(firstErrorField);
        if (field) {
          field.focus();
          // For Choices.js dropdowns, we need to focus on the input
          const choicesInput = field.parentElement.querySelector('.choices__input');
          if (choicesInput) {
            choicesInput.focus();
          }
        }
      }

      return isValid;
    } else {
      // Card is visible - check stored data
      if (!window.currentPrimaryAssignment) {
        Toast.error("Primary assignment is required");
        return false;
      }
      return true;
    }
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /**
   * Mark a field as invalid with visual feedback
   */
  function markFieldAsInvalid(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) {
      field.classList.add("is-invalid");
      field.style.borderColor = "#dc3545";
      
      // For Choices.js dropdowns, add border to the container
      const choicesContainer = field.parentElement.querySelector('.choices');
      if (choicesContainer) {
        choicesContainer.style.borderColor = "#dc3545";
      }
    }
  }

  /**
   * Clear validation errors from all fields
   */
  function clearValidationErrors() {
    // Clear all input fields
    const inputs = document.querySelectorAll(".form-control");
    inputs.forEach((input) => {
      input.classList.remove("is-invalid");
      input.style.borderColor = "";
    });

    // Clear all Choices.js dropdowns
    const choicesContainers = document.querySelectorAll('.choices');
    choicesContainers.forEach((container) => {
      container.style.borderColor = "";
    });
  }

  /**
   * Setup input listeners to clear errors on user input
   */
  function setupInputValidationListeners() {
    const fields = [
      "firstName", "lastName", "email", "phone", 
      "position", "employeeCode", "password",
      "territoryType", "territory", "role"
    ];

    fields.forEach((fieldId) => {
      const field = document.getElementById(fieldId);
      if (field) {
        field.addEventListener("input", function () {
          this.classList.remove("is-invalid");
          this.style.borderColor = "";
          
          // Clear Choices.js container border if applicable
          const choicesContainer = this.parentElement.querySelector('.choices');
          if (choicesContainer) {
            choicesContainer.style.borderColor = "";
          }
        });

        // For select elements (Choices.js), listen to change event
        field.addEventListener("change", function () {
          this.classList.remove("is-invalid");
          this.style.borderColor = "";
          
          const choicesContainer = this.parentElement.querySelector('.choices');
          if (choicesContainer) {
            choicesContainer.style.borderColor = "";
          }
        });
      }
    });
  }

  // ========================================================================
  // CREDENTIAL GENERATION
  // ========================================================================

  function generateCredentials() {
    generateEmployeeCode();
    generatePassword();
  }

  function generateEmployeeCode() {
    const code = UserManagementUtils.generateEmployeeCode();
    document.getElementById("employeeCode").value = code;
  }

  function generatePassword() {
    const password = UserManagementUtils.generatePassword();
    document.getElementById("password").value = password;
  }

  function togglePassword() {
    const passwordInput = document.getElementById("password");
    const toggleBtn = document.getElementById("togglePasswordBtn");
    const icon = toggleBtn.querySelector("i");

    if (passwordInput.type === "password") {
      passwordInput.type = "text";
      icon.className = "ri-eye-off-line";
    } else {
      passwordInput.type = "password";
      icon.className = "ri-eye-line";
    }
  }

  // ========================================================================
  // SECONDARY ASSIGNMENTS
  // ========================================================================

  /**
   * Add a new secondary assignment row
   */
  function addSecondaryAssignment() {
    secondaryCounter++;
    const assignmentId = `secondary-${secondaryCounter}`;

    // Hide "no assignments" message
    document.getElementById("noSecondaryMsg").style.display = "none";

    // Create assignment HTML
    const assignmentHTML = `
      <div class="secondary-assignment border rounded p-3 mb-3" id="${assignmentId}" data-assignment-id="${secondaryCounter}">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0"><i class="ri-user-add-line me-2"></i>Secondary Assignment #${secondaryCounter}</h6>
          <button type="button" class="btn btn-sm btn-danger" onclick="removeSecondaryAssignment('${assignmentId}')">
            <i class="ri-delete-bin-line me-1"></i>Remove
          </button>
        </div>
        <div class="row gy-3">
          <div class="col-md-4">
            <label class="form-label">Territory Type</label>
            <select class="form-control" id="secTerritoryType-${secondaryCounter}">
              <option value="">Select territory type...</option>
              <option value="diocese">Diocese</option>
              <option value="region">Region</option>
              <option value="church">Church</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Territory</label>
            <select class="form-control" id="secTerritory-${secondaryCounter}" disabled>
              <option value="">Select territory type first...</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select class="form-control" id="secRole-${secondaryCounter}" disabled>
              <option value="">Select territory first...</option>
            </select>
          </div>
        </div>
      </div>
    `;

    // Add to container
    document.getElementById("secondaryAssignmentsContainer").insertAdjacentHTML("beforeend", assignmentHTML);

    // Initialize Choices.js for this assignment
    const typeChoice = new Choices(`#secTerritoryType-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    const territoryChoice = new Choices(`#secTerritory-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    const roleChoice = new Choices(`#secRole-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    // Store the assignment data
    secondaryAssignments.push({
      id: secondaryCounter,
      typeChoice,
      territoryChoice,
      roleChoice,
    });

    // Add event listeners for this assignment
    document.getElementById(`secTerritoryType-${secondaryCounter}`).addEventListener("change", function () {
      handleSecondaryTerritoryTypeChange(secondaryCounter, typeChoice, territoryChoice, roleChoice);
    });

    document.getElementById(`secTerritory-${secondaryCounter}`).addEventListener("change", function () {
      handleSecondaryTerritoryChange(secondaryCounter, typeChoice, territoryChoice, roleChoice);
    });
  }

  /**
   * Remove a secondary assignment
   */
  window.removeSecondaryAssignment = function (assignmentId) {
    const element = document.getElementById(assignmentId);
    if (element) {
      const dataId = parseInt(element.getAttribute("data-assignment-id"));

      // Remove from array
      secondaryAssignments = secondaryAssignments.filter((a) => a.id !== dataId);

      // Remove from DOM
      element.remove();

      // Show "no assignments" message if no secondary assignments left
      if (secondaryAssignments.length === 0) {
        document.getElementById("noSecondaryMsg").style.display = "block";
      }
    }
  };

  /**
   * Handle territory type change for secondary assignment
   */
  async function handleSecondaryTerritoryTypeChange(id, typeChoice, territoryChoice, roleChoice) {
    const territoryType = document.getElementById(`secTerritoryType-${id}`).value;

    // Reset territory and role
    territoryChoice.clearChoices();
    territoryChoice.setChoices([{ value: "", label: "Loading...", selected: true }], "value", "label", false);
    territoryChoice.disable();

    roleChoice.clearChoices();
    roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
    roleChoice.disable();

    if (!territoryType) {
      territoryChoice.clearChoices();
      territoryChoice.setChoices([{ value: "", label: "Select territory type first...", selected: true }], "value", "label", false);
      return;
    }

    try {
      let response;
      if (territoryType === "diocese") {
        response = await APIHandler.getDioceses("all=true");
      } else if (territoryType === "region") {
        response = await APIHandler.getRegions("all=true");
      } else if (territoryType === "church") {
        response = await APIHandler.getChurches("all=true");
      }

      if (response && response.success) {
        const territories = response.data.dioceses || response.data.regions || response.data.churches || [];

        territoryChoice.clearChoices();
        const choices = [{ value: "", label: "Select territory...", selected: true }];

        territories.forEach((territory) => {
          choices.push({
            value: territory.id,
            label: territory.name,
            customProperties: { type: territory.territory_type },
          });
        });

        territoryChoice.setChoices(choices, "value", "label", false);
        territoryChoice.enable();
      } else {
        Toast.error("Failed to load territories");
      }
    } catch (error) {
      console.error("Error loading territories:", error);
      Toast.error("Failed to load territories");
    }
  }

  /**
   * Handle territory change for secondary assignment
   */
  async function handleSecondaryTerritoryChange(id, typeChoice, territoryChoice, roleChoice) {
    const territoryValue = document.getElementById(`secTerritory-${id}`).value;

    if (!territoryValue) {
      roleChoice.clearChoices();
      roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
      return;
    }

    // Get territory type from Choices.js
    const allChoices = territoryChoice._currentState.choices;
    const selectedOption = allChoices.find((c) => c.value == territoryValue);
    const territoryType = selectedOption?.customProperties?.type;

    roleChoice.clearChoices();
    roleChoice.setChoices([{ value: "", label: "Loading...", selected: true }], "value", "label", false);
    roleChoice.disable();

    if (!territoryType) {
      roleChoice.clearChoices();
      roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
      return;
    }

    try {
      const response = await APIHandler.getRoles(`territory_level=${territoryType}`);

      if (response && response.success) {
        const roles = response.data.roles || [];

        roleChoice.clearChoices();
        const choices = [{ value: "", label: "Select role...", selected: true }];

        roles.forEach((role) => {
          choices.push({ value: role.id, label: role.name });
        });

        roleChoice.setChoices(choices, "value", "label", false);
        roleChoice.enable();
      } else {
        Toast.error("Failed to load roles");
      }
    } catch (error) {
      console.error("Error loading roles:", error);
      Toast.error("Failed to load roles");
    }
  }

  // ========================================================================
  // TERRITORY & ROLE LOADING
  // ========================================================================

  async function handleTerritoryTypeChange() {
    const territoryType = document.getElementById("territoryType").value;
    const territorySelect = document.getElementById("territory");
    const roleSelect = document.getElementById("role");

    console.log("🔍 Territory Type Changed:", territoryType);

    // Reset territory and role
    territoryChoice.clearChoices();
    territoryChoice.setChoices(
      [{ value: "", label: "Loading...", selected: true }],
      "value",
      "label",
      true
    );
    territoryChoice.disable();

    roleChoice.clearChoices();
    roleChoice.setChoices(
      [
        {
          value: "",
          label: "Select territory first...",
          selected: true,
        },
      ],
      "value",
      "label",
      true
    );
    roleChoice.disable();

    if (!territoryType) {
      territoryChoice.clearChoices();
      territoryChoice.setChoices(
        [
          {
            value: "",
            label: "Select territory type first...",
            selected: true,
          },
        ],
        "value",
        "label",
        true
      );
      return;
    }

    try {
      let response;

      // Load territories based on type
      if (territoryType === "diocese") {
        console.log("📡 Fetching dioceses...");
        response = await APIHandler.getDioceses("all=true");
      } else if (territoryType === "region") {
        console.log("📡 Fetching regions...");
        response = await APIHandler.getRegions("all=true");
      } else if (territoryType === "church") {
        console.log("📡 Fetching churches...");
        response = await APIHandler.getChurches("all=true");
      }

      console.log("📦 API Response:", response);

      if (response && response.success) {
        const territories =
          response.data.dioceses ||
          response.data.regions ||
          response.data.churches ||
          [];

        console.log("✅ Territories loaded:", territories.length, territories);

        // Clear and populate Choices.js
        territoryChoice.clearChoices();
        const choices = [
          { value: "", label: "Select territory...", selected: true },
        ];

        territories.forEach((territory) => {
          choices.push({
            value: territory.id,
            label: territory.name,
            customProperties: {
              type: territory.territory_type,
            },
          });
        });

        console.log("🎯 Setting choices:", choices);
        territoryChoice.setChoices(choices, "value", "label", false);
        territoryChoice.enable();
        
        console.log("✅ Dropdown enabled. Current value:", territorySelect.value);
        console.log("✅ Choices instance state:", territoryChoice._currentState);
      } else {
        console.error("❌ API returned error:", response);
        Toast.error(response?.message || "Failed to load territories");
        territoryChoice.clearChoices();
        territoryChoice.setChoices(
          [
            {
              value: "",
              label: "Failed to load territories",
              selected: true,
            },
          ],
          "value",
          "label",
          true
        );
      }
    } catch (error) {
      console.error("💥 Error loading territories:", error);
      Toast.error("Failed to load territories: " + error.message);
      territoryChoice.clearChoices();
      territoryChoice.setChoices(
        [
          {
            value: "",
            label: "Failed to load territories",
            selected: true,
          },
        ],
        "value",
        "label",
        true
      );
    }
  }

  async function handleTerritoryChange() {
    const territorySelect = document.getElementById("territory");
    const territoryValue = territorySelect.value;
    const roleSelect = document.getElementById("role");

    // Get territory type from Choices.js custom properties
    const selectedChoice = territoryChoice.getValue(true);
    if (!selectedChoice || !territoryValue) {
      roleChoice.clearChoices();
      roleChoice.setChoices(
        [
          {
            value: "",
            label: "Select territory first...",
            selected: true,
          },
        ],
        "value",
        "label",
        true
      );
      return;
    }

    // Find the selected option to get custom properties
    const allChoices = territoryChoice._currentState.choices;
    const selectedOption = allChoices.find((c) => c.value == territoryValue);
    const territoryType = selectedOption?.customProperties?.type;

    // Reset role select
    roleChoice.clearChoices();
    roleChoice.setChoices(
      [{ value: "", label: "Loading...", selected: true }],
      "value",
      "label",
      true
    );
    roleChoice.disable();

    if (!territoryType) {
      roleChoice.clearChoices();
      roleChoice.setChoices(
        [
          {
            value: "",
            label: "Select territory first...",
            selected: true,
          },
        ],
        "value",
        "label",
        true
      );
      return;
    }

    try {
      // Load roles for this territory level
      const response = await APIHandler.getRoles(
        `territory_level=${territoryType}`
      );

      if (response.success) {
        const roles = response.data.roles || [];

        // Clear and populate Choices.js
        roleChoice.clearChoices();
        const choices = [
          { value: "", label: "Select role...", selected: true },
        ];

        roles.forEach((role) => {
          choices.push({
            value: role.id,
            label: role.name,
          });
        });

        roleChoice.setChoices(choices, "value", "label", false);
        roleChoice.enable();
      } else {
        Toast.error("Failed to load roles");
        roleChoice.clearChoices();
        roleChoice.setChoices(
          [{ value: "", label: "Failed to load roles", selected: true }],
          "value",
          "label",
          true
        );
      }
    } catch (error) {
      console.error("Error loading roles:", error);
      Toast.error("Failed to load roles");
      roleChoice.clearChoices();
      roleChoice.setChoices(
        [{ value: "", label: "Failed to load roles", selected: true }],
        "value",
        "label",
        true
      );
    }
  }

  // ========================================================================
  // REVIEW POPULATION
  // ========================================================================

  function populateReview() {
    // User info
    const firstName = document.getElementById("firstName").value;
    const lastName = document.getElementById("lastName").value;
    const email = document.getElementById("email").value;
    const phone = document.getElementById("phone").value || "Not provided";
    const position =
      document.getElementById("position").value || "Not specified";
    const employeeCode = document.getElementById("employeeCode").value;

    document.getElementById("reviewName").textContent = `${firstName} ${lastName}`;
    document.getElementById("reviewEmail").textContent = email;
    document.getElementById("reviewPhone").textContent = phone;
    document.getElementById("reviewPosition").textContent = position;
    document.getElementById("reviewEmployeeCode").textContent = employeeCode;

    // Assignments info
    const reviewContainer = document.getElementById("reviewAssignments");
    let assignmentsHTML = "";

    // Primary assignment - check if form is visible or card is visible
    let territoryName, roleName;
    
    const primaryFormVisible = document.getElementById("primaryAssignmentForm").style.display !== "none";
    
    if (primaryFormVisible) {
      // Form is visible - get from form selections
      const territoryId = document.getElementById("territory").value;
      const roleId = document.getElementById("role").value;
      
      if (territoryId && roleId) {
        // Get names from Choices.js selections
        const territoryChoices = territoryChoice._currentState.choices;
        const roleChoices = roleChoice._currentState.choices;
        
        const selectedTerritory = territoryChoices.find(c => c.value == territoryId);
        const selectedRole = roleChoices.find(c => c.value == roleId);
        
        territoryName = selectedTerritory?.label || "Not assigned";
        roleName = selectedRole?.label || "Not assigned";
      } else {
        territoryName = "Not assigned";
        roleName = "Not assigned";
      }
    } else {
      // Card is visible - get from stored data (source of truth)
      if (window.currentPrimaryAssignment) {
        territoryName = window.currentPrimaryAssignment.territory.name;
        roleName = window.currentPrimaryAssignment.role.name;
      } else {
        territoryName = "Not assigned";
        roleName = "Not assigned";
      }
    }

    assignmentsHTML += `
      <div class="d-flex align-items-start mb-3 pb-3 border-bottom">
        <div class="flex-shrink-0 me-3">
          <span class="badge bg-primary rounded-pill px-3 py-2">Primary</span>
        </div>
        <div class="flex-grow-1">
          <div class="mb-2">
            <i class="ri-map-pin-line text-primary me-2"></i>
            <span class="text-muted small">Territory:</span>
            <span class="d-block fw-semibold text-dark ms-4">${territoryName}</span>
          </div>
          <div>
            <i class="ri-shield-user-line text-primary me-2"></i>
            <span class="text-muted small">Role:</span>
            <span class="d-block fw-semibold text-dark ms-4">${roleName}</span>
          </div>
        </div>
      </div>
    `;

    // Secondary assignments - get from stored data
    if (window.currentSecondaryAssignments && window.currentSecondaryAssignments.length > 0) {
      window.currentSecondaryAssignments.forEach((assignment, index) => {
        const secTerritoryName = assignment.territory.name;
        const secRoleName = assignment.role.name;

        const isLast = index === window.currentSecondaryAssignments.length - 1;
        const borderClass = isLast ? "" : "border-bottom";

        assignmentsHTML += `
          <div class="d-flex align-items-start mb-3 pb-3 ${borderClass}">
            <div class="flex-shrink-0 me-3">
              <span class="badge bg-light text-dark border rounded-pill px-3 py-2">Secondary</span>
            </div>
            <div class="flex-grow-1">
              <div class="mb-2">
                <i class="ri-map-pin-line text-muted me-2"></i>
                <span class="text-muted small">Territory:</span>
                <span class="d-block fw-semibold text-dark ms-4">${secTerritoryName}</span>
              </div>
              <div>
                <i class="ri-shield-user-line text-muted me-2"></i>
                <span class="text-muted small">Role:</span>
                <span class="d-block fw-semibold text-dark ms-4">${secRoleName}</span>
              </div>
            </div>
          </div>
        `;
      });
    }


    reviewContainer.innerHTML = assignmentsHTML;
  }

  // ========================================================================
  // SECONDARY ASSIGNMENTS MANAGEMENT
  // ========================================================================

  /**
   * Add a new secondary assignment form
   */
  function addSecondaryAssignment() {
    secondaryCounter++;
    const assignmentId = `secondary-${secondaryCounter}`;

    // Hide "no assignments" message if it exists
    const noSecondaryMsg = document.getElementById("noSecondaryMsg");
    if (noSecondaryMsg) {
      noSecondaryMsg.style.display = "none";
    }

    // Create assignment HTML
    const assignmentHTML = `
      <div class="secondary-assignment border rounded p-3 mb-3" id="${assignmentId}" data-assignment-id="${secondaryCounter}">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0"><i class="ri-user-add-line me-2"></i>Secondary Assignment #${secondaryCounter}</h6>
          <button type="button" class="btn btn-sm btn-danger" onclick="removeSecondaryAssignment('${assignmentId}')">
            <i class="ri-delete-bin-line me-1"></i>Remove
          </button>
        </div>
        <div class="row gy-3">
          <div class="col-md-4">
            <label class="form-label">Territory Type</label>
            <select class="form-control" id="secTerritoryType-${secondaryCounter}">
              <option value="">Select territory type...</option>
              <option value="diocese">Diocese</option>
              <option value="region">Region</option>
              <option value="church">Church</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Territory</label>
            <select class="form-control" id="secTerritory-${secondaryCounter}" disabled>
              <option value="">Select territory type first...</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select class="form-control" id="secRole-${secondaryCounter}" disabled>
              <option value="">Select territory first...</option>
            </select>
          </div>
        </div>
      </div>
    `;

    // Add to container
    document.getElementById("secondaryAssignmentsContainer").insertAdjacentHTML("beforeend", assignmentHTML);

    // Initialize Choices.js for this assignment
    const typeChoice = new Choices(`#secTerritoryType-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    const territoryChoice = new Choices(`#secTerritory-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    const roleChoice = new Choices(`#secRole-${secondaryCounter}`, {
      searchEnabled: true,
      itemSelectText: "",
      shouldSort: false,
    });

    // Add event listeners for this assignment
    document.getElementById(`secTerritoryType-${secondaryCounter}`).addEventListener("change", function () {
      handleSecondaryTerritoryTypeChange(secondaryCounter, typeChoice, territoryChoice, roleChoice);
    });

    document.getElementById(`secTerritory-${secondaryCounter}`).addEventListener("change", function () {
      handleSecondaryTerritoryChange(secondaryCounter, typeChoice, territoryChoice, roleChoice);
    });

    console.log(`✅ Added secondary assignment form #${secondaryCounter}`);
  }

  /**
   * Remove a secondary assignment
   */
  window.removeSecondaryAssignment = function (assignmentId) {
    const element = document.getElementById(assignmentId);
    if (element) {
      const dataId = parseInt(element.getAttribute("data-assignment-id"));

      // If this is an existing assignment (has ID from database), remove it from currentSecondaryAssignments
      if (window.currentSecondaryAssignments) {
        // Find if this is an existing assignment by checking if it has a database ID
        const assignmentIndex = window.currentSecondaryAssignments.findIndex(a => {
          // Check if this assignment matches the form ID
          return a.formId === dataId;
        });

        if (assignmentIndex !== -1) {
          window.currentSecondaryAssignments.splice(assignmentIndex, 1);
          console.log(`🗑️ Removed assignment from currentSecondaryAssignments. Remaining:`, window.currentSecondaryAssignments.length);
        }
      }

      // Remove from DOM
      element.remove();

      // Show "no assignments" message if no secondary assignments left
      const container = document.getElementById("secondaryAssignmentsContainer");
      if (container && container.children.length === 0) {
        const noSecondaryMsg = document.getElementById("noSecondaryMsg");
        if (noSecondaryMsg) {
          noSecondaryMsg.style.display = "block";
        }
      }

      console.log(`✅ Removed secondary assignment form #${dataId}`);
    }
  };

  /**
   * Handle territory type change for secondary assignment
   */
  async function handleSecondaryTerritoryTypeChange(id, typeChoice, territoryChoice, roleChoice) {
    const territoryType = document.getElementById(`secTerritoryType-${id}`).value;

    // Reset territory and role
    territoryChoice.clearChoices();
    territoryChoice.setChoices([{ value: "", label: "Loading...", selected: true }], "value", "label", false);
    territoryChoice.disable();

    roleChoice.clearChoices();
    roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
    roleChoice.disable();

    if (!territoryType) {
      territoryChoice.clearChoices();
      territoryChoice.setChoices([{ value: "", label: "Select territory type first...", selected: true }], "value", "label", false);
      return;
    }

    try {
      let response;
      if (territoryType === "diocese") {
        response = await APIHandler.getDioceses("all=true");
      } else if (territoryType === "region") {
        response = await APIHandler.getRegions("all=true");
      } else if (territoryType === "church") {
        response = await APIHandler.getChurches("all=true");
      }

      if (response && response.success) {
        const territories = response.data.dioceses || response.data.regions || response.data.churches || [];

        territoryChoice.clearChoices();
        const choices = [{ value: "", label: "Select territory...", selected: true }];

        territories.forEach((territory) => {
          choices.push({
            value: territory.id,
            label: territory.name,
            customProperties: { type: territory.territory_type },
          });
        });

        territoryChoice.setChoices(choices, "value", "label", false);
        territoryChoice.enable();
      } else {
        Toast.error("Failed to load territories");
      }
    } catch (error) {
      console.error("Error loading territories:", error);
      Toast.error("Failed to load territories");
    }
  }

  /**
   * Handle territory change for secondary assignment
   */
  async function handleSecondaryTerritoryChange(id, typeChoice, territoryChoice, roleChoice) {
    const territoryValue = document.getElementById(`secTerritory-${id}`).value;

    if (!territoryValue) {
      roleChoice.clearChoices();
      roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
      return;
    }

    // Get territory type from Choices.js
    const allChoices = territoryChoice._currentState.choices;
    const selectedOption = allChoices.find((c) => c.value == territoryValue);
    const territoryType = selectedOption?.customProperties?.type;
    const territoryName = selectedOption?.label;

    roleChoice.clearChoices();
    roleChoice.setChoices([{ value: "", label: "Loading...", selected: true }], "value", "label", false);
    roleChoice.disable();

    if (!territoryType) {
      roleChoice.clearChoices();
      roleChoice.setChoices([{ value: "", label: "Select territory first...", selected: true }], "value", "label", false);
      return;
    }

    try {
      const response = await APIHandler.getRoles(`territory_level=${territoryType}`);

      if (response && response.success) {
        const roles = response.data.roles || [];

        roleChoice.clearChoices();
        const choices = [{ value: "", label: "Select role...", selected: true }];

        roles.forEach((role) => {
          choices.push({ value: role.id, label: role.name });
        });

        roleChoice.setChoices(choices, "value", "label", false);
        roleChoice.enable();

        // Add event listener to save the assignment when role is selected
        document.getElementById(`secRole-${id}`).addEventListener("change", function() {
          const roleValue = this.value;
          const roleOption = roles.find(r => r.id == roleValue);
          
          if (roleValue && roleOption) {
            // Initialize currentSecondaryAssignments if it doesn't exist
            if (!window.currentSecondaryAssignments) {
              window.currentSecondaryAssignments = [];
            }

            // Add or update the assignment in currentSecondaryAssignments
            const existingIndex = window.currentSecondaryAssignments.findIndex(a => a.formId === id);
            
            const assignmentData = {
              id: null, // New assignment, no database ID yet
              formId: id, // Track which form this came from
              territory: {
                id: parseInt(territoryValue),
                name: territoryName,
                type: territoryType
              },
              role: {
                id: parseInt(roleValue),
                name: roleOption.name
              },
              assignment_type: "secondary"
            };

            if (existingIndex !== -1) {
              // Update existing
              window.currentSecondaryAssignments[existingIndex] = assignmentData;
              console.log(`✏️ Updated secondary assignment #${id}:`, assignmentData);
            } else {
              // Add new
              window.currentSecondaryAssignments.push(assignmentData);
              console.log(`➕ Added new secondary assignment #${id}:`, assignmentData);
            }

            console.log(`📋 Current secondary assignments:`, window.currentSecondaryAssignments);
          }
        }, { once: false }); // Allow multiple changes
      } else {
        Toast.error("Failed to load roles");
      }
    } catch (error) {
      console.error("Error loading roles:", error);
      Toast.error("Failed to load roles");
    }
  }

  // ========================================================================
  // FORM SUBMISSION
  // ========================================================================

  async function handleSubmit(e) {
    e.preventDefault();

    // Validate all sections
    if (!validateBasicInfo()) {
      // Switch to Basic Info tab
      const basicInfoTab = document.querySelector('a[href="#basic-info"]');
      const tab = new bootstrap.Tab(basicInfoTab);
      tab.show();
      return;
    }

    if (!validateAssignment()) {
      // Switch to Territory Assignment tab
      const territoryTab = document.querySelector(
        'a[href="#territory-assignment"]'
      );
      const tab = new bootstrap.Tab(territoryTab);
      tab.show();
      return;
    }

    try {
      const submitBtn = document.getElementById("submitBtn");
      const originalHTML = submitBtn.innerHTML;

      // Disable button and show loading
      submitBtn.disabled = true;
      submitBtn.innerHTML =
        '<span class="spinner-grow spinner-grow-sm align-middle me-2" role="status" aria-hidden="true"></span>Updating User...';

      // ========================================================================
      // STEP 1: Update User Profile (NO ASSIGNMENTS)
      // ========================================================================
      const formData = {
        firstname: document.getElementById("firstName").value.trim(),
        lastname: document.getElementById("lastName").value.trim(),
        email: document.getElementById("email").value.trim(),
        phone: document.getElementById("phone").value.trim() || null,
        position: document.getElementById("position").value.trim() || null,
        employee_code: document.getElementById("employeeCode").value.trim(),
        must_change_password:
          document.getElementById("mustChangePassword").checked,
      };

      // Only include password if it's been changed
      const password = document.getElementById("password").value;
      if (password && password.trim()) {
        formData.password = password;
      }

      console.log("📤 Step 1: Updating user profile (no assignments):", formData);

      // Call UPDATE USER API (profile only)
      const userResponse = await APIHandler.updateUser(EDIT_USER_ID, formData);

      if (!userResponse.success) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHTML;
        Toast.error(userResponse.message || "Failed to update user profile");
        return;
      }

      console.log("✅ User profile updated successfully");

      // ========================================================================
      // STEP 2: Handle Assignments Separately
      // ========================================================================
      submitBtn.innerHTML =
        '<span class="spinner-grow spinner-grow-sm align-middle me-2" role="status" aria-hidden="true"></span>Updating Assignments...';

      // Collect new assignments
      const newAssignments = [];

      // Get primary assignment
      const primaryFormVisible = document.getElementById("primaryAssignmentForm").style.display !== "none";
      
      if (primaryFormVisible) {
        // User is editing - get from form
        const territoryId = document.getElementById("territory").value;
        const roleId = document.getElementById("role").value;
        
        if (!territoryId || !roleId) {
          Toast.error("Please complete the primary assignment");
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalHTML;
          return;
        }
        
        // Check if there's an existing primary assignment to preserve its ID
        const existingPrimaryId = window.currentPrimaryAssignment?.id || null;
        
        newAssignments.push({
          id: existingPrimaryId, // Preserve existing ID if changing, null if new
          territory_id: parseInt(territoryId),
          role_id: parseInt(roleId),
          assignment_type: "primary",
        });
      } else {
        // User is viewing card - get from stored data
        if (window.currentPrimaryAssignment) {
          newAssignments.push({
            id: window.currentPrimaryAssignment.id,
            territory_id: parseInt(window.currentPrimaryAssignment.territory.id),
            role_id: parseInt(window.currentPrimaryAssignment.role.id),
            assignment_type: "primary",
          });
        } else {
          Toast.error("Primary assignment is required");
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalHTML;
          return;
        }
      }

      // Add secondary assignments from stored data
      if (window.currentSecondaryAssignments && window.currentSecondaryAssignments.length > 0) {
        console.log("📋 Processing secondary assignments:", window.currentSecondaryAssignments);
        window.currentSecondaryAssignments.forEach((assignment, index) => {
          console.log(`  Secondary assignment ${index + 1}:`, {
            id: assignment.id,
            territory_id: assignment.territory.id,
            role_id: assignment.role.id,
            assignment_type: "secondary"
          });
          newAssignments.push({
            id: assignment.id,
            territory_id: parseInt(assignment.territory.id),
            role_id: parseInt(assignment.role.id),
            assignment_type: "secondary",
          });
        });
      }

      console.log("📤 Step 2: Processing assignments:", newAssignments);

      // Get original assignments from when page loaded
      const originalAssignments = window.originalUserAssignments || [];
      
      // Compare and sync assignments
      const assignmentResults = await syncUserAssignments(EDIT_USER_ID, originalAssignments, newAssignments);

      if (!assignmentResults.success) {
        Toast.warning("User updated but some assignments failed: " + assignmentResults.message);
      }

      // ========================================================================
      // STEP 3: Success - Redirect
      // ========================================================================
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalHTML;

      Toast.success("User and assignments updated successfully!");

      // Show loading state again before redirect
      submitBtn.disabled = true;
      submitBtn.innerHTML =
        '<span class="spinner-grow spinner-grow-sm align-middle me-2" role="status" aria-hidden="true"></span>Redirecting...';

      // Redirect to users page after short delay
      setTimeout(() => {
        window.location.href = "/makueni-west/diocese/settings/admin/users";
      }, 1500);

    } catch (error) {
      console.error("Error updating user:", error);
      Toast.error("Failed to update user");

      // Restore button
      const submitBtn = document.getElementById("submitBtn");
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalHTML;
    }
  }

  /**
   * Sync user assignments - create new, update changed, delete removed
   * @param {number} userId - User ID
   * @param {Array} oldAssignments - Original assignments
   * @param {Array} newAssignments - New assignments
   * @returns {Object} - Success status and message
   */
  async function syncUserAssignments(userId, oldAssignments, newAssignments) {
    const results = {
      created: [],
      updated: [],
      deleted: [],
      errors: [],
    };

    try {
      console.log("🔍 Starting assignment sync:");
      console.log("  Old assignments:", oldAssignments);
      console.log("  New assignments:", newAssignments);

      // Find assignments to delete (in old but not in new)
      for (const oldAssignment of oldAssignments) {
        const oldId = oldAssignment.id;
        const stillExists = newAssignments.find(a => a.id === oldId);
        
        console.log(`  Checking old assignment ${oldId}: stillExists =`, !!stillExists);
        
        if (!stillExists) {
          console.log(`🗑️ Deleting assignment ${oldId}`);
          const response = await APIHandler.deleteUserAssignment(oldId);
          console.log(`  Delete response:`, response);
          
          if (response.success) {
            results.deleted.push(oldId);
            console.log(`  ✅ Successfully deleted assignment ${oldId}`);
          } else {
            const errorMsg = `Failed to delete assignment ${oldId}: ${response.message}`;
            results.errors.push(errorMsg);
            console.error(`  ❌ ${errorMsg}`);
          }
        }
      }

      // Find assignments to create or update
      for (const newAssignment of newAssignments) {
        if (!newAssignment.id || newAssignment.id === null) {
          // Create new assignment
          console.log(`➕ Creating new assignment:`, newAssignment);
          const response = await APIHandler.createUserAssignment({
            user_id: userId,
            territory_id: newAssignment.territory_id,
            role_id: newAssignment.role_id,
            assignment_type: newAssignment.assignment_type,
          });
          console.log(`  Create response:`, response);
          
          if (response.success) {
            results.created.push(response.data.id);
            console.log(`  ✅ Successfully created assignment ${response.data.id}`);
          } else {
            const errorMsg = `Failed to create assignment: ${response.message}`;
            results.errors.push(errorMsg);
            console.error(`  ❌ ${errorMsg}`);
          }
        } else {
          // Check if assignment needs updating
          const oldAssignment = oldAssignments.find(a => a.id === newAssignment.id);
          if (oldAssignment) {
            const needsUpdate = 
              oldAssignment.territory.id !== newAssignment.territory_id ||
              oldAssignment.role.id !== newAssignment.role_id ||
              oldAssignment.assignment_type !== newAssignment.assignment_type;

            console.log(`  Checking assignment ${newAssignment.id} for updates: needsUpdate =`, needsUpdate);

            if (needsUpdate) {
              console.log(`✏️ Updating assignment ${newAssignment.id}`);
              console.log(`  Old: territory=${oldAssignment.territory.id}, role=${oldAssignment.role.id}, type=${oldAssignment.assignment_type}`);
              console.log(`  New: territory=${newAssignment.territory_id}, role=${newAssignment.role_id}, type=${newAssignment.assignment_type}`);
              
              const response = await APIHandler.updateUserAssignment(newAssignment.id, {
                territory_id: newAssignment.territory_id,
                role_id: newAssignment.role_id,
                assignment_type: newAssignment.assignment_type,
              });
              console.log(`  Update response:`, response);
              
              if (response.success) {
                results.updated.push(newAssignment.id);
                console.log(`  ✅ Successfully updated assignment ${newAssignment.id}`);
              } else {
                const errorMsg = `Failed to update assignment ${newAssignment.id}: ${response.message}`;
                results.errors.push(errorMsg);
                console.error(`  ❌ ${errorMsg}`);
              }
            } else {
              // Assignment exists and hasn't changed - skip it
              console.log(`  ⏭️ Skipping assignment ${newAssignment.id} - no changes needed`);
            }
          } else {
            // Assignment has an ID but doesn't exist in old assignments
            // This shouldn't happen, but log it for debugging
            console.warn(`⚠️ Assignment ${newAssignment.id} has an ID but not found in old assignments - skipping to avoid duplicate`);
          }
        }
      }

      console.log("✅ Assignment sync results:", results);

      return {
        success: results.errors.length === 0,
        message: results.errors.length > 0 ? results.errors.join(", ") : "All assignments synced successfully",
        results,
      };

    } catch (error) {
      console.error("❌ Error syncing assignments:", error);
      return {
        success: false,
        message: error.message || "Failed to sync assignments",
        results,
      };
    }
  }

  // ========================================================================
  // EXPOSE PUBLIC API (if needed)
  // ========================================================================

  window.AddUserForm = {
    populateReview,
  };
})();
