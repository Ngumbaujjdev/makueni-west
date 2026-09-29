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
      noun: "types",
    });

    DemographicsUI.wireFilterToolbar("filterToolbar", table, [
      { id: "categoryFilter", columnIndex: 1, exact: true },
      { id: "statusFilter", columnIndex: 2, exact: true },
    ], { noun: "types" });
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

    const UI = DemographicsUI;
    const addedThis = UI.rowsInMonth(types, 0, "created_at").length;
    const activeShare = types.length ? Math.round((active / types.length) * 100) : 0;

    UI.renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-list-check-2",
        label: "Total Types",
        value: types.length,
        color: "primary",
        trend: addedThis ? `${addedThis} added this month` : "None added this month",
      },
      { icon: "ri-checkbox-circle-line", label: "Active", value: active, color: "success", trend: `${activeShare}% of all types` },
      {
        icon: "ri-close-circle-line",
        label: "Inactive",
        value: inactive,
        color: inactive ? "danger" : "purple",
        trend: inactive ? "Hidden from entry forms" : "Everything is in use",
      },
      {
        icon: "ri-bar-chart-line",
        label: "Most-Used Category",
        value: mostUsed ? mostUsed[0] : "-",
        color: "secondary",
        trend: mostUsed ? `${mostUsed[1]} type${mostUsed[1] === 1 ? "" : "s"}` : "",
      },
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

    const UI = DemographicsUI;
    tbody.innerHTML = allTypes
      .map((t) => {
        const icon = t.icon || t.category?.icon || "ri-calendar-event-line";
        const category = t.category?.name || "-";
        const status = t.is_active ? "Active" : "Inactive";
        const statusPill = t.is_active
          ? UI.pill("Active", "success", "ri-checkbox-circle-fill")
          : UI.pill("Inactive", "danger", "ri-close-circle-fill");

        const editBtn = CAN_WRITE_GATHERING_TYPES
          ? `<button type="button" class="btn btn-sm btn-primary-light" onclick="AttendanceGatheringTypes.openEditModal(${t.id})" title="Edit" aria-label="Edit">
               <i class="ri-edit-line"></i>
             </button>`
          : "";

        return `
          <tr data-row-id="${t.id}">
            <td data-search="${escapeHtml(t.name)}" data-order="${escapeHtml(t.name)}">
              <div class="d-flex align-items-center gap-2">
                ${UI.avatarTile(icon, t.is_active ? UI.colorFor(t.name) : "secondary")}
                <span class="fw-semibold">${escapeHtml(t.name)}</span>
              </div>
            </td>
            <td data-search="${escapeHtml(category)}">${UI.pill(escapeHtml(category), UI.colorFor(category))}</td>
            <td data-search="${status}">${statusPill}</td>
            <td class="text-end">
              <div class="d-inline-flex align-items-center gap-1">
                ${editBtn}
                <div class="dropdown">
                  <button type="button" class="btn btn-sm btn-light list-kebab" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="More actions">
                    <i class="ri-more-2-fill"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    ${CAN_WRITE_GATHERING_TYPES ? `
                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="AttendanceGatheringTypes.toggleActive(${t.id})">
                      <i class="${t.is_active ? "ri-toggle-line text-danger" : "ri-toggle-fill text-success"} me-2"></i>${t.is_active ? "Deactivate" : "Activate"}
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    ` : ""}
                    <li><a class="dropdown-item" href="javascript:void(0);" onclick="AttendanceGatheringTypes.openAuditModal(${t.id})">
                      <i class="ri-history-line me-2 text-primary"></i>View activity log
                    </a></li>
                  </ul>
                </div>
              </div>
            </td>
          </tr>`;
      })
      .join("");
  }

  function openCreateModal() {
    editingId = null;
    document.getElementById("gatheringTypeModalTitle").textContent = "Add gathering type";
    document.querySelectorAll("#gatheringTypeModal .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
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
    document.getElementById("gatheringTypeModalTitle").textContent = "Edit gathering type";
    document.querySelectorAll("#gatheringTypeModal .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
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

    const nameInput = document.getElementById("gatheringTypeName");
    const categoryInput = document.getElementById("gatheringTypeCategory");
    nameInput.classList.toggle("is-invalid", !name);
    categoryInput.classList.toggle("is-invalid", !gatheringCategoryId);
    if (!name || !gatheringCategoryId) {
      Toast.warning("Please fix the highlighted fields");
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
      // Show the backend's field errors inline too, not only as a toast.
      if (result.errors?.name) {
        nameInput.classList.add("is-invalid");
        nameInput.nextElementSibling.textContent = [].concat(result.errors.name)[0];
      }
      Toast.error(result.message || "Failed to save gathering type");
      return;
    }

    Toast.success(`${editingId ? "Updated" : "Added"} - ${name}`);
    bootstrap.Modal.getInstance(document.getElementById("gatheringTypeModal")).hide();
    await loadList();
    DemographicsUI.flashRow(editingId || result.data?.id);
  }

  async function toggleActive(id) {
    const type = allTypes.find((t) => t.id === id);
    if (!type) return;

    const apply = async () => {
      const result = await DemographicsAPIHandler.updateGatheringType(id, { is_active: !type.is_active });
      if (!result.success) {
        Toast.error(result.message || "Failed to update gathering type");
        return;
      }
      Toast.success(`${type.is_active ? "Deactivated" : "Activated"} - ${type.name}`);
      await loadList();
      DemographicsUI.flashRow(id);
    };

    // Deactivating hides it from the attendance form - confirm first.
    if (type.is_active) {
      Toast.confirm(`Deactivate "${type.name}"? It will be hidden from the attendance form (past records stay).`, apply, null, {
        title: "Deactivate gathering type",
        confirmText: "Deactivate",
        type: "warning",
      });
      return;
    }
    apply();
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
