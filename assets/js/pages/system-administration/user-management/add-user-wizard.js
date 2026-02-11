/**
 * ============================================================================
 * ADD USER FORM WITH TABS
 * ============================================================================
 * Handles the tab-based form for creating new users with territory assignment
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
    generateCredentials();
    setupTabListeners();
    setupInputValidationListeners();
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

    // Secondary assignment button
    document
      .getElementById("addSecondaryBtn")
      .addEventListener("click", addSecondaryAssignment);
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

    if (!password) {
      markFieldAsInvalid("password");
      Toast.error("Please generate a password");
      isValid = false;
      if (!firstErrorField) firstErrorField = "password";
    }

    // Focus on first error field
    if (firstErrorField) {
      document.getElementById(firstErrorField).focus();
    }

    return isValid;
  }

  function validateAssignment() {
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

    // Primary assignment
    const territorySelect = document.getElementById("territory");
    const territoryName =
      territorySelect.options[territorySelect.selectedIndex]?.textContent ||
      "Not selected";

    const roleSelect = document.getElementById("role");
    const roleName =
      roleSelect.options[roleSelect.selectedIndex]?.textContent ||
      "Not selected";

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

    // Secondary assignments
    if (secondaryAssignments.length > 0) {
      secondaryAssignments.forEach((assignment, index) => {
        const secTerritorySelect = document.getElementById(`secTerritory-${assignment.id}`);
        const secTerritoryName =
          secTerritorySelect.options[secTerritorySelect.selectedIndex]?.textContent ||
          "Not selected";

        const secRoleSelect = document.getElementById(`secRole-${assignment.id}`);
        const secRoleName =
          secRoleSelect.options[secRoleSelect.selectedIndex]?.textContent ||
          "Not selected";

        const isLast = index === secondaryAssignments.length - 1;
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
        '<span class="spinner-grow spinner-grow-sm align-middle me-2" role="status" aria-hidden="true"></span>Creating User...';

      // Collect form data
      const formData = {
        firstname: document.getElementById("firstName").value.trim(),
        lastname: document.getElementById("lastName").value.trim(),
        email: document.getElementById("email").value.trim(),
        phone: document.getElementById("phone").value.trim() || null,
        position: document.getElementById("position").value.trim() || null,
        employee_code: document.getElementById("employeeCode").value.trim(),
        password: document.getElementById("password").value,
        must_change_password:
          document.getElementById("mustChangePassword").checked,
        status: "active",
      };

      // Collect assignments array (primary + secondary)
      const assignments = [];

      // Add primary assignment
      assignments.push({
        territory_id: parseInt(document.getElementById("territory").value),
        role_id: parseInt(document.getElementById("role").value),
        assignment_type: "primary",
      });

      // Add secondary assignments
      secondaryAssignments.forEach((assignment) => {
        const territoryId = document.getElementById(`secTerritory-${assignment.id}`).value;
        const roleId = document.getElementById(`secRole-${assignment.id}`).value;

        if (territoryId && roleId) {
          assignments.push({
            territory_id: parseInt(territoryId),
            role_id: parseInt(roleId),
            assignment_type: "secondary",
          });
        }
      });

      // Add assignments to form data
      formData.assignments = assignments;

      console.log("📤 Submitting user with assignments:", formData);

      // Call API
      const response = await APIHandler.createUser(formData);

      // Restore button
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalHTML;

      if (response.success) {
        Toast.success("User created successfully!");

        // Show loading state again before redirect
        submitBtn.disabled = true;
        submitBtn.innerHTML =
          '<span class="spinner-grow spinner-grow-sm align-middle me-2" role="status" aria-hidden="true"></span>Redirecting...';

        // Redirect to users page after short delay
        setTimeout(() => {
          window.location.href = "http://localhost:8080/makueni-west/diocese/settings/admin/users";
        }, 1500);
      } else {
        Toast.error(response.message || "Failed to create user");
      }
    } catch (error) {
      console.error("Error creating user:", error);
      Toast.error("Failed to create user");

      // Restore button
      const submitBtn = document.getElementById("submitBtn");
      submitBtn.disabled = false;
      submitBtn.innerHTML =
        '<i class="ri-user-add-line me-1"></i>Create User';
    }
  }

  // ========================================================================
  // EXPOSE PUBLIC API (if needed)
  // ========================================================================

  window.AddUserForm = {
    populateReview,
  };
})();
