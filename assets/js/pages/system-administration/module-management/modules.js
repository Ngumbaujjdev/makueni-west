/**
 * ============================================================================
 * MODULE MANAGEMENT - Main Module
 * ============================================================================
 * Handles module tree rendering, drag-and-drop, CRUD operations, and
 * auto-permission generation for the Module Structure tab
 * ============================================================================
 */

(function () {
  "use strict";

  // ========================================================================
  // STATE MANAGEMENT
  // ========================================================================

  let dragulaInstances = [];
  let currentTerritoryLevel = "diocese";
  let currentModuleGroups = [];
  let isInitialized = false; // Prevent duplicate initialization
  let currentSubmoduleTabIndex = 0; // Track current tab in submodule modal

  // ========================================================================
  // PUBLIC API
  // ========================================================================

  window.ModuleManagement = {
    init: initializeModuleManagement,
    loadModulesTab: loadModulesTab,
    openCreateModuleModal: openCreateModuleModal,
    openEditModuleModal: openEditModuleModal,
    openCreateSubmoduleModal: openCreateSubmoduleModal,
    openEditSubmoduleModal: openEditSubmoduleModal,
    saveSubmodule: saveSubmodule,
    openCreateSubSubmoduleModal: openCreateSubSubmoduleModal,
    openEditSubSubmoduleModal: openEditSubSubmoduleModal,
    saveSubSubmodule: saveSubSubmodule,
    deleteSubSubmodule: deleteSubSubmodule,
    deleteModule: deleteModule,
    deleteSubmodule: deleteSubmodule,
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  function initializeModuleManagement() {
    if (isInitialized) {
      console.log("⏭️ Module Management already initialized, skipping...");
      loadModulesTab(); // Just load the data
      return;
    }

    console.log("🔧 Initializing Module Management...");
    setupEventListeners();
    loadModulesTab();
    isInitialized = true;
  }

  function setupEventListeners() {
    // Territory filter - REAL-TIME (on change)
    const territoryFilter = document.getElementById("moduleTerritoryFilter");
    if (territoryFilter) {
      territoryFilter.addEventListener("change", () => {
        currentTerritoryLevel = territoryFilter.value;
        console.log("🔄 Territory changed to:", currentTerritoryLevel || "All");
        loadModulesTab();
      });
    }

    // Apply filter button (optional - for manual refresh)
    const applyFilterBtn = document.getElementById("applyModuleFilters");
    if (applyFilterBtn) {
      applyFilterBtn.addEventListener("click", () => {
        currentTerritoryLevel = territoryFilter
          ? territoryFilter.value
          : "diocese";
        console.log("🔄 Manually applying filter:", currentTerritoryLevel);
        loadModulesTab();
      });
    }

    // Create module button
    const createModuleBtn = document.getElementById("createModuleBtn");
    if (createModuleBtn) {
      createModuleBtn.addEventListener("click", openCreateModuleModal);
    }

    // Save module button
    const saveModuleBtn = document.getElementById("saveModuleBtn");
    if (saveModuleBtn) {
      saveModuleBtn.addEventListener("click", saveModule);
    }

    // Save submodule button
    const saveSubmoduleBtn = document.getElementById("saveSubmoduleBtn");
    if (saveSubmoduleBtn) {
      saveSubmoduleBtn.addEventListener("click", saveSubmodule);
    }

    // Auto-generate permissions toggle
    const autoGenPerms = document.getElementById("autoGeneratePermissions");
    if (autoGenPerms) {
      autoGenPerms.addEventListener("change", function () {
        const permOptions = document.getElementById("permissionOptions");
        if (permOptions) {
          permOptions.style.display = this.checked ? "block" : "none";
        }
      });
    }

    // Auto-generate submodule permissions toggle
    const autoGenSubPerms = document.getElementById(
      "autoGenerateSubmodulePermissions",
    );
    if (autoGenSubPerms) {
      autoGenSubPerms.addEventListener("change", function () {
        const permOptions = document.getElementById(
          "submodulePermissionOptions",
        );
        if (permOptions) {
          permOptions.style.display = this.checked ? "block" : "none";
        }
      });
    }

    // Module modal tab navigation
    initializeModuleTabNavigation();

    // Submodule modal tab navigation
    initializeSubmoduleTabNavigation();

    // Sub-submodule modal tab navigation
    initializeSubSubmoduleTabNavigation();

    // Module group selection handler
    const moduleGroupSelect = document.getElementById("moduleGroup");
    if (moduleGroupSelect) {
      moduleGroupSelect.addEventListener("change", function () {
        handleModuleGroupSelection(this.value);
      });
    }

    // Auto-generate submodule path from title
    const submoduleTitleInput = document.getElementById("submoduleTitle");
    const submodulePathInput = document.getElementById("submodulePath");

    if (submoduleTitleInput && submodulePathInput) {
      submoduleTitleInput.addEventListener("input", function () {
        // Generate URL-friendly path from title
        const title = this.value.trim();
        const modulePrefix = submodulePathInput.dataset.modulePrefix || "";

        if (title) {
          // Convert to lowercase, replace spaces with hyphens, remove special characters
          const pageName = title
            .toLowerCase()
            .replace(/\s+/g, "-") // Replace spaces with hyphens
            .replace(/[^a-z0-9-]/g, "") // Remove special characters
            .replace(/-+/g, "-") // Replace multiple hyphens with single
            .replace(/^-|-$/g, ""); // Remove leading/trailing hyphens

          // Combine module prefix with page name
          const fullPath = modulePrefix
            ? `/${modulePrefix}/${pageName}`
            : `/${pageName}`;
          submodulePathInput.value = fullPath;

          // Check for duplicate paths
          checkDuplicatePath(fullPath);
        } else {
          submodulePathInput.value = "";
          clearPathValidation();
        }
      });
    }

    // Auto-generate sub-submodule path from title
    const subSubmoduleTitleInput = document.getElementById("subSubmoduleTitle");
    const subSubmodulePathInput = document.getElementById("subSubmodulePath");

    if (subSubmoduleTitleInput && subSubmodulePathInput) {
      subSubmoduleTitleInput.addEventListener("input", function () {
        // Generate URL-friendly path from title
        const title = this.value.trim();
        const submodulePrefix =
          subSubmodulePathInput.dataset.submodulePrefix || "";

        if (title) {
          // Convert to lowercase, replace spaces with hyphens, remove special characters
          const pageName = title
            .toLowerCase()
            .replace(/\s+/g, "-") // Replace spaces with hyphens
            .replace(/[^a-z0-9-]/g, "") // Remove special characters
            .replace(/-+/g, "-") // Replace multiple hyphens with single
            .replace(/^-|-$/g, ""); // Remove leading/trailing hyphens

          // Combine submodule prefix with page name
          const fullPath = submodulePrefix
            ? `${submodulePrefix}/${pageName}`
            : `/${pageName}`;
          subSubmodulePathInput.value = fullPath;
        } else {
          subSubmodulePathInput.value = "";
        }
      });
    }
  }

  /**
   * Check if a path already exists in the current modules
   */
  function checkDuplicatePath(path) {
    const pathInput = document.getElementById("submodulePath");
    const submoduleId = document.getElementById("submoduleId").value; // Empty when creating, has value when editing

    let isDuplicate = false;

    // Search through all modules and submodules
    if (currentModuleGroups && currentModuleGroups.length > 0) {
      for (const group of currentModuleGroups) {
        if (group.modules && Array.isArray(group.modules)) {
          for (const module of group.modules) {
            if (module.submodules && Array.isArray(module.submodules)) {
              for (const submodule of module.submodules) {
                // Skip the current submodule when editing
                if (submoduleId && submodule.id == submoduleId) continue;

                if (submodule.path === path) {
                  isDuplicate = true;
                  break;
                }
              }
            }
            if (isDuplicate) break;
          }
        }
        if (isDuplicate) break;
      }
    }

    // Update UI based on duplicate status
    if (isDuplicate) {
      pathInput.classList.add("is-invalid");
      pathInput.classList.remove("is-valid");

      // Add or update error message
      let errorMsg = pathInput.parentElement.nextElementSibling;
      if (!errorMsg || !errorMsg.classList.contains("invalid-feedback")) {
        errorMsg = document.createElement("div");
        errorMsg.className = "invalid-feedback";
        pathInput.parentElement.after(errorMsg);
      }
      errorMsg.textContent =
        "⚠️ This path already exists. Please use a different title.";
    } else {
      pathInput.classList.remove("is-invalid");
      pathInput.classList.add("is-valid");
      clearPathValidation();
    }
  }

  /**
   * Clear path validation styling
   */
  function clearPathValidation() {
    const pathInput = document.getElementById("submodulePath");
    if (pathInput) {
      pathInput.classList.remove("is-invalid", "is-valid");

      // Remove error message if exists
      const errorMsg = pathInput.parentElement.nextElementSibling;
      if (errorMsg && errorMsg.classList.contains("invalid-feedback")) {
        errorMsg.remove();
      }
    }
  }

  /**
   * Initialize tab navigation for module modal
   */
  function initializeModuleTabNavigation() {
    const tabs = [
      "module-basic",
      "module-group",
      "module-status",
      "module-permissions",
    ];
    let currentTabIndex = 0;

    const prevBtn = document.getElementById("modulePrevBtn");
    const nextBtn = document.getElementById("moduleNextBtn");
    const saveBtn = document.getElementById("saveModuleBtn");

    // Update button visibility based on current tab
    function updateNavigationButtons() {
      if (!prevBtn || !nextBtn || !saveBtn) return;

      // Previous button: hide on first tab
      prevBtn.style.display = currentTabIndex === 0 ? "none" : "inline-block";

      // Next button: hide on last tab
      nextBtn.style.display =
        currentTabIndex === tabs.length - 1 ? "none" : "inline-block";

      // Save button: show only on last tab
      saveBtn.style.display =
        currentTabIndex === tabs.length - 1 ? "inline-block" : "none";
    }

    // Previous button click
    if (prevBtn) {
      prevBtn.addEventListener("click", () => {
        if (currentTabIndex > 0) {
          currentTabIndex--;
          const tabButton = document.getElementById(
            `${tabs[currentTabIndex]}-tab`,
          );
          if (tabButton) {
            tabButton.click();
          }
        }
      });
    }

    // Next button click
    if (nextBtn) {
      nextBtn.addEventListener("click", () => {
        if (currentTabIndex < tabs.length - 1) {
          currentTabIndex++;
          const tabButton = document.getElementById(
            `${tabs[currentTabIndex]}-tab`,
          );
          if (tabButton) {
            tabButton.click();
          }
        }
      });
    }

    // Listen to tab changes
    tabs.forEach((tabId, index) => {
      const tabButton = document.getElementById(`${tabId}-tab`);
      if (tabButton) {
        tabButton.addEventListener("shown.bs.tab", () => {
          currentTabIndex = index;
          updateNavigationButtons();
        });
      }
    });

    // Initialize button state
    updateNavigationButtons();
  }

  /**
   * Initialize tab navigation for submodule modal - FRESH IMPLEMENTATION
   */
  function initializeSubmoduleTabNavigation() {
    console.log("🔧 Initializing FRESH submodule tab navigation...");

    const tabs = ["sm-tab-basic", "sm-tab-status", "sm-tab-permissions"];
    let currentIndex = 0;

    const prevBtn = document.getElementById("sm-btn-prev");
    const nextBtn = document.getElementById("sm-btn-next");
    const saveBtn = document.getElementById("sm-btn-save");

    if (!prevBtn || !nextBtn || !saveBtn) {
      console.error("❌ Navigation buttons not found!");
      return;
    }

    console.log("✅ All navigation buttons found!");

    function updateButtons() {
      prevBtn.style.display = currentIndex === 0 ? "none" : "inline-block";
      nextBtn.style.display =
        currentIndex === tabs.length - 1 ? "none" : "inline-block";
      saveBtn.style.display =
        currentIndex === tabs.length - 1 ? "inline-block" : "none";
      console.log("🔄 Buttons updated for tab index:", currentIndex);
    }

    // Previous button
    prevBtn.onclick = function (e) {
      e.preventDefault();
      if (currentIndex > 0) {
        currentIndex--;
        const tabEl = document.getElementById(tabs[currentIndex]);
        if (tabEl) {
          const tab = new bootstrap.Tab(tabEl);
          tab.show();
        }
        console.log("⬅️ Previous to tab:", currentIndex);
      }
    };

    // Next button
    nextBtn.onclick = function (e) {
      e.preventDefault();
      if (currentIndex < tabs.length - 1) {
        currentIndex++;
        const tabEl = document.getElementById(tabs[currentIndex]);
        if (tabEl) {
          const tab = new bootstrap.Tab(tabEl);
          tab.show();
        }
        console.log("➡️ Next to tab:", currentIndex);
      }
    };

    // Listen to tab changes
    tabs.forEach((tabId, index) => {
      const tabEl = document.getElementById(tabId);
      if (tabEl) {
        tabEl.addEventListener("shown.bs.tab", function () {
          currentIndex = index;
          updateButtons();
          console.log("📑 Tab shown:", tabId, "index:", index);
        });
      }
    });

    // Reset on modal show
    const modal = document.getElementById("submoduleModal");
    if (modal) {
      modal.addEventListener("shown.bs.modal", function () {
        currentIndex = 0;
        updateButtons();
        console.log("📖 Modal shown - reset to first tab");
      });
    }

    updateButtons();
    console.log("✅ Fresh submodule tab navigation initialized!");
  }

  /**
   * Initialize tab navigation for sub-submodule modal
   */
  function initializeSubSubmoduleTabNavigation() {
    console.log("🔧 Initializing sub-submodule tab navigation...");

    const tabs = ["ssm-tab-basic", "ssm-tab-status", "ssm-tab-permissions"];
    let currentIndex = 0;

    const prevBtn = document.getElementById("ssm-btn-prev");
    const nextBtn = document.getElementById("ssm-btn-next");
    const saveBtn = document.getElementById("ssm-btn-save");

    if (!prevBtn || !nextBtn || !saveBtn) {
      console.error("❌ Sub-submodule navigation buttons not found!");
      return;
    }

    console.log("✅ All sub-submodule navigation buttons found!");

    function updateButtons() {
      prevBtn.style.display = currentIndex === 0 ? "none" : "inline-block";
      nextBtn.style.display =
        currentIndex === tabs.length - 1 ? "none" : "inline-block";
      saveBtn.style.display =
        currentIndex === tabs.length - 1 ? "inline-block" : "none";
      console.log(
        "🔄 Sub-submodule buttons updated for tab index:",
        currentIndex,
      );
    }

    // Previous button
    prevBtn.onclick = function (e) {
      e.preventDefault();
      if (currentIndex > 0) {
        currentIndex--;
        const tabEl = document.getElementById(tabs[currentIndex]);
        if (tabEl) {
          const tab = new bootstrap.Tab(tabEl);
          tab.show();
        }
        console.log("⬅️ Previous to tab:", currentIndex);
      }
    };

    // Next button
    nextBtn.onclick = function (e) {
      e.preventDefault();
      if (currentIndex < tabs.length - 1) {
        currentIndex++;
        const tabEl = document.getElementById(tabs[currentIndex]);
        if (tabEl) {
          const tab = new bootstrap.Tab(tabEl);
          tab.show();
        }
        console.log("➡️ Next to tab:", currentIndex);
      }
    };

    // Listen to tab changes
    tabs.forEach((tabId, index) => {
      const tabEl = document.getElementById(tabId);
      if (tabEl) {
        tabEl.addEventListener("shown.bs.tab", function () {
          currentIndex = index;
          updateButtons();
          console.log("📑 Sub-submodule tab shown:", tabId, "index:", index);
        });
      }
    });

    // Reset on modal show
    const modal = document.getElementById("subSubmoduleModal");
    if (modal) {
      modal.addEventListener("shown.bs.modal", function () {
        currentIndex = 0;
        updateButtons();
        console.log("📖 Sub-submodule modal shown - reset to first tab");
      });
    }

    updateButtons();
    console.log("✅ Sub-submodule tab navigation initialized!");
  }

  // ========================================================================
  // DATA LOADING
  // ========================================================================

  async function loadModulesTab() {
    try {
      const container = document.getElementById("moduleTreeContainer");
      if (!container) {
        console.error("❌ Module tree container not found!");
        return;
      }

      // Show loading state
      container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading modules...</p>
                </div>`;

      console.log(
        "📡 Fetching modules for territory:",
        currentTerritoryLevel || "All",
      );
      const response = await APIHandler.getModules(currentTerritoryLevel);

      // Debug: Log the full response
      console.log("📦 Full API Response:", response);
      console.log("📦 Response.success:", response.success);
      console.log("📦 Response.data:", response.data);

      if (response.success && response.data) {
        // Handle both possible response structures
        let moduleGroups;

        if (response.data.module_groups) {
          // New backend format
          moduleGroups = response.data.module_groups;
          console.log("✅ Using new format (module_groups)");
        } else if (Array.isArray(response.data)) {
          // Old backend format (array of groups)
          moduleGroups = response.data;
          console.log("✅ Using old format (array)");
        } else {
          console.error("❌ Unknown response format:", response.data);
          throw new Error("Unknown response format");
        }

        console.log("✅ Modules loaded:", moduleGroups.length, "groups");
        currentModuleGroups = moduleGroups;
        renderModuleTree(currentModuleGroups);
        initializeDragula();
      } else {
        console.error("❌ Response not successful:", response);
        throw new Error(response.message || "Failed to load modules");
      }
    } catch (error) {
      console.error("❌ Error loading modules:", error);
      console.error("❌ Error stack:", error.stack);
      const container = document.getElementById("moduleTreeContainer");
      if (container) {
        container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="ri-folder-line fs-48 text-danger mb-3 d-block"></i>
                        <p class="text-danger mb-0">Failed to load modules</p>
                        <small class="text-muted">${error.message}</small>
                    </div>`;
      }
      Toast.error("Failed to load modules: " + error.message);
    }
  }

  // ========================================================================
  // RENDERING
  // ========================================================================

  function renderModuleTree(moduleGroups) {
    const container = document.getElementById("moduleTreeContainer");
    if (!container) return;

    if (!moduleGroups || moduleGroups.length === 0) {
      container.innerHTML = `
                <div class="text-center py-5">
                    <i class="ri-folder-line fs-48 text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-0">No modules found for this territory</p>
                </div>`;
      return;
    }

    let html = '<div class="accordion" id="moduleAccordion">';

    moduleGroups.forEach((group, groupIndex) => {
      const collapseId = `moduleGroup${groupIndex}`;
      html += `
                <div class="accordion-item border mb-2">
                    <h2 class="accordion-header">
                        <button class="accordion-button ${groupIndex === 0 ? "" : "collapsed"}" 
                                type="button" 
                                data-bs-toggle="collapse" 
                                data-bs-target="#${collapseId}">
                            <i class="${group.icon || "ri-folder-line"} me-2 text-primary fs-18"></i>
                            <strong>${group.name}</strong>
                            <span class="badge bg-primary-transparent text-primary ms-2">
                                ${group.modules_count || 0} modules
                            </span>
                        </button>
                    </h2>
                    <div id="${collapseId}" 
                         class="accordion-collapse collapse ${groupIndex === 0 ? "show" : ""}" 
                         data-bs-parent="#moduleAccordion">
                        <div class="accordion-body">
                            ${renderModules(Array.isArray(group.modules) ? group.modules : Object.values(group.modules || {}), group.id)}
                        </div>
                    </div>
                </div>`;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  function renderModules(modules, groupId) {
    if (!modules || modules.length === 0) {
      return '<p class="text-muted mb-0">No modules in this group</p>';
    }

    let html = `<ul class="list-unstyled ms-3 module-list" data-group-id="${groupId}">`;

    modules.forEach((module) => {
      const hasSubmodules = module.submodules && module.submodules.length > 0;
      const collapseId = `module-${module.id}-submodules`;

      html += `
                <li class="mb-3 module-item" 
                    data-module-id="${module.id}" 
                    data-module-number="${module.number || 0}">
                    <div class="d-flex align-items-center justify-content-between p-2 border rounded bg-light">
                        <div class="d-flex align-items-center flex-grow-1">
                            <i class="ri-drag-move-2-fill drag-handle me-2 fs-18" 
                               style="cursor: move; color: #6c757d;" 
                               title="Drag to reorder"></i>
                            
                            ${
                              hasSubmodules
                                ? `
                                <i class="ri-arrow-right-s-line collapse-icon me-2 fs-18" 
                                   style="cursor: pointer; transition: transform 0.2s;" 
                                   data-bs-toggle="collapse" 
                                   data-bs-target="#${collapseId}"
                                   onclick="this.classList.toggle('rotate-90')"
                                   title="Expand/Collapse"></i>
                            `
                                : '<span class="me-2" style="width: 18px;"></span>'
                            }
                            
                            <i class="${module.icon || "ri-folder-line"} me-2 text-info"></i>
                            <strong>${module.name}</strong>
                            <span class="badge bg-info-transparent text-info ms-2">
                                ${module.submodules_count || 0} submodules
                            </span>
                            ${
                              module.is_active
                                ? '<span class="badge bg-success-transparent text-success ms-1">Active</span>'
                                : '<span class="badge bg-secondary-transparent text-secondary ms-1">Inactive</span>'
                            }
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-success-light btn-sm" 
                                    onclick="ModuleManagement.openEditModuleModal(${module.id})"
                                    title="Edit Module">
                                <i class="ri-edit-line text-success"></i>
                            </button>
                            <button class="btn btn-primary-light btn-sm" 
                                    onclick="ModuleManagement.openCreateSubmoduleModal(${module.id})"
                                    title="Add Submodule">
                                <i class="ri-add-line text-primary"></i>
                            </button>
                            <button class="btn btn-danger-light btn-sm" 
                                    onclick="ModuleManagement.deleteModule(${module.id}, '${module.name.replace(/'/g, "\\'")}')"
                                    title="Delete Module">
                                <i class="ri-delete-bin-line text-danger"></i>
                            </button>
                        </div>
                    </div>
                    ${
                      hasSubmodules
                        ? `
                        <div class="collapse show" id="${collapseId}">
                            ${renderSubmodules(module.submodules, module.id)}
                        </div>
                    `
                        : ""
                    }
                </li>`;
    });

    html += "</ul>";
    return html;
  }

  function renderSubmodules(submodules, moduleId) {
    let html = `<ul class="list-unstyled ms-4 mt-2 submodule-list" data-module-id="${moduleId}">`;

    submodules.forEach((submodule, index) => {
      html += `
                <li class="mb-2 submodule-item" 
                    data-submodule-id="${submodule.id}" 
                    data-module-id="${moduleId}">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center flex-grow-1">
                            <i class="ri-file-line me-2 text-success"></i>
                            <span class="text-dark">${submodule.title}</span>
                            <small class="text-muted ms-2">${submodule.path}</small>
                            ${
                              submodule.sub_submodules_count > 0
                                ? `<span class="badge bg-success-transparent text-success ms-2">
                                    ${submodule.sub_submodules_count} items
                                </span>`
                                : ""
                            }
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-info-light btn-sm" 
                                    onclick="ModuleManagement.openCreateSubSubmoduleModal(${submodule.id})"
                                    title="Add Sub-submodule">
                                <i class="ri-add-line text-info"></i>
                            </button>
                            <button class="btn btn-success-light btn-sm" 
                                    onclick="ModuleManagement.openEditSubmoduleModal(${submodule.id})"
                                    title="Edit Submodule">
                                <i class="ri-edit-line text-success"></i>
                            </button>
                            <button class="btn btn-danger-light btn-sm" 
                                    onclick="ModuleManagement.deleteSubmodule(${moduleId}, ${submodule.id}, '${submodule.title.replace(/'/g, "\\'")}')"
                                    title="Delete Submodule">
                                <i class="ri-delete-bin-line text-danger"></i>
                            </button>
                        </div>
                    </div>
                    ${
                      submodule.sub_submodules &&
                      submodule.sub_submodules.length > 0
                        ? renderSubSubmodules(
                            submodule.sub_submodules,
                            submodule.id,
                            moduleId,
                          )
                        : ""
                    }
                </li>`;
    });

    html += "</ul>";
    return html;
  }

  function renderSubSubmodules(subSubmodules, submoduleId, moduleId) {
    let html = '<ul class="list-unstyled ms-5 mt-2">';

    subSubmodules.forEach((subSub) => {
      html += `
                <li class="mb-2 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center flex-grow-1">
                        <i class="ri-file-text-line me-2 text-warning"></i>
                        <span class="text-dark">${subSub.title}</span>
                        <small class="text-muted ms-2">${subSub.path}</small>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-success-light btn-sm" 
                                onclick="ModuleManagement.openEditSubSubmoduleModal(${moduleId}, ${submoduleId}, ${subSub.id})"
                                title="Edit Sub-submodule">
                            <i class="ri-edit-line text-success"></i>
                        </button>
                        <button class="btn btn-danger-light btn-sm" 
                                onclick="ModuleManagement.deleteSubSubmodule(${moduleId}, ${submoduleId}, ${subSub.id}, '${subSub.title.replace(/'/g, "\\'")}')"
                                title="Delete Sub-submodule">
                            <i class="ri-delete-bin-line text-danger"></i>
                        </button>
                    </div>
                </li>`;
    });

    html += "</ul>";
    return html;
  }

  // ========================================================================
  // DRAG AND DROP
  // ========================================================================

  function initializeDragula() {
    // Destroy existing instances
    dragulaInstances.forEach((drake) => drake.destroy());
    dragulaInstances = [];

    console.log("🎯 Initializing Dragula for modules only...");

    // Initialize for modules (reorder within same group)
    const moduleContainers = Array.from(
      document.querySelectorAll(".module-list"),
    );
    if (moduleContainers.length > 0) {
      console.log("📦 Found", moduleContainers.length, "module containers");
      const moduleDrake = dragula(moduleContainers, {
        moves: (el, container, handle) =>
          handle.classList.contains("drag-handle"),
      }).on("drop", handleModuleDrop);
      dragulaInstances.push(moduleDrake);
    }

    // Submodules now use arrow buttons instead of drag-and-drop
    console.log(
      "✅ Dragula initialized with",
      dragulaInstances.length,
      "instances (modules only)",
    );
  }

  async function handleModuleDrop(el, target, source, sibling) {
    const moduleId = el.dataset.moduleId;
    const newPosition = Array.from(target.children).indexOf(el) + 1;

    console.log("📍 Module dropped:", { moduleId, newPosition });

    try {
      await APIHandler.updateModuleOrder(moduleId, newPosition);
      Toast.success("Module order updated");
      console.log("✅ Module order saved");
    } catch (error) {
      console.error("❌ Error updating module order:", error);
      Toast.error("Failed to update module order");
      loadModulesTab(); // Reload to reset
    }
  }

  // ========================================================================
  // MODAL OPERATIONS - MODULE
  // ========================================================================

  function openCreateModuleModal() {
    console.log("➕ Opening create module modal");
    document.getElementById("moduleModalTitle").textContent = "Create Module";
    document.getElementById("moduleForm").reset();
    document.getElementById("moduleId").value = "";

    // Update territory display
    const territoryDisplay = document.getElementById("currentTerritoryDisplay");
    if (territoryDisplay) {
      territoryDisplay.textContent =
        currentTerritoryLevel.charAt(0).toUpperCase() +
        currentTerritoryLevel.slice(1);
    }

    // Load module groups for current territory
    loadModuleGroups();

    // Hide selected group details initially
    const selectedGroupDetails = document.getElementById(
      "selectedGroupDetails",
    );
    if (selectedGroupDetails) {
      selectedGroupDetails.style.display = "none";
    }

    // Reset to first tab
    const firstTab = document.getElementById("module-basic-tab");
    if (firstTab) {
      firstTab.click();
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById("moduleModal"));
    modal.show();
  }

  async function openEditModuleModal(moduleId) {
    console.log("✏️ Opening edit module modal for ID:", moduleId);

    try {
      // Fetch module details
      const response = await APIHandler.getModule(moduleId);

      if (response.success && response.data) {
        const module = response.data;

        // Populate modal fields
        document.getElementById("moduleId").value = module.id;
        document.getElementById("moduleName").value = module.name;
        document.getElementById("moduleIcon").value = module.icon || "";
        document.getElementById("moduleNumber").value = module.number || "";
        document.getElementById("moduleDescription").value =
          module.description || "";
        document.getElementById("moduleActive").checked = module.is_active;

        // Load groups and then select the current one
        await loadModuleGroups();
        const groupSelect = document.getElementById("moduleGroup");
        if (groupSelect && module.module_group_id) {
          groupSelect.value = module.module_group_id;
        }

        // Set modal title
        document.getElementById("moduleModalTitle").textContent = "Edit Module";

        // Hide permission generation (only for new modules)
        const permSection = document.querySelector("#moduleModal .card.border");
        if (permSection) permSection.style.display = "none";

        // Show modal
        const modal = new bootstrap.Modal(
          document.getElementById("moduleModal"),
        );
        modal.show();
      } else {
        Toast.error("Failed to load module details");
      }
    } catch (error) {
      console.error("❌ Error loading module:", error);
      Toast.error("Failed to load module");
    }
  }

  async function loadModuleGroups() {
    try {
      const select = document.getElementById("moduleGroup");
      if (!select) {
        console.error("❌ Module group select element not found");
        return;
      }

      select.innerHTML = '<option value="">Loading...</option>';

      console.log(
        "🔄 Loading module groups for territory:",
        currentTerritoryLevel,
      );
      console.log("📦 Current cached groups:", currentModuleGroups);

      let groups = [];

      // Always fetch fresh groups from the API to ensure we have the latest data
      try {
        const response = await APIHandler.getModuleGroups(
          currentTerritoryLevel,
        );
        console.log("📡 API Response for module groups:", response);

        if (response.success && response.data) {
          // The API returns an array of groups directly in data
          groups = Array.isArray(response.data) ? response.data : [];
          console.log("✅ Fetched groups from API:", groups.length);
        } else {
          console.warn("⚠️ API response not successful, trying cached groups");
          // Fallback to cached groups
          groups = currentModuleGroups || [];
        }
      } catch (apiError) {
        console.error(
          "❌ Error fetching from API, using cached groups:",
          apiError,
        );
        groups = currentModuleGroups || [];
      }

      console.log("📊 Total groups available:", groups.length);

      // Filter groups to only show ones matching current territory
      const filteredGroups = groups.filter((group) => {
        const matches = group.territory_scope === currentTerritoryLevel;
        console.log(
          `  - ${group.name}: territory=${group.territory_scope}, matches=${matches}`,
        );
        return matches;
      });

      console.log(
        `🎯 Filtered groups for ${currentTerritoryLevel}:`,
        filteredGroups.length,
      );

      if (filteredGroups && filteredGroups.length > 0) {
        select.innerHTML = '<option value="">Select Group</option>';
        filteredGroups.forEach((group) => {
          const option = document.createElement("option");
          option.value = group.id;
          option.textContent = `${group.name}`;
          option.dataset.groupData = JSON.stringify(group); // Store group data for later use
          select.appendChild(option);
        });
        console.log(
          `✅ Loaded ${filteredGroups.length} groups for ${currentTerritoryLevel}`,
        );
      } else {
        select.innerHTML =
          '<option value="">No groups found for this territory</option>';
        console.error(
          "❌ No module groups found for territory:",
          currentTerritoryLevel,
        );
        console.error(
          "   Available groups:",
          groups.map((g) => `${g.name} (${g.territory_scope})`),
        );
      }
    } catch (error) {
      console.error("❌ Fatal error loading module groups:", error);
      const select = document.getElementById("moduleGroup");
      if (select)
        select.innerHTML = '<option value="">Error loading groups</option>';
    }
  }

  /**
   * Handle module group selection - show group details
   */
  function handleModuleGroupSelection(groupId) {
    const selectedGroupDetails = document.getElementById(
      "selectedGroupDetails",
    );

    if (!groupId || !selectedGroupDetails) {
      if (selectedGroupDetails) {
        selectedGroupDetails.style.display = "none";
      }
      return;
    }

    // Get selected option
    const select = document.getElementById("moduleGroup");
    const selectedOption = select.options[select.selectedIndex];

    if (!selectedOption || !selectedOption.dataset.groupData) {
      selectedGroupDetails.style.display = "none";
      return;
    }

    try {
      const groupData = JSON.parse(selectedOption.dataset.groupData);

      // Update group details display
      document.getElementById("selectedGroupName").textContent = groupData.name;
      document.getElementById("selectedGroupDescription").textContent =
        groupData.description || "No description";
      document.getElementById("selectedGroupTerritory").textContent =
        groupData.territory_scope;
      document.getElementById("selectedGroupModulesCount").textContent =
        groupData.modules_count || 0;

      // Update icon
      const iconElement = document.getElementById("selectedGroupIcon");
      if (iconElement) {
        iconElement.className = `${groupData.icon || "ri-folder-line"} fs-24 me-3 text-primary`;
      }

      // Show details
      selectedGroupDetails.style.display = "block";

      console.log("✅ Selected module group:", groupData.name);
    } catch (error) {
      console.error("Error parsing group data:", error);
      selectedGroupDetails.style.display = "none";
    }
  }

  async function saveModule() {
    const saveBtn = document.getElementById("saveModuleBtn");
    const originalBtnText = saveBtn.innerHTML;

    try {
      // Disable button and show loading state
      saveBtn.disabled = true;
      saveBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';

      const moduleId = document.getElementById("moduleId").value;
      const data = {
        name: document.getElementById("moduleName").value,
        icon: document.getElementById("moduleIcon").value,
        module_group_id: document.getElementById("moduleGroup").value,
        number: document.getElementById("moduleNumber").value,
        description: document.getElementById("moduleDescription").value,
        is_active: document.getElementById("moduleActive").checked,
      };

      console.log("📋 Module save data:", data);
      console.log(
        "🔍 Validation check - Group ID:",
        data.module_group_id,
        "Type:",
        typeof data.module_group_id,
      );

      // Validate required fields (name, icon, number are required)
      // For group: only required for new modules (not when editing)
      if (!data.name || !data.icon || !data.number) {
        Toast.error(
          "Please fill in all required fields (Name, Icon, Order Number)",
        );
        // Go back to first tab
        document.getElementById("module-basic-tab").click();
        return;
      }

      // Check group only for new modules
      if (!moduleId && !data.module_group_id) {
        Toast.error("Please select a module group");
        // Go back to Module Group tab
        document.getElementById("module-group-tab").click();
        return;
      }

      // Add permissions if auto-generate is checked
      if (document.getElementById("autoGeneratePermissions").checked) {
        const selectedPermissions = [];
        document
          .querySelectorAll(".permission-checkbox:checked")
          .forEach((checkbox) => {
            selectedPermissions.push({
              action: checkbox.value,
              territory_scope: currentTerritoryLevel,
            });
          });
        data.permissions = selectedPermissions;
        console.log(
          "🔑 Auto-generating",
          selectedPermissions.length,
          "permissions",
        );
      }

      console.log("💾 Saving module:", data);

      const response = moduleId
        ? await APIHandler.updateModule(moduleId, data)
        : await APIHandler.createModule(data);

      console.log("📦 API Response:", response);

      if (response.success) {
        Toast.success(
          moduleId
            ? "Module updated successfully"
            : "Module created successfully",
        );
        console.log("✅ Module saved successfully!", response.data);

        // Close modal
        bootstrap.Modal.getInstance(
          document.getElementById("moduleModal"),
        ).hide();

        // Reload modules list to show the new module
        loadModulesTab();
      } else {
        // Handle validation errors
        if (response.errors) {
          // Show first validation error
          const errorKeys = Object.keys(response.errors);
          if (errorKeys.length > 0) {
            const firstKey = errorKeys[0];
            const firstError = response.errors[firstKey][0];
            Toast.error(firstError);
            console.log(`❌ Validation error (${firstKey}):`, firstError);
          }
        } else {
          Toast.error(response.message || "Failed to save module");
        }
        throw new Error(response.message || "Validation failed");
      }
    } catch (error) {
      console.error("❌ Error saving module:", error);
      // Only show toast if we haven't already shown a validation error
      if (!error.message.includes("Validation failed")) {
        Toast.error(error.message || "Failed to save module");
      }
    } finally {
      // Re-enable button and restore text
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalBtnText;
    }
  }

  async function deleteModule(moduleId, moduleName) {
    if (
      !confirm(
        `Are you sure you want to delete "${moduleName}"?\n\nThis will also delete all submodules and sub-submodules.`,
      )
    ) {
      return;
    }

    try {
      console.log("🗑️ Deleting module:", moduleId);
      await APIHandler.deleteModule(moduleId);
      Toast.success("Module deleted successfully");
      loadModulesTab();
    } catch (error) {
      console.error("❌ Error deleting module:", error);
      Toast.error("Failed to delete module");
    }
  }

  // ========================================================================
  // MODAL OPERATIONS - SUBMODULE
  // ========================================================================

  function openCreateSubmoduleModal(moduleId) {
    console.log("➕ Opening create submodule modal for module:", moduleId);
    document.getElementById("submoduleModalTitle").textContent =
      "Create Submodule";
    document.getElementById("submoduleForm").reset();
    document.getElementById("submoduleId").value = "";
    document.getElementById("submoduleModuleId").value = moduleId;

    // Find the parent module to get its name for path generation
    let parentModule = null;
    if (currentModuleGroups && currentModuleGroups.length > 0) {
      for (const group of currentModuleGroups) {
        if (group.modules && Array.isArray(group.modules)) {
          parentModule = group.modules.find((m) => m.id == moduleId);
          if (parentModule) break;
        }
      }
    }

    // Store parent module info for path generation
    if (parentModule) {
      const moduleSlug = parentModule.name
        .toLowerCase()
        .replace(/\s+/g, "-")
        .replace(/[^a-z0-9-]/g, "")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "");

      // Store in a data attribute for access in the input event listener
      // Include territory prefix in the path (e.g., diocese/budget-management)
      const territoryPrefix = currentTerritoryLevel || "diocese";
      const fullModulePrefix = `${territoryPrefix}/${moduleSlug}`;
      document.getElementById("submodulePath").dataset.modulePrefix =
        fullModulePrefix;
      document.getElementById("submodulePath").placeholder =
        `/${territoryPrefix}/${moduleSlug}/your-page-name`;
    }

    // Reset tab index to first tab
    currentSubmoduleTabIndex = 0;

    // Reset to first tab
    const firstTab = document.getElementById("submodule-basic-tab");
    if (firstTab) {
      firstTab.click();
    }

    // Initialize/update tab navigation
    setTimeout(() => {
      initializeSubmoduleTabNavigation();
    }, 100);

    const modal = new bootstrap.Modal(
      document.getElementById("submoduleModal"),
    );
    modal.show();
  }

  async function openEditSubmoduleModal(submoduleId) {
    console.log("✏️ Opening edit submodule modal for ID:", submoduleId);

    try {
      // Fetch submodule details
      const response = await APIHandler.getSubmodule(submoduleId);

      if (response.success && response.data) {
        const submodule = response.data;

        console.log("📦 Submodule data:", submodule);

        // Populate modal fields
        document.getElementById("submoduleId").value = submodule.id;
        document.getElementById("submoduleModuleId").value =
          submodule.module_id;
        document.getElementById("submoduleTitle").value = submodule.title || "";
        document.getElementById("submodulePath").value = submodule.path || "";
        document.getElementById("submoduleDescription").value =
          submodule.description || "";
        document.getElementById("submoduleActive").checked =
          submodule.is_active == 1 || submodule.is_active === true;

        // Set modal title with parent module name
        let modalTitle = "Edit Submodule";
        if (submodule.module_name) {
          modalTitle += ` - ${submodule.module_name}`;
        }
        document.getElementById("submoduleModalTitle").textContent = modalTitle;

        // Change modal header color to success for edit
        const modalHeader = document.querySelector(
          "#submoduleModal .modal-header",
        );
        if (modalHeader) {
          modalHeader.classList.remove("bg-primary");
          modalHeader.classList.add("bg-success");
        }

        // Update the info alert for editing
        const infoAlert = document.querySelector("#submoduleModal .alert-info");
        if (infoAlert) {
          infoAlert.innerHTML = `
                        <div class="d-flex align-items-start">
                            <i class="ri-information-line fs-20 me-3 text-warning"></i>
                            <div class="flex-fill">
                                <h6 class="alert-heading mb-2 fw-semibold">Edit Submodule</h6>
                                <ul class="mb-0 ps-3 fs-13 text-dark">
                                    <li class="mb-1">Modifying this submodule will affect its navigation and access</li>
                                    <li class="mb-1">Path changes may break existing links</li>
                                    <li class="mb-0">Permissions are managed separately</li>
                                </ul>
                            </div>
                        </div>
                    `;
          infoAlert.classList.remove("alert-info", "bg-info-transparent");
          infoAlert.classList.add("alert-warning", "bg-warning-transparent");
        }

        // Hide permission generation tab (only for new submodules)
        const permTab = document.getElementById("sm-tab-permissions");
        if (permTab) {
          permTab.style.display = "none";
        }

        // Make sure we're on the basic info tab
        document.getElementById("sm-tab-basic").click();

        // Disable auto-path generation when editing
        const titleInput = document.getElementById("submoduleTitle");
        const pathInput = document.getElementById("submodulePath");

        if (titleInput && pathInput) {
          // Remove readonly from path so user can edit it
          pathInput.removeAttribute("readonly");
          pathInput.classList.remove("bg-light");

          // Clone the title input to remove all event listeners
          const newTitleInput = titleInput.cloneNode(true);
          titleInput.parentNode.replaceChild(newTitleInput, titleInput);

          console.log("🔓 Path auto-generation disabled for editing");
        }

        // Show modal
        const modal = new bootstrap.Modal(
          document.getElementById("submoduleModal"),
        );
        modal.show();

        console.log("✅ Submodule edit modal opened successfully");
      } else {
        Toast.error("Failed to load submodule details");
      }
    } catch (error) {
      console.error("❌ Error loading submodule:", error);
      Toast.error("Failed to load submodule");
    }
  }

  async function saveSubmodule() {
    const saveBtn = document.getElementById("sm-btn-save");
    const originalBtnContent = saveBtn.innerHTML;

    try {
      // Disable button and show loading state
      saveBtn.disabled = true;
      saveBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';

      const submoduleId = document.getElementById("submoduleId").value;
      const moduleId = document.getElementById("submoduleModuleId").value;
      const data = {
        title: document.getElementById("submoduleTitle").value,
        path: document.getElementById("submodulePath").value,
        description: document.getElementById("submoduleDescription").value,
        is_active: document.getElementById("submoduleActive").checked,
      };

      // Validate required fields
      if (!data.title || !data.path) {
        Toast.error("Please fill in all required fields");
        return;
      }

      // Check for duplicate paths before saving
      let isDuplicate = false;
      if (currentModuleGroups && currentModuleGroups.length > 0) {
        for (const group of currentModuleGroups) {
          if (group.modules && Array.isArray(group.modules)) {
            for (const module of group.modules) {
              if (module.submodules && Array.isArray(module.submodules)) {
                for (const submodule of module.submodules) {
                  // Skip the current submodule when editing
                  if (submoduleId && submodule.id == submoduleId) continue;

                  if (submodule.path === data.path) {
                    isDuplicate = true;
                    break;
                  }
                }
              }
              if (isDuplicate) break;
            }
          }
          if (isDuplicate) break;
        }
      }

      if (isDuplicate) {
        Toast.error("This path already exists. Please use a different title.");
        // Go back to first tab to fix
        const firstTab = document.getElementById("sm-tab-basic");
        if (firstTab) {
          const tab = new bootstrap.Tab(firstTab);
          tab.show();
        }
        return;
      }

      // Add permissions if auto-generate is checked
      if (document.getElementById("autoGenerateSubmodulePermissions").checked) {
        const selectedPermissions = [];
        document
          .querySelectorAll(".submodule-permission-checkbox:checked")
          .forEach((checkbox) => {
            selectedPermissions.push({
              action: checkbox.value,
              territory_scope: currentTerritoryLevel,
            });
          });
        data.permissions = selectedPermissions;
        console.log(
          "🔑 Auto-generating",
          selectedPermissions.length,
          "permissions for submodule",
        );
      }

      console.log("💾 Saving submodule:", data);

      const response = submoduleId
        ? await APIHandler.updateSubmodule(moduleId, submoduleId, data)
        : await APIHandler.createSubmodule(moduleId, data);

      if (response.success) {
        Toast.success(
          submoduleId
            ? "Submodule updated successfully"
            : "Submodule created successfully",
        );
        bootstrap.Modal.getInstance(
          document.getElementById("submoduleModal"),
        ).hide();
        // NOTE: Commented out to allow adding multiple items without reload
        // loadModulesTab();
      } else {
        throw new Error(response.message || "Failed to save submodule");
      }
    } catch (error) {
      console.error("❌ Error saving submodule:", error);
      Toast.error(error.message || "Failed to save submodule");
    } finally {
      // Re-enable button and restore original content
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalBtnContent;
    }
  }

  async function deleteSubmodule(moduleId, submoduleId, submoduleTitle) {
    if (
      !confirm(
        `Are you sure you want to delete "${submoduleTitle}"?\n\nThis will also delete all sub-submodules.`,
      )
    ) {
      return;
    }

    try {
      console.log("🗑️ Deleting submodule:", submoduleId);
      await APIHandler.deleteSubmodule(moduleId, submoduleId);
      Toast.success("Submodule deleted successfully");
      loadModulesTab();
    } catch (error) {
      console.error("❌ Error deleting submodule:", error);
      Toast.error("Failed to delete submodule");
    }
  }

  // ========================================================================
  // SUB-SUBMODULE MANAGEMENT
  // ========================================================================

  function openCreateSubSubmoduleModal(submoduleId) {
    console.log(
      "➕ Opening create sub-submodule modal for submodule:",
      submoduleId,
    );

    // Reset form
    document.getElementById("subSubmoduleForm").reset();
    document.getElementById("subSubmoduleId").value = "";
    document.getElementById("subSubmoduleSubmoduleId").value = submoduleId;
    document.getElementById("subSubmoduleModalTitle").textContent =
      "Create Sub-submodule";
    document.getElementById("subSubmoduleActive").checked = true;

    // Find the parent submodule to get its path
    let parentSubmodule = null;
    let parentModule = null;

    if (currentModuleGroups && currentModuleGroups.length > 0) {
      for (const group of currentModuleGroups) {
        if (group.modules && Array.isArray(group.modules)) {
          for (const module of group.modules) {
            if (module.submodules && Array.isArray(module.submodules)) {
              const submodule = module.submodules.find(
                (s) => s.id == submoduleId,
              );
              if (submodule) {
                parentSubmodule = submodule;
                parentModule = module;
                break;
              }
            }
          }
          if (parentSubmodule) break;
        }
        if (parentSubmodule) break;
      }
    }

    // Set the submodule path prefix for auto-generation
    const pathInput = document.getElementById("subSubmodulePath");
    if (parentSubmodule && parentSubmodule.path) {
      pathInput.dataset.submodulePrefix = parentSubmodule.path;
      pathInput.placeholder = `${parentSubmodule.path}/your-page-name`;
      console.log(
        "✅ Parent submodule found:",
        parentSubmodule.title,
        "Path:",
        parentSubmodule.path,
      );
    } else {
      pathInput.dataset.submodulePrefix = "";
      pathInput.placeholder = "/auto-generated-from-title";
      console.warn("⚠️ Parent submodule not found");
    }

    // Store module ID for API call
    if (parentModule) {
      pathInput.dataset.moduleId = parentModule.id;
    }

    // Show modal
    const modal = new bootstrap.Modal(
      document.getElementById("subSubmoduleModal"),
    );
    modal.show();
  }

  async function saveSubSubmodule() {
    const saveBtn = document.getElementById("ssm-btn-save");
    const originalBtnContent = saveBtn.innerHTML;

    try {
      // Disable button and show loading state
      saveBtn.disabled = true;
      saveBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';

      const subSubmoduleId = document.getElementById("subSubmoduleId").value;
      const submoduleId = document.getElementById(
        "subSubmoduleSubmoduleId",
      ).value;
      const pathInput = document.getElementById("subSubmodulePath");
      const moduleId = pathInput.dataset.moduleId;

      const data = {
        title: document.getElementById("subSubmoduleTitle").value,
        path: document.getElementById("subSubmodulePath").value,
        description: document.getElementById("subSubmoduleDescription").value,
        is_active: document.getElementById("subSubmoduleActive").checked,
      };

      // Validate required fields
      if (!data.title || !data.path) {
        Toast.error("Please fill in all required fields");
        return;
      }

      if (!moduleId || !submoduleId) {
        Toast.error("Missing parent module or submodule information");
        return;
      }

      // Add permissions if auto-generate is checked
      if (
        document.getElementById("autoGenerateSubSubmodulePermissions").checked
      ) {
        const selectedPermissions = [];
        document
          .querySelectorAll(".sub-submodule-permission-checkbox:checked")
          .forEach((checkbox) => {
            selectedPermissions.push({
              action: checkbox.value,
              territory_scope: currentTerritoryLevel,
            });
          });
        data.permissions = selectedPermissions;
        console.log(
          "🔑 Auto-generating",
          selectedPermissions.length,
          "permissions for sub-submodule",
        );
      }

      console.log("💾 Saving sub-submodule:", data);

      const response = subSubmoduleId
        ? await APIHandler.updateSubSubmodule(
            moduleId,
            submoduleId,
            subSubmoduleId,
            data,
          )
        : await APIHandler.createSubSubmodule(moduleId, submoduleId, data);

      if (response.success) {
        Toast.success(
          subSubmoduleId
            ? "Sub-submodule updated successfully"
            : "Sub-submodule created successfully",
        );
        bootstrap.Modal.getInstance(
          document.getElementById("subSubmoduleModal"),
        ).hide();
        // NOTE: Commented out to allow adding multiple items without reload
        // loadModulesTab();
      } else {
        throw new Error(response.message || "Failed to save sub-submodule");
      }
    } catch (error) {
      console.error("❌ Error saving sub-submodule:", error);
      Toast.error(error.message || "Failed to save sub-submodule");
    } finally {
      // Re-enable button and restore original content
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalBtnContent;
    }
  }

  async function openEditSubSubmoduleModal(
    moduleId,
    submoduleId,
    subSubmoduleId,
  ) {
    try {
      console.log("✏️ Opening edit sub-submodule modal:", {
        moduleId,
        submoduleId,
        subSubmoduleId,
      });

      // Fetch sub-submodule data
      const response = await APIHandler.getSubSubmodule(
        moduleId,
        submoduleId,
        subSubmoduleId,
      );

      console.log("📡 API Response:", response);

      if (!response.success || !response.data) {
        const errorMsg =
          response.message || "Failed to load sub-submodule data";
        console.error("❌ API Error:", errorMsg);
        throw new Error(errorMsg);
      }

      const subSubmodule = response.data;
      console.log("📦 Sub-submodule data:", subSubmodule);

      // Populate form
      document.getElementById("subSubmoduleId").value = subSubmodule.id || "";
      document.getElementById("subSubmoduleSubmoduleId").value = submoduleId;
      document.getElementById("subSubmoduleTitle").value =
        subSubmodule.title || "";
      document.getElementById("subSubmodulePath").value =
        subSubmodule.path || "";
      document.getElementById("subSubmoduleDescription").value =
        subSubmodule.description || "";
      document.getElementById("subSubmoduleActive").checked =
        subSubmodule.is_active == 1 || subSubmodule.is_active === true;

      // CRITICAL FIX: Store moduleId in path input dataset for save operation
      const pathInput = document.getElementById("subSubmodulePath");
      if (pathInput) {
        pathInput.dataset.moduleId = moduleId;
        console.log("✅ Stored moduleId in dataset:", moduleId);
      }

      // Set modal title with parent info
      let modalTitle = "Edit Sub-submodule";
      if (subSubmodule.submodule_name) {
        modalTitle += ` - ${subSubmodule.submodule_name}`;
      } else if (subSubmodule.module_name) {
        modalTitle += ` - ${subSubmodule.module_name}`;
      }
      document.getElementById("subSubmoduleModalTitle").textContent =
        modalTitle;

      // Change modal header color to success for edit
      const modalHeader = document.querySelector(
        "#subSubmoduleModal .modal-header",
      );
      if (modalHeader) {
        modalHeader.classList.remove("bg-info");
        modalHeader.classList.add("bg-success");
      }

      // Update the info alert for editing
      const infoAlert = document.querySelector(
        "#subSubmoduleModal .alert-info",
      );
      if (infoAlert) {
        infoAlert.innerHTML = `
                    <div class="d-flex align-items-start">
                        <i class="ri-information-line fs-20 me-3 text-warning"></i>
                        <div class="flex-fill">
                            <h6 class="alert-heading mb-2 fw-semibold">Edit Sub-submodule</h6>
                            <ul class="mb-0 ps-3 fs-13 text-dark">
                                <li class="mb-1">Modifying this sub-submodule will affect its navigation and access</li>
                                <li class="mb-1">Path changes may break existing links</li>
                                <li class="mb-0">Permissions are managed separately</li>
                            </ul>
                        </div>
                    </div>
                `;
        infoAlert.classList.remove("alert-info", "bg-info-transparent");
        infoAlert.classList.add("alert-warning", "bg-warning-transparent");
      }

      // Hide permission generation tab and content (only for editing)
      const permTab = document.getElementById("ssm-tab-permissions");
      const permContent = document.getElementById("ssm-content-permissions");
      if (permTab) {
        permTab.parentElement.style.display = "none"; // Hide the entire li element
      }
      if (permContent) {
        permContent.style.display = "none";
      }

      // Make sure we're on the basic info tab
      const basicTab = document.getElementById("ssm-tab-basic");
      if (basicTab) {
        basicTab.click();
      }

      // Disable auto-path generation when editing
      const titleInput = document.getElementById("subSubmoduleTitle");
      // pathInput already declared above, just reuse it

      if (titleInput && pathInput) {
        // Remove readonly from path so user can edit it
        pathInput.removeAttribute("readonly");
        pathInput.classList.remove("bg-light");

        // Clone the title input to remove all event listeners
        const newTitleInput = titleInput.cloneNode(true);
        titleInput.parentNode.replaceChild(newTitleInput, titleInput);

        console.log("🔓 Path auto-generation disabled for editing");
      }

      // Show modal
      const modal = new bootstrap.Modal(
        document.getElementById("subSubmoduleModal"),
      );
      modal.show();

      console.log("✅ Sub-submodule edit modal opened successfully");
    } catch (error) {
      console.error("❌ Error loading sub-submodule:", error);
      Toast.error(
        "Failed to load sub-submodule: " + (error.message || "Unknown error"),
      );
    }
  }

  async function deleteSubSubmodule(
    moduleId,
    submoduleId,
    subSubmoduleId,
    title,
  ) {
    if (!confirm(`Are you sure you want to delete "${title}"?`)) {
      return;
    }

    try {
      console.log("🗑️ Deleting sub-submodule:", subSubmoduleId);
      await APIHandler.deleteSubSubmodule(
        moduleId,
        submoduleId,
        subSubmoduleId,
      );
      Toast.success("Sub-submodule deleted successfully");
      loadModulesTab();
    } catch (error) {
      console.error("❌ Error deleting sub-submodule:", error);
      Toast.error("Failed to delete sub-submodule");
    }
  }

  console.log("✅ Module Management module loaded");
})();
