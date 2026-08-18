/**
 * ============================================================================
 * PAGE - GATHERING TYPES CONFIG (church/attendance/gathering-types.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * Each church manages its own list of specific gatherings (e.g. "Kesha",
 * "Tuesday Fellowship") under the global Ministry Gathering/Special Event
 * categories - not shared with other churches. Sunday Service is excluded
 * from the category dropdown since it doesn't use a gathering type
 * (services.php's calendar entry skips this step entirely).
 *
 * Dependencies: DemographicsAPIHandler, DemographicsUI, Toast
 * ============================================================================
 */

const AttendanceGatheringTypes = (function () {
  "use strict";

  let categories = [];
  let allTypes = [];
  let editingId = null;

  async function init() {
    Object.assign(USER_TERRITORY, DemographicsUI.resolveUserTerritory(USER_TERRITORY));

    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    if (CAN_WRITE_GATHERING_TYPES) {
      document.getElementById("addGatheringTypeBtn").addEventListener("click", openCreateModal);
      document.getElementById("saveGatheringTypeBtn").addEventListener("click", saveGatheringType);
      document.getElementById("gatheringTypeIcon").addEventListener("input", updateIconPreview);
    }

    await loadCategories();
    await loadList();
  }

  async function loadCategories() {
    const result = await DemographicsAPIHandler.getGatheringCategories();
    // Sunday Service is excluded - it doesn't use a configured gathering
    // type, so offering it here would be a dead end.
    categories = result.success ? (result.data || []).filter((c) => !c.is_weekly) : [];

    const select = document.getElementById("gatheringTypeCategory");
    select.innerHTML = categories.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join("");
  }

  async function loadList() {
    const tbody = document.getElementById("gatheringTypesTableBody");
    tbody.innerHTML = DemographicsUI.renderTableLoading(4, "Loading gathering types...");

    const result = await DemographicsAPIHandler.getGatheringTypes(USER_TERRITORY.id, { include_inactive: "true" });

    if (!result.success) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(4, "Could not load gathering types");
      return;
    }

    allTypes = result.data || [];
    renderRows();
    renderStats(allTypes);

    DemographicsUI.renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search gathering types...",
      filters: [
        {
          id: "categoryFilter",
          label: "All Categories",
          options: categories.map((c) => ({ value: c.name, label: c.name })),
        },
        {
          id: "statusFilter",
          label: "All Statuses",
          options: [
            { value: "Active", label: "Active" },
            { value: "Inactive", label: "Inactive" },
          ],
        },
      ],
    });

    const table = DemographicsUI.initListDataTable("gatheringTypesTable", {
      searchPlaceholder: "Search gathering types...",
      order: [[0, "asc"]],
      nonSortableColumns: [3],
      hideDefaultSearch: true,
    });

    DemographicsUI.wireFilterToolbar("filterToolbar", table, [
      { id: "categoryFilter", columnIndex: 1, exact: true },
      { id: "statusFilter", columnIndex: 2, exact: true },
    ]);
  }

  function renderStats(types) {
    const active = types.filter((t) => t.is_active).length;
    const inactive = types.length - active;

    const countsByCategory = types.reduce((acc, t) => {
      const name = t.category?.name || "Uncategorized";
      acc[name] = (acc[name] || 0) + 1;
      return acc;
    }, {});
    const mostUsed = Object.entries(countsByCategory).sort((a, b) => b[1] - a[1])[0];

    DemographicsUI.renderStatCardsRow("statCardsRow", [
      { icon: "ri-list-check-2", label: "Total Types", value: types.length, color: "primary" },
      { icon: "ri-checkbox-circle-line", label: "Active", value: active, color: "success" },
      { icon: "ri-close-circle-line", label: "Inactive", value: inactive, color: "secondary" },
      { icon: "ri-bar-chart-line", label: "Most-Used Category", value: mostUsed ? mostUsed[0] : "-", color: "warning" },
    ]);
  }

  function renderRows() {
    const tbody = document.getElementById("gatheringTypesTableBody");

    if (allTypes.length === 0) {
      tbody.innerHTML = DemographicsUI.renderTableEmpty(
        4,
        "No gathering types configured yet - add your first one",
        "ri-list-check-2",
      );
      return;
    }

    tbody.innerHTML = allTypes
      .map((t) => {
        const icon = t.icon || t.category?.icon || "ri-calendar-event-line";
        const statusBadge = t.is_active
          ? '<span class="badge bg-success"><i class="ri-checkbox-circle-fill me-1"></i>Active</span>'
          : '<span class="badge bg-secondary"><i class="ri-close-circle-fill me-1"></i>Inactive</span>';

        return `
          <tr>
            <td class="fw-semibold"><i class="${icon} me-2 text-primary"></i>${escapeHtml(t.name)}</td>
            <td><span class="badge bg-primary-transparent">${escapeHtml(t.category?.name || "-")}</span></td>
            <td class="text-center">${statusBadge}</td>
            <td class="text-end">
              <div class="btn-group">
                <button type="button" class="btn btn-sm btn-primary-light dropdown-toggle" data-bs-toggle="dropdown">
                  <i class="ri-settings-3-line me-1"></i>Actions
                </button>
                <ul class="dropdown-menu">
                  ${CAN_WRITE_GATHERING_TYPES ? `
                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="AttendanceGatheringTypes.openEditModal(${t.id})">
                    <i class="ri-edit-line me-2 text-info"></i>Edit
                  </a></li>
                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="AttendanceGatheringTypes.toggleActive(${t.id})">
                    <i class="ri-toggle-line me-2 text-warning"></i>${t.is_active ? "Deactivate" : "Activate"}
                  </a></li>
                  <li><hr class="dropdown-divider"></li>
                  ` : ""}
                  <li><a class="dropdown-item" href="javascript:void(0);" onclick="AttendanceGatheringTypes.openAuditModal(${t.id})">
                    <i class="ri-history-line me-2 text-secondary"></i>View Activity Log
                  </a></li>
                </ul>
              </div>
            </td>
          </tr>`;
      })
      .join("");
  }

  function openCreateModal() {
    editingId = null;
    document.getElementById("gatheringTypeModalTitle").textContent = "Add Gathering Type";
    document.getElementById("gatheringTypeId").value = "";
    document.getElementById("gatheringTypeName").value = "";
    document.getElementById("gatheringTypeCategory").value = categories[0]?.id || "";
    document.getElementById("gatheringTypeIcon").value = "";
    document.getElementById("gatheringTypeActive").checked = true;
    updateIconPreview();

    new bootstrap.Modal(document.getElementById("gatheringTypeModal")).show();
  }

  function openEditModal(id) {
    const type = allTypes.find((t) => t.id === id);
    if (!type) {
      Toast.error("Gathering type not found");
      return;
    }

    editingId = id;
    document.getElementById("gatheringTypeModalTitle").textContent = "Edit Gathering Type";
    document.getElementById("gatheringTypeId").value = type.id;
    document.getElementById("gatheringTypeName").value = type.name || "";
    document.getElementById("gatheringTypeCategory").value = type.gathering_category_id;
    document.getElementById("gatheringTypeIcon").value = type.icon || "";
    document.getElementById("gatheringTypeActive").checked = !!type.is_active;
    updateIconPreview();

    new bootstrap.Modal(document.getElementById("gatheringTypeModal")).show();
  }

  /** Live preview so a user picking a Remix Icon class name can confirm
   * it's the icon they meant before saving, instead of finding out on
   * the list page afterward. */
  function updateIconPreview() {
    const value = document.getElementById("gatheringTypeIcon").value.trim() || "ri-calendar-event-line";
    document.getElementById("gatheringTypeIconPreview").innerHTML = `<i class="${value}"></i>`;
  }

  async function saveGatheringType() {
    const name = document.getElementById("gatheringTypeName").value.trim();
    const gatheringCategoryId = document.getElementById("gatheringTypeCategory").value;

    if (!name) {
      Toast.warning("Please enter a name");
      return;
    }
    if (!gatheringCategoryId) {
      Toast.warning("Please select a category");
      return;
    }

    const payload = {
      name,
      gathering_category_id: parseInt(gatheringCategoryId, 10),
      icon: document.getElementById("gatheringTypeIcon").value.trim() || null,
      is_active: document.getElementById("gatheringTypeActive").checked,
    };

    if (!editingId) {
      payload.territory_id = USER_TERRITORY.id;
    }

    const btn = document.getElementById("saveGatheringTypeBtn");
    DemographicsUI.setButtonLoading(btn, "Saving...");

    const result = editingId
      ? await DemographicsAPIHandler.updateGatheringType(editingId, payload)
      : await DemographicsAPIHandler.createGatheringType(payload);

    DemographicsUI.restoreButton(btn);

    if (!result.success) {
      Toast.error(result.message || "Failed to save gathering type");
      return;
    }

    Toast.success(editingId ? "Gathering type updated" : "Gathering type created");
    bootstrap.Modal.getInstance(document.getElementById("gatheringTypeModal")).hide();
    loadList();
  }

  async function toggleActive(id) {
    const type = allTypes.find((t) => t.id === id);
    if (!type) return;

    const result = await DemographicsAPIHandler.updateGatheringType(id, { is_active: !type.is_active });

    if (!result.success) {
      Toast.error(result.message || "Failed to update gathering type");
      return;
    }

    Toast.success(type.is_active ? "Gathering type deactivated" : "Gathering type activated");
    loadList();
  }

  async function openAuditModal(id) {
    const body = document.getElementById("gatheringTypeAuditBody");
    body.innerHTML = DemographicsUI.renderTableLoading
      ? `<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>`
      : "";

    new bootstrap.Modal(document.getElementById("gatheringTypeAuditModal")).show();

    const result = await DemographicsAPIHandler.getGatheringTypeAudits(id);

    if (!result.success || !result.data || result.data.length === 0) {
      body.innerHTML = `<p class="text-body fw-semibold text-center py-4 mb-0">No activity recorded yet</p>`;
      return;
    }

    body.innerHTML = `<ul class="list-unstyled mb-0">${result.data
      .map((audit) => {
        const eventColors = { created: "success", updated: "info", deleted: "danger" };
        const color = eventColors[audit.event] || "secondary";
        return `
          <li class="mb-3 pb-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="badge bg-${color}-transparent text-${color} text-capitalize">${audit.event}</span>
              <span class="fs-12 text-body">${audit.created_at_human}</span>
            </div>
            <p class="mb-0 fs-13 text-body fw-semibold">
              ${audit.user ? `${audit.user.name}` : "System"}
            </p>
          </li>`;
      })
      .join("")}</ul>`;
  }

  function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return "";
    return unsafe
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  return { init, openEditModal, toggleActive, openAuditModal };
})();

window.AttendanceGatheringTypes = AttendanceGatheringTypes;
