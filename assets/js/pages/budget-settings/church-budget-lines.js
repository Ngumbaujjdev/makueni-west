/**
 * ============================================================================
 * PAGE - CHURCH BUDGET SETTINGS (church/settings/budget-settings/budget-lines.php)
 * ============================================================================
 * Diocese Management System - Makueni West
 *
 * A church works from the diocese's shared budget types, categories and
 * lines (read-only here) and can add lines only it uses. The API decides
 * what the church sees (shared church/all lines + its own) and refuses
 * changes to anything it doesn't own; this page mirrors that with a lock.
 *
 * Dependencies: BudgetAPIHandler, DemographicsUI (list/filter/select
 * helpers), Toast
 * ============================================================================
 */

const ChurchBudgetLines = (function () {
  "use strict";

  const UI = () => DemographicsUI;
  const CAN = typeof BUDGET_LINES_CAN !== "undefined" ? BUDGET_LINES_CAN : {};

  let lines = [];
  let categories = [];
  let editingId = null;

  async function init() {
    Object.assign(USER_TERRITORY, UI().resolveUserTerritory(USER_TERRITORY));
    if (!USER_TERRITORY.id) {
      Toast.error("No church assigned to your account");
      return;
    }

    document.getElementById("addBudgetLineBtn")?.addEventListener("click", openCreateModal);
    document.getElementById("saveBudgetLineBtn").addEventListener("click", save);

    document.getElementById("budgetLinesTableBody").innerHTML = UI().renderTableLoading(6, "Loading budget lines...");
    const [categoriesResult, typesResult] = await Promise.all([
      BudgetAPIHandler.getBudgetCategories(),
      BudgetAPIHandler.getBudgetTypes(),
    ]);
    categories = categoriesResult.success ? categoriesResult.data || [] : [];
    renderCategories();
    renderTypes(typesResult.success ? typesResult.data || [] : []);
    fillCategorySelect();
    await loadLines();
  }

  const isOwn = (line) => !!line.territory_id;
  const categoryColor = (slug) => (slug === "income" ? "success" : slug === "expense" ? "danger" : UI().colorFor(slug));

  async function loadLines() {
    const result = await BudgetAPIHandler.getBudgetLines();
    if (!result.success) {
      document.getElementById("budgetLinesTableBody").innerHTML = UI().renderTableEmpty(6, result.message || "Could not load budget lines");
      return;
    }
    lines = result.data || [];
    renderStats();
    renderRows();

    UI().renderFilterToolbar("filterToolbar", {
      searchPlaceholder: "Search budget lines...",
      filters: [
        { id: "categoryFilter", label: "All Categories", options: categories.map((c) => ({ value: c.name, label: c.name })) },
        {
          id: "sourceFilter",
          label: "Diocese and ours",
          options: [
            { value: "Our church", label: "Our church" },
            { value: "Diocese", label: "Diocese" },
          ],
        },
        {
          id: "statusFilter",
          label: "All Statuses",
          options: [
            { value: "Active", label: "Active" },
            { value: "Off", label: "Off" },
          ],
        },
      ],
    });
    const table = UI().initListDataTable("budgetLinesTable", {
      searchPlaceholder: "Search budget lines...",
      order: [[2, "desc"], [0, "asc"]],
      nonSortableColumns: [5],
      hideDefaultSearch: true,
      noun: "lines",
    });
    UI().wireFilterToolbar("filterToolbar", table, [
      { id: "categoryFilter", columnIndex: 1, exact: true },
      { id: "sourceFilter", columnIndex: 2, exact: true },
      { id: "statusFilter", columnIndex: 4, exact: true },
    ], { noun: "lines" });
  }

  function renderStats() {
    const own = lines.filter(isOwn);
    const shared = lines.length - own.length;
    const ownUsed = own.filter((l) => l.budget_line_items_count > 0).length;
    const off = lines.filter((l) => !l.is_active).length;
    const newest = [...own].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)))[0];

    UI().renderStatCardsRow("statCardsRow", [
      {
        icon: "ri-home-heart-line",
        label: "Our Church's Lines",
        value: own.length,
        color: "primary",
        sub: newest ? `Latest: ${newest.name}` : "None added yet",
      },
      {
        icon: "ri-government-line",
        label: "Diocese Lines",
        value: shared,
        color: "secondary",
        sub: "Shared with every church",
      },
      {
        icon: "ri-file-list-3-line",
        label: "Our Lines in Use",
        value: ownUsed,
        color: "success",
        sub: own.length ? `${own.length - ownUsed} not used in a budget yet` : "Add one to use it",
      },
      {
        icon: "ri-toggle-line",
        label: "Switched Off",
        value: off,
        color: off ? "danger" : "purple",
        sub: off ? "Not offered for new budgets" : "Every line is offered",
      },
    ]);
  }

  function renderRows() {
    const tbody = document.getElementById("budgetLinesTableBody");
    if (!lines.length) {
      tbody.innerHTML = UI().renderTableEmpty(6, "No budget lines yet", "ri-price-tag-3-line");
      return;
    }
    const canChange = CAN.update || CAN.delete;
    tbody.innerHTML = lines
      .map((line) => {
        const own = isOwn(line);
        const category = line.budget_category?.name || "-";
        const categorySlug = line.budget_category?.slug || "";
        const source = own ? "Our church" : "Diocese";
        const status = line.is_active ? "Active" : "Off";
        const used = line.budget_line_items_count || 0;
        const sourcePill = own
          ? UI().pill("Our church", "primary", "ri-home-heart-line")
          : `<span class="soft-chip soft-secondary"><i class="ri-lock-line me-1"></i>Diocese</span>`;
        const statusPill = line.is_active
          ? UI().pill("Active", "success", "ri-checkbox-circle-fill")
          : UI().pill("Off", "danger", "ri-close-circle-fill");

        let actions = `<span class="fs-12 fw-semibold" title="Set by the diocese"><i class="ri-lock-line me-1"></i>Locked</span>`;
        if (own && canChange) {
          actions = `
            <div class="d-inline-flex align-items-center gap-1">
              ${CAN.update ? `<button type="button" class="btn btn-sm btn-primary-light" onclick="ChurchBudgetLines.openEditModal(${line.id})" title="Edit" aria-label="Edit ${esc(line.name)}"><i class="ri-edit-line"></i></button>` : ""}
              <div class="dropdown">
                <button type="button" class="btn btn-sm btn-light list-kebab" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="More actions">
                  <i class="ri-more-2-fill"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  ${CAN.update ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="ChurchBudgetLines.toggleActive(${line.id})">
                    <i class="${line.is_active ? "ri-toggle-line text-danger" : "ri-toggle-fill text-success"} me-2"></i>${line.is_active ? "Switch off" : "Switch on"}
                  </a></li>` : ""}
                  ${CAN.delete ? `<li><a class="dropdown-item" href="javascript:void(0);" onclick="ChurchBudgetLines.remove(${line.id})">
                    <i class="ri-delete-bin-line me-2 text-danger"></i>Delete
                  </a></li>` : ""}
                </ul>
              </div>
            </div>`;
        }

        return `
          <tr data-row-id="${line.id}">
            <td data-search="${esc(line.name)} ${esc(line.description || "")}" data-order="${esc(line.name)}">
              <div class="d-flex align-items-center gap-2">
                ${UI().avatarTile(own ? "ri-home-heart-line" : "ri-price-tag-3-line", line.is_active ? categoryColor(categorySlug) : "secondary")}
                <div style="min-width:0">
                  <div class="fw-semibold">${esc(line.name)}</div>
                  ${line.description ? `<div class="fs-12 text-truncate" style="max-width:320px">${esc(line.description)}</div>` : ""}
                </div>
              </div>
            </td>
            <td data-search="${esc(category)}"><span class="soft-chip soft-${categoryColor(categorySlug)}">${esc(category)}</span></td>
            <td data-search="${source}" data-order="${own ? 1 : 0}">${sourcePill}</td>
            <td class="d-none d-md-table-cell" data-order="${used}">${used ? `<b>${used}</b> ${used === 1 ? "budget" : "budgets"}` : "Not yet"}</td>
            <td data-search="${status}">${statusPill}</td>
            <td class="text-end">${actions}</td>
          </tr>`;
      })
      .join("");
  }

  function renderCategories() {
    const list = document.getElementById("budgetCategoriesList");
    list.innerHTML = categories.length
      ? categories
          .map(
            (c) => `
        <li class="list-group-item d-flex align-items-center gap-3">
          ${UI().avatarTile(c.slug === "income" ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line", categoryColor(c.slug))}
          <div class="flex-fill" style="min-width:0">
            <div class="fw-semibold">${esc(c.name)}</div>
            ${c.description ? `<div class="fs-12">${esc(c.description)}</div>` : ""}
          </div>
          <span class="fs-12 fw-semibold"><b>${lines.filter((l) => l.budget_category_id === c.id).length || ""}</b></span>
          <i class="ri-lock-line" title="Set by the diocese"></i>
        </li>`,
          )
          .join("")
      : `<li class="list-group-item fw-semibold">No categories yet</li>`;
  }

  function renderTypes(types) {
    const list = document.getElementById("budgetTypesList");
    const active = types.filter((t) => t.is_active !== false);
    list.innerHTML = active.length
      ? active
          .map(
            (t) => `
        <li class="list-group-item d-flex align-items-center gap-3">
          ${UI().avatarTile("ri-calendar-2-line", UI().colorFor(t.name))}
          <div class="flex-fill fw-semibold">${esc(t.name)}</div>
          ${t.duration_months ? `<span class="soft-chip soft-primary">${t.duration_months} ${t.duration_months === 1 ? "month" : "months"}</span>` : ""}
          <i class="ri-lock-line" title="Set by the diocese"></i>
        </li>`,
          )
          .join("")
      : `<li class="list-group-item fw-semibold">No budget types yet</li>`;
  }

  function fillCategorySelect() {
    const select = document.getElementById("budgetLineCategory");
    select.innerHTML = categories
      .filter((c) => c.is_active !== false)
      .map(
        (c) =>
          `<option value="${c.id}" data-icon="${c.slug === "income" ? "ri-arrow-down-circle-line" : "ri-arrow-up-circle-line"}" data-color="${categoryColor(c.slug)}">${esc(c.name)}</option>`,
      )
      .join("");
    UI().enhanceSelect(select, { search: false });
  }

  function resetForm() {
    document.querySelectorAll("#budgetLineModal .is-invalid").forEach((el) => el.classList.remove("is-invalid"));
  }

  function openCreateModal() {
    editingId = null;
    resetForm();
    document.getElementById("budgetLineModalTitle").textContent = "Add a line for our church";
    document.getElementById("budgetLineName").value = "";
    document.getElementById("budgetLineDescription").value = "";
    document.getElementById("budgetLineActive").checked = true;
    const select = document.getElementById("budgetLineCategory");
    select.value = categories.find((c) => c.slug === "expense")?.id || categories[0]?.id || "";
    UI().syncSelect(select);
    new bootstrap.Modal(document.getElementById("budgetLineModal")).show();
  }

  function openEditModal(id) {
    const line = lines.find((l) => l.id === id);
    if (!line || !isOwn(line)) return;
    editingId = id;
    resetForm();
    document.getElementById("budgetLineModalTitle").textContent = "Edit our line";
    document.getElementById("budgetLineName").value = line.name || "";
    document.getElementById("budgetLineDescription").value = line.description || "";
    document.getElementById("budgetLineActive").checked = !!line.is_active;
    const select = document.getElementById("budgetLineCategory");
    select.value = line.budget_category_id;
    UI().syncSelect(select);
    new bootstrap.Modal(document.getElementById("budgetLineModal")).show();
  }

  async function save() {
    const nameInput = document.getElementById("budgetLineName");
    const categoryInput = document.getElementById("budgetLineCategory");
    const name = nameInput.value.trim();
    nameInput.classList.toggle("is-invalid", !name);
    categoryInput.classList.toggle("is-invalid", !categoryInput.value);
    if (!name || !categoryInput.value) {
      Toast.warning("Please fix the highlighted fields");
      return;
    }

    const payload = {
      budget_category_id: parseInt(categoryInput.value, 10),
      name,
      description: document.getElementById("budgetLineDescription").value.trim() || null,
      is_active: document.getElementById("budgetLineActive").checked,
    };

    const btn = document.getElementById("saveBudgetLineBtn");
    UI().setButtonLoading(btn, "Saving...");
    const result = editingId
      ? await BudgetAPIHandler.updateBudgetLine(editingId, payload)
      : await BudgetAPIHandler.createBudgetLine(payload);
    UI().restoreButton(btn);

    if (!result.success) {
      if (result.errors?.name) {
        nameInput.classList.add("is-invalid");
        nameInput.nextElementSibling.textContent = [].concat(result.errors.name)[0];
      }
      Toast.error(result.message || "Could not save the line");
      return;
    }

    Toast.success(`${editingId ? "Saved" : "Added"} - ${name}`);
    bootstrap.Modal.getInstance(document.getElementById("budgetLineModal")).hide();
    await reload(editingId || result.data?.id);
  }

  function toggleActive(id) {
    const line = lines.find((l) => l.id === id);
    if (!line) return;
    const apply = async () => {
      const result = await BudgetAPIHandler.updateBudgetLine(id, { is_active: !line.is_active });
      if (!result.success) {
        Toast.error(result.message || "Could not update the line");
        return;
      }
      Toast.success(`${line.is_active ? "Switched off" : "Switched on"} - ${line.name}`);
      await reload(id);
    };
    if (line.is_active) {
      Toast.confirm(`Switch off "${line.name}"? It won't be offered for new budgets. Budgets already using it keep it.`, apply, null, {
        title: "Switch off line",
        confirmText: "Switch off",
        type: "warning",
      });
      return;
    }
    apply();
  }

  function remove(id) {
    const line = lines.find((l) => l.id === id);
    if (!line) return;
    if (line.budget_line_items_count > 0) {
      Toast.warning(`"${line.name}" is used in a budget, so it can't be deleted. Switch it off instead.`);
      return;
    }
    Toast.confirm(`Delete "${line.name}"?`, async () => {
      const result = await BudgetAPIHandler.deleteBudgetLine(id);
      if (!result.success) {
        Toast.error(result.message || "Could not delete the line");
        return;
      }
      Toast.success(`Deleted - ${line.name}`);
      await reload();
    }, null, { title: "Delete line", confirmText: "Delete", type: "error" });
  }

  /** Re-fetch and redraw in place (initListDataTable rebuilds the table, never the page). */
  async function reload(flashId) {
    await loadLines();
    renderCategories();
    UI().flashRow(flashId);
  }

  function esc(value) {
    if (value === null || value === undefined) return "";
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  return { init, openEditModal, toggleActive, remove };
})();

window.ChurchBudgetLines = ChurchBudgetLines;
