/**
 * ============================================================================
 * CHURCH HOME - the church at a glance (docs/specs/church-home-spec.md)
 * ============================================================================
 * The YNEX Ecommerce dashboard's own cards, each filled with one side of the
 * church. Every card asks its own module's API and fills in as soon as its
 * answer arrives; a role that can't see a module gets a calm line in that
 * card instead of an error. Nothing here writes.
 * ============================================================================
 */
(function () {
  "use strict";

  const CTX = window.HOME_CTX;
  const A = AccountingUI;
  const UI = DemographicsUI;
  const esc = A.esc;
  const $ = (id) => document.getElementById(id);
  const money = (n) => A.money(n, { cents: false });
  const num = A.num;
  const day = A.day;
  const textOn = (c) => (c === "secondary" || c === "warning" ? "text-dark" : "text-white");
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const TODAY = iso(new Date());
  const YEAR = new Date().getFullYear();
  const link = (path) => `${CTX.church}/${path}`;

  // --------------------------------------------------------------------------
  // One fetch helper, like MembersAPI: the acting role goes with every call
  // and it never throws.
  // --------------------------------------------------------------------------
  async function api(path, params = {}) {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== "") q.set(k, v);
    });
    const h = { Accept: "application/json", Authorization: `Bearer ${localStorage.getItem(Constants.STORAGE_KEYS.AUTH_TOKEN)}` };
    try {
      const role = JSON.parse(localStorage.getItem(Constants.STORAGE_KEYS.CURRENT_ROLE) || "null");
      if (role?.assignment_id) h["X-Assignment-Id"] = String(role.assignment_id);
    } catch (e) {
      /* no role cached - the API uses the primary one */
    }
    try {
      const res = await fetch(`${AppConfig.API_BASE_URL}/${path}${q.toString() ? `?${q}` : ""}`, { headers: h });
      const body = await res.json().catch(() => ({}));
      return { ok: res.ok && body.success !== false, status: res.status, data: body.data ?? null };
    } catch (e) {
      return { ok: false, status: 0, data: null };
    }
  }

  /** A calm line in place of a card's content: an icon, the words, maybe one action. */
  function note(text, { icon = "ri-information-line", action = null } = {}) {
    const a = action ? ` <a href="${action.href}" class="fw-semibold text-primary text-nowrap">${esc(action.text)}<i class="ri-arrow-right-line ms-1"></i></a>` : "";
    return `<div class="home-note"><i class="${icon}"></i><span>${esc(text)}${a}</span></div>`;
  }
  /** Why a card is empty: not this role's part (403), or the API didn't answer. */
  const failNote = (r, who) => (r.status === 403 ? note(`Not part of your role - ${who}.`, { icon: "ri-lock-2-line" }) : note("Couldn't load this just now - refresh to try again.", { icon: "ri-wifi-off-line" }));

  /** The template's "Increased by [x%] this month" line. */
  function deltaLine(delta, caption) {
    if (!delta) return `<span class="fs-12 mb-0">${esc(caption)}</span>`;
    if (delta.dir === "flat") return `<span class="fs-12 mb-0">Same as ${esc(caption.replace(/^vs /, ""))}</span>`;
    const word = delta.dir === "up" ? "Up by" : delta.dir === "down" ? "Down by" : "";
    const color = delta.dir === "up" ? "success" : delta.dir === "down" ? "danger" : "primary";
    const pill = `<span class="badge bg-${color} text-white mx-1">${esc(delta.text)}</span>`;
    return `<span class="fs-12 mb-0">${word}${pill}${esc(caption)}</span>`;
  }
  function kpi(id, value, line) {
    const card = $(id);
    card.querySelector('[data-kpi="value"]').innerHTML = value;
    card.querySelector('[data-kpi="delta"]').innerHTML = line;
  }
  const kpiFail = (id, r, who) => kpi(id, "-", `<span class="fs-12">${r.status === 403 ? `Not part of your role - ${esc(who)}` : "Couldn't load"}</span>`);

  /** The template's green price: money in green with +, out red with -, a transfer plain. */
  function signed(j) {
    const dir = { receipt: "in", payment: "out", bill: "out", payroll: "out" }[j.doc_type];
    return `<span class="fw-semibold ${dir === "in" ? "text-success" : dir === "out" ? "text-danger" : "text-dark"}">${dir === "in" ? "+" : dir === "out" ? "-" : ""}${money(j.amount)}</span>`;
  }

  const moneyTile = (t) => {
    const dir = { receipt: "in", payment: "out", bill: "out", payroll: "out" }[t];
    const [icon, color] = dir === "in" ? ["ri-arrow-left-down-line", "success"] : dir === "out" ? ["ri-arrow-right-up-line", "danger"] : ["ri-arrow-left-right-line", "primary"];
    return `<span class="avatar avatar-md avatar-rounded bg-${color} text-white"><i class="${icon} fs-18"></i></span>`;
  };

  // --------------------------------------------------------------------------
  // Money: the two KPI cards, Latest money, Money this year
  // --------------------------------------------------------------------------
  function renderMoney(r) {
    const who = "the treasurer keeps the books";
    if (!r.ok) {
      kpiFail("kpiIn", r, who);
      kpiFail("kpiOut", r, who);
      $("latestList").innerHTML = `<li>${failNote(r, who)}</li>`;
      $("moneyStats").innerHTML = `<div class="col-12">${failNote(r, who)}</div>`;
      return;
    }
    const d = r.data;
    const prev = d.last_month.label.split(" ")[0];
    const monthLine = (now, before) => (now || before ? deltaLine(UI.periodDelta(now, before || null, {}), before ? `vs ${prev}` : "Nothing last month to compare") : `<span class="fs-12 mb-0">Nothing recorded yet this month</span>`);
    kpi("kpiIn", money(d.month.in), monthLine(d.month.in, d.last_month.in));
    kpi("kpiOut", money(d.month.out), monthLine(d.month.out, d.last_month.out));

    // Latest money - the template's Recent Orders list.
    const held = (d.cash || []).reduce((s, a) => s + Number(a.balance || 0), 0);
    const rows = (d.latest || []).slice(0, 6);
    if (!rows.length) {
      $("latestList").innerHTML = `<li>${
        !held && d.can?.setup
          ? note("Your books aren't set up yet.", { icon: "ri-rocket-line", action: { href: link("accounting/start.php"), text: "Start here" } })
          : note("Nothing recorded yet.", { icon: "ri-bill-line", action: d.can?.receipt ? { href: link("accounting/receipts.php"), text: "Write a receipt" } : null })
      }</li>`;
    } else {
      $("latestList").innerHTML = rows
        .map(
          (j, i) => `
        <li class="${i === rows.length - 1 ? "mb-0" : "mb-3"}">
          <a href="${link("accounting/documents.php")}">
            <div class="d-flex align-items-center">
              <div class="lh-1">${moneyTile(j.doc_type)}</div>
              <div class="flex-fill ms-2 min-w-0">
                <p class="fw-semibold mb-0 text-truncate">${esc(j.party_name || j.narration || j.type_label)}</p>
                <p class="fs-12 text-muted mb-0 text-truncate">${esc(j.type_label)}${j.method_label ? ` · ${esc(j.method_label)}` : ""} · ${esc(A.since(j.date))}</p>
              </div>
              <div class="ms-2 text-nowrap">${signed(j)}</div>
            </div>
          </a>
        </li>`
        )
        .join("");
    }

    // Money this year - the template's Earnings: three figures, then the bars.
    const share = (top, total) => (top && total ? `${esc(top.name)} ${Math.round((top.total / total) * 100)}%` : "");
    const stat = (value, badge, color) => `<span class="mt-1 fs-16 fw-semibold">${money(value)}</span>${badge ? `<span class="soft-chip soft-${color} ms-2">${badge}</span>` : ""}`;
    const cells = $("moneyStats").querySelectorAll("[data-stat]");
    cells[0].innerHTML = stat(d.year.in, share(d.top_income?.[0], d.year.in), "success");
    cells[1].innerHTML = stat(d.year.out, share(d.top_expense?.[0], d.year.out), "danger");
    cells[2].innerHTML = stat(held, `${(d.cash || []).length} account${(d.cash || []).length === 1 ? "" : "s"}`, "primary");
    earnings(d.monthly);
  }

  /** The template's Earnings chart (assets/js/ecommerce-dashboard.js): money in by month, this month solid. */
  function earnings(m) {
    if (!m?.labels?.length || typeof ApexCharts === "undefined") return;
    if (!m.in.some(Number) && !(m.out || []).some(Number)) {
      $("earnings").innerHTML = note("No money recorded in the last 12 months - the bars fill in as receipts are written.", { icon: "ri-bar-chart-2-line" });
      return;
    }
    const rgb = getComputedStyle(document.documentElement).getPropertyValue("--primary-rgb").trim() || "44, 164, 191";
    const last = m.labels.length - 1;
    const colors = m.labels.map((_, i) => (i === last ? `rgb(${rgb})` : `rgba(${rgb}, 0.3)`));
    $("earnings").innerHTML = "";
    new ApexCharts($("earnings"), {
      series: [{ name: "Money in", data: m.in }],
      chart: { height: 200, type: "bar", toolbar: { show: false }, fontFamily: "Inter, sans-serif" },
      grid: { borderColor: "#f1f1f1", strokeDashArray: 3 },
      colors,
      plotOptions: { bar: { columnWidth: "25%", distributed: true, borderRadius: 7 } },
      dataLabels: { enabled: false },
      legend: { show: false },
      xaxis: { categories: m.labels.map((l) => l.split(" ")[0]), axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: "#8c9097", fontSize: "11px" } } },
      yaxis: { labels: { formatter: (v) => (v >= 1000 ? `${Math.round(v / 1000)}k` : v), style: { colors: "#8c9097", fontSize: "11px" } } },
      tooltip: {
        custom: ({ dataPointIndex: i }) =>
          `<div class="home-tip"><strong>${esc(m.labels[i])}</strong><span class="text-success">In ${money(m.in[i])}</span><span class="text-danger">Out ${money(m.out?.[i] || 0)}</span></div>`,
      },
    }).render();
  }

  /** Staff: one line under the money chart. */
  function renderStaff(r) {
    if (!r.ok || !r.data?.figures?.staff) return;
    const f = r.data.figures;
    const el = $("staffLine");
    el.innerHTML = `<span class="avatar avatar-xs avatar-rounded bg-purple text-white me-2"><i class="ri-team-line"></i></span>Paid to staff this year <strong class="mx-1">${money(f.paid)}</strong>· ${num(f.staff)} staff, ${money(f.monthly_pay)} a month <a href="${link("hr/dashboard.php")}" class="ms-auto fw-semibold text-primary text-nowrap">Staff<i class="ri-arrow-right-line ms-1"></i></a>`;
    el.hidden = false;
  }

  // --------------------------------------------------------------------------
  // People: Last Sunday, Members, Who comes
  // --------------------------------------------------------------------------
  function renderAttendance(r) {
    if (!r.ok) {
      kpiFail("kpiSunday", r, "attendance is recorded by the pastor");
      $("whoHead").innerHTML = failNote(r, "attendance is recorded by the pastor");
      return;
    }
    const d = r.data;
    const weeks = d.sunday?.weekly || [];
    const lastW = weeks[weeks.length - 1];
    const prevW = weeks[weeks.length - 2];
    if (lastW) {
      kpi("kpiSunday", `${num(lastW.total)} <small class="fs-12 fw-normal text-muted">people</small>`, deltaLine(prevW ? UI.periodDelta(lastW.total, prevW.total) : null, prevW ? `vs ${day(prevW.date, { day: "numeric", month: "short" })}` : `on ${day(lastW.date, { day: "numeric", month: "short" })}`));
    } else {
      kpi("kpiSunday", "-", `<span class="fs-12">No Sunday recorded yet</span>`);
    }

    // Who comes - the template's Top Countries: a headline, then a row per gathering.
    const s = d.summary || {};
    if (!s.sunday_average) {
      $("whoHead").innerHTML = note("No services recorded yet.", { icon: "ri-calendar-check-line", action: { href: link("attendance/new.php"), text: "Record attendance" } });
      $("whoList").innerHTML = "";
      return;
    }
    $("whoHead").innerHTML = `<div><h4 class="mb-0 lh-1">${num(s.sunday_average)}</h4></div><div><span class="soft-chip soft-primary mx-1">${s.coverage?.percentage ?? 0}% of Sundays recorded</span><span class="text-muted fs-11">Sunday average, last 12 months</span></div>`;
    const months = d.sunday.months || [];
    const trend = months.length > 1 ? Math.sign((months[months.length - 1].average || 0) - (months[months.length - 2].average || 0)) : 0;
    const arrow = (t) => (t > 0 ? '<i class="ti ti-trending-up fs-16 text-success"></i>' : t < 0 ? '<i class="ti ti-trending-down fs-16 text-danger"></i>' : '<i class="ri-subtract-line fs-16 text-primary"></i>');
    const COLORS = ["success", "purple", "pink", "warning", "info"];
    const rows = [
      { name: "Sunday service", icon: "ri-book-open-line", color: "primary", mid: arrow(trend), value: s.sunday_average },
      ...(d.ministries?.items || [])
        .filter((g) => g.average)
        .sort((a, b) => b.average - a.average)
        .slice(0, 4)
        .map((g, i) => ({ name: g.name, icon: g.icon || "ri-group-line", color: COLORS[i], mid: `<span class="fs-11 text-muted text-nowrap">${num(g.times)} times</span>`, value: g.average })),
    ];
    $("whoList").innerHTML = rows
      .map(
        (g, i) => `
      <li class="${i === rows.length - 1 ? "mb-0" : "mb-3"}">
        <div class="d-flex justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center lh-1 min-w-0">
            <span class="avatar avatar-xs me-2 avatar-rounded bg-${g.color} ${textOn(g.color)}"><i class="${g.icon}"></i></span>
            <span class="top-country-name text-truncate">${esc(g.name)}</span>
          </div>
          <div>${g.mid}</div>
          <div class="fs-14">${num(g.value)}</div>
        </div>
      </li>`
      )
      .join("");
  }

  function renderMembers(r) {
    if (!r.ok) return kpiFail("kpiMembers", r, "the register is kept by the pastor");
    const d = r.data;
    if (!d.active) return kpi("kpiMembers", "0", `<span class="fs-12"><a href="${link("members/new.php")}" class="text-primary fw-semibold">Add the first member</a></span>`);
    const joined = Number(d.new_this_month || 0);
    const line = joined
      ? `<span class="fs-12 mb-0">Joined<span class="badge bg-success text-white mx-1">+${num(joined)}</span>this month</span>`
      : `<span class="fs-12 mb-0">${num(d.baptised)} baptised · none new this month</span>`;
    kpi("kpiMembers", num(d.active), line);
  }

  // --------------------------------------------------------------------------
  // Next big day - the template's sale card
  // --------------------------------------------------------------------------
  function renderNext(rs) {
    const items = rs.filter((r) => r.ok).flatMap((r) => r.data || []);
    const next = items
      .filter((a) => ["published", "open", "ongoing"].includes(a.status) && String(a.starts_at).slice(0, 10) >= TODAY)
      .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)))[0];
    if (!next) {
      $("nextTitle").textContent = "Nothing planned yet";
      $("nextDetails").textContent = "Plan an event or an initiative and it shows here.";
      $("nextText").textContent = "Harambees, fellowships, revival weeks and Bible studies - the next one sits on this card.";
      $("nextOpen").href = link("events/new.php");
      $("nextOpen").textContent = "Plan an event";
      return;
    }
    const start = new Date(next.starts_at);
    const end = next.ends_at ? new Date(next.ends_at) : null;
    const time = (d) => d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
    const page = next.kind === "initiative" ? "initiatives/initiative.php" : "events/event.php";
    $("nextKind").textContent = `${next.kind === "initiative" ? "Initiative" : "Event"}${next.type_label ? ` · ${next.type_label}` : ""}`;
    $("nextTitle").textContent = next.title;
    const expected = next.totals?.expected ? `${num(next.totals.expected)} expected so far.` : "";
    $("nextDetails").textContent = [next.venue ? `At ${next.venue}.` : "", next.registration && next.register_by ? `Register by ${day(next.register_by)}.` : "", expected].filter(Boolean).join(" ") || "Open it for the details.";
    $("nextDays").textContent = `${day(next.starts_at)}, ${time(start)}${end ? ` - ${String(next.ends_at).slice(0, 10) === String(next.starts_at).slice(0, 10) ? time(end) : day(next.ends_at)}` : ""}`;
    const badge = $("nextBadge");
    badge.querySelector("[data-day]").textContent = start.getDate();
    badge.querySelector("[data-month]").textContent = start.toLocaleDateString("en-GB", { month: "short" });
    badge.hidden = false;
    const days = Math.round((new Date(String(next.starts_at).slice(0, 10)) - new Date(TODAY)) / 86400000);
    $("nextLink").textContent = days === 0 ? "It's today." : days === 1 ? "It's tomorrow." : `In ${days} days.`;
    $("nextLink").href = `${link(page)}?id=${next.id}`;
    const more = items.filter((a) => a !== next && ["published", "open", "ongoing"].includes(a.status) && String(a.starts_at).slice(0, 10) >= TODAY).length;
    $("nextText").textContent = `${next.audience === "everyone" || !next.audience ? "For everyone" : `For ${String(next.audience).replace(/_/g, " ")}`}${next.open_to && next.open_to !== "own" ? `, open to the ${next.open_to}` : ""}.${more ? ` ${more} more planned after it.` : ""}`;
    $("nextOpen").href = `${link(page)}?id=${next.id}`;
  }

  // --------------------------------------------------------------------------
  // Needs attention - the template's Orders tabs
  // --------------------------------------------------------------------------
  /** One row the template's way: avatar + name, two labelled values, a small tile, the arrow. */
  const attnRow = ({ who, label, a, b, tile, href }) => `
    <tr>
      <td>
        <div class="d-flex align-items-center">
          <div class="lh-1">${A.avatar(who, "md")}</div>
          <div class="align-items-center ms-2">
            <span class="fs-12 text-muted">${esc(label)}</span>
            <p class="mb-0">${esc(who)}</p>
          </div>
        </div>
      </td>
      <td><div class="align-items-center"><span class="fs-12 text-muted">${esc(a[0])}</span><p class="mb-0 fw-semibold">${a[1]}</p></div></td>
      <td><div class="align-items-center"><span class="fs-12 text-muted">${esc(b[0])}</span><p class="mb-0">${b[1]}</p></div></td>
      <td>${tile}</td>
      <td><a aria-label="Open" href="${href}"><span class="orders-arrow"><i class="ri-arrow-right-s-line fs-18"></i></span></a></td>
    </tr>`;
  const emptyRow = (html) => `<tr><td class="p-3 border-0">${html}</td></tr>`;
  const count = (id, n) => ($(id).innerHTML = n ? `<span class="soft-chip soft-danger ms-1">${num(n)}</span>` : "");
  const dueText = (iso, late) => `<span class="${late ? "text-danger fw-semibold" : ""}">${late ? '<i class="ri-time-line me-1"></i>' : ""}${esc(day(iso, { day: "numeric", month: "short" }))}</span>`;
  const iconTile = (icon, color) => `<span class="avatar avatar-md avatar-rounded bg-${color}-transparent text-${color}"><i class="${icon} fs-18"></i></span>`;

  function renderApprovals(r) {
    if (!r.ok) return ($("approvalsRows").innerHTML = emptyRow(failNote(r, "approvals go to the people named in the rules")));
    const items = r.data.items || [];
    count("countApprovals", items.length);
    if (!items.length) return ($("approvalsRows").innerHTML = emptyRow(note("Nothing is waiting for you.", { icon: "ri-checkbox-circle-line" })));
    const ICONS = { payroll_run: ["ri-money-dollar-box-line", "pink"], payment_voucher: ["ri-file-list-3-line", "danger"], requisition: ["ri-hand-coin-line", "warning"] };
    $("approvalsRows").innerHTML = items
      .slice(0, 5)
      .map((x) => {
        const [icon, color] = ICONS[x.subject?.type] || ["ri-shield-check-line", "primary"];
        const late = x.due_at && String(x.due_at).slice(0, 10) < TODAY;
        return attnRow({
          who: x.requested_by || "Someone",
          label: x.subject?.label || "Request",
          a: ["Amount", x.subject?.amount ? money(x.subject.amount) : "-"],
          b: ["Due", x.due_at ? dueText(x.due_at, late) : "-"],
          tile: iconTile(icon, color),
          href: x.subject?.page ? `${link(`accounting/${x.subject.page}`)}?${x.subject.param}=${x.subject.id}` : link("accounting/approvals.php"),
        });
      })
      .join("");
  }

  function renderVisitors(r) {
    if (!r.ok) return ($("visitorsRows").innerHTML = emptyRow(failNote(r, "visitors are followed up by the pastor")));
    const due = r.data.due || [];
    count("countVisitors", r.data.due_count || due.length);
    if (!due.length) return ($("visitorsRows").innerHTML = emptyRow(note("Every visitor has been followed up.", { icon: "ri-checkbox-circle-line", action: { href: link("visitors/new.php"), text: "Record visitors" } })));
    $("visitorsRows").innerHTML = due
      .slice(0, 5)
      .map((v) =>
        attnRow({
          who: v.name,
          label: `${v.visits > 1 ? `${v.visits} visits` : "First visit"}${v.area ? ` · ${v.area}` : ""}`,
          a: ["Came", esc(day(v.last_visit_on, { day: "numeric", month: "short" }))],
          b: ["Follow up by", v.due_on ? dueText(v.due_on, v.overdue) : "-"],
          tile: iconTile("ri-phone-line", "success"),
          href: `${link("visitors/visitor.php")}?id=${v.id}`,
        })
      )
      .join("");
  }

  function renderCare(rCare, rHosp) {
    if (!rCare.ok) return ($("careRows").innerHTML = emptyRow(failNote(rCare, "care is kept by the pastor")));
    const d = rCare.data;
    const TYPES = Object.fromEntries((d.by_type || []).map((t) => [t.key, t.label]));
    const ICONS = { hospital: ["ri-hospital-line", "danger"], prayer: ["ri-hand-heart-line", "purple"], counselling: ["ri-chat-heart-line", "primary"], bereavement: ["ri-heart-line", "secondary"], concern: ["ri-error-warning-line", "warning"], home_visit: ["ri-home-heart-line", "success"], phone_call: ["ri-phone-line", "info"] };
    const hospital = rHosp.ok ? rHosp.data || [] : [];
    const needs = [...hospital.map((h) => ({ ...h, why: "hospital_stay" })), ...(d.needs || []).filter((n) => !hospital.some((h) => h.id === n.id))];
    count("countCare", needs.filter((n) => n.due || n.overdue).length);
    if (!needs.length) return ($("careRows").innerHTML = emptyRow(note("No one is waiting on a visit or a call.", { icon: "ri-checkbox-circle-line" })));
    $("careRows").innerHTML = needs
      .slice(0, 5)
      .map((n) => {
        if (n.why === "not_contacted") {
          return attnRow({
            who: n.who,
            label: `Member${n.person?.area ? ` · ${n.person.area}` : ""}`,
            a: ["Why", "No contact"],
            b: ["For", `${num(d.not_contacted_days)} days`],
            tile: iconTile("ri-user-unfollow-line", "secondary"),
            href: `${link("members/member.php")}?id=${n.person?.id}`,
          });
        }
        const [icon, color] = ICONS[n.type] || ["ri-heart-pulse-line", "primary"];
        return attnRow({
          who: n.who,
          label: `${TYPES[n.type] || "Care"}${n.priority === "high" ? " · High" : ""}`,
          a: n.why === "hospital_stay" ? ["In", esc(n.hospital || "Hospital")] : ["Since", esc(day(n.on, { day: "numeric", month: "short" }))],
          b: ["Next visit", n.next_on ? dueText(n.next_on, n.overdue || n.next_on < TODAY) : "-"],
          tile: iconTile(icon, color),
          href: `${link("pastoral-care/case.php")}?id=${n.id}`,
        });
      })
      .join("");
  }

  // --------------------------------------------------------------------------
  // Budget this year - the template's Top Selling Products
  // --------------------------------------------------------------------------
  function renderBudget(r) {
    const body = $("budgetRows");
    if (!r.ok) return (body.innerHTML = `<tr><td colspan="5" class="p-3">${failNote(r, "budgets are made by the treasurer")}</td></tr>`);
    const d = r.data;
    const lines = [...(d.lines?.out || []), ...(d.lines?.in || [])].filter((l) => l.planned || l.actual);
    if (!lines.length) return (body.innerHTML = `<tr><td colspan="5" class="p-3">${note(`No budget for ${YEAR} yet.`, { icon: "ri-pie-chart-2-line", action: d.can_record || !d.view_only ? { href: link("budget/form.php"), text: "Make one" } : null })}</td></tr>`);
    const time = Number(d.period?.time_pct ?? 100);
    const status = (l) => {
      if (l.is_unplanned) return ["Not planned", "warning"];
      if (l.side === "out" && l.actual > l.planned) return ["Over", "danger"];
      if (l.side === "in" && l.pct < time - 25) return ["Behind", "warning"];
      return ["On track", "success"];
    };
    body.innerHTML = lines
      .sort((a, b) => Math.max(b.planned, b.actual) - Math.max(a.planned, a.actual))
      .slice(0, 5)
      .map((l) => {
        const [label, color] = status(l);
        const inSide = l.side === "in";
        return `
        <tr>
          <td class="text-center lh-1"><span class="avatar avatar-md avatar-rounded bg-${inSide ? "success" : "danger"}-transparent text-${inSide ? "success" : "danger"}"><i class="${inSide ? "ri-arrow-left-down-line" : "ri-arrow-right-up-line"} fs-18"></i></span></td>
          <td>${esc(l.name)}</td>
          <td>${inSide ? "Money in" : "Money out"}</td>
          <td><span class="badge badge-sm bg-${color} ${textOn(color)}">${label}</span></td>
          <td><span class="fw-semibold">${money(l.actual)}</span> <span class="text-muted fs-12">of ${money(l.planned)} · ${Math.round(l.pct || 0)}%</span></td>
        </tr>`;
      })
      .join("");
  }

  // --------------------------------------------------------------------------
  // This week - the template's Top Customers
  // --------------------------------------------------------------------------
  const KIND_LABELS = { service: "Service", meeting: "Meeting", event: "Event", session: "Initiative", booking: "Room booking", due: "Due", conference: "Conference", fasting_prayer: "Fasting & prayer", deadline: "Deadline", holiday: "Holiday", celebration: "Celebration", other: "Date" };
  function renderWeek(r) {
    const list = $("weekList");
    if (!r.ok) return (list.innerHTML = `<li>${failNote(r, "the calendar is kept by the pastor")}</li>`);
    const items = (r.data || []).filter((x) => String(x.start).slice(0, 10) >= TODAY).sort((a, b) => String(a.start).localeCompare(String(b.start)));
    if (!items.length) return (list.innerHTML = `<li>${note("Nothing on the calendar for the rest of the week.", { icon: "ri-calendar-line", action: { href: link("calendar/?add=1"), text: "Add a date" } })}</li>`);
    const shown = items.slice(0, 6);
    list.innerHTML = shown
      .map((x, i) => {
        const time = x.all_day ? "All day" : String(x.start).slice(11, 16);
        const weekday = new Date(`${String(x.start).slice(0, 10)}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short" });
        return `
        <li class="${i === shown.length - 1 ? "mb-0" : "mb-3"}">
          <a href="${link("calendar/")}">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <div class="d-flex align-items-center min-w-0">
                <div class="me-2">${A.dateTile(x.start)}</div>
                <div class="min-w-0">
                  <p class="mb-0 fw-semibold text-truncate">${esc(x.title)}</p>
                  <p class="mb-0 text-muted fs-12 text-truncate">${esc(KIND_LABELS[x.kind] || "Date")} · ${esc(time)}${x.location ? ` · ${esc(x.location)}` : ""}</p>
                </div>
              </div>
              <div><span class="fs-14">${esc(String(x.start).slice(0, 10) === TODAY ? "Today" : weekday)}</span></div>
            </div>
          </a>
        </li>`;
      })
      .join("");
    if (items.length > shown.length) list.insertAdjacentHTML("beforeend", `<li class="mt-3"><a href="${link("calendar/")}" class="fs-12 fw-semibold text-primary">${num(items.length - shown.length)} more this week<i class="ri-arrow-right-line ms-1"></i></a></li>`);
  }

  // --------------------------------------------------------------------------
  // Monthly reports - the template's Products Overview, with its search,
  // Sort By and pages working
  // --------------------------------------------------------------------------
  const REPORT = {
    seen: ["Seen", "success", "ri-eye-line"],
    sent: ["Sent", "primary", "ri-send-plane-line"],
    draft: ["Draft", "warning", "ri-edit-line"],
    late: ["Late", "danger", "ri-time-line"],
    due: ["Due", "info", "ri-alarm-line"],
    not_started: ["Not started", "light", "ri-time-line"],
    not_tracked: ["Not tracked", "light", "ri-subtract-line"],
  };
  const reportState = (m) => (m.late && !m.sent_at ? "late" : m.status === "not_started" && m.due_in_days != null && m.due_in_days <= 10 ? "due" : REPORT[m.status] ? m.status : "not_tracked");
  const ORDER = { late: 0, due: 1, draft: 2, not_started: 3, sent: 4, seen: 5, not_tracked: 6 };
  const PER_PAGE = 6;
  const reports = { all: [], q: "", sort: "new", page: 1, can: {} };

  function renderReports(r) {
    if (!r.ok) {
      $("reportRows").innerHTML = `<tr><td colspan="6">${failNote(r, "monthly reports are written by the pastor")}</td></tr>`;
      $("reportShowing").innerHTML = "Showing 0 Entries";
      return;
    }
    reports.all = (r.data.months || []).map((m) => ({ ...m, state: reportState(m) }));
    reports.can = r.data.can || {};
    drawReports();
  }
  function drawReports() {
    const q = reports.q.toLowerCase();
    let rows = reports.all.filter((m) => !q || `${m.label} ${REPORT[m.state][0]}`.toLowerCase().includes(q));
    rows = rows.sort((a, b) => (reports.sort === "old" ? a.month - b.month : reports.sort === "status" ? ORDER[a.state] - ORDER[b.state] || b.month - a.month : b.month - a.month));
    const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    reports.page = Math.min(reports.page, pages);
    const shown = rows.slice((reports.page - 1) * PER_PAGE, reports.page * PER_PAGE);
    $("reportRows").innerHTML = shown.length
      ? shown
          .map((m) => {
            const [label, color, icon] = REPORT[m.state];
            const current = m.month === new Date().getMonth() + 1;
            const pill = color === "light" ? `<span class="soft-chip soft-secondary"><i class="${icon}"></i>${label}</span>` : `<span class="badge bg-${color} ${textOn(color)}"><i class="${icon} me-1"></i>${label}</span>`;
            const open = m.status === "not_tracked" ? "" : `<a href="${link("monthly-reports/report.php")}?year=${m.year}&month=${m.month}" class="btn btn-sm ${m.state === "late" || m.state === "draft" || m.state === "due" ? "btn-primary" : "btn-light"}">${m.state === "late" || m.state === "draft" || m.state === "due" ? (reports.can.write ? "Write" : "Open") : "Open"}</a>`;
            return `
          <tr>
            <td>
              <div class="d-flex align-items-center">
                <div class="me-2 lh-1"><span class="avatar avatar-sm avatar-rounded ${current ? "bg-primary text-white" : "bg-light text-dark"} fw-semibold fs-11">${esc(m.short)}</span></div>
                <div class="fs-14">${esc(m.label)}</div>
              </div>
            </td>
            <td>${esc(day(m.due_on))}</td>
            <td>${pill}</td>
            <td>${m.sent_at ? esc(day(m.sent_at)) : "-"}</td>
            <td>${m.comments ? `<i class="ri-chat-3-line me-1 text-primary"></i>${num(m.comments)}` : "-"}</td>
            <td class="text-end">${open}</td>
          </tr>`;
          })
          .join("")
      : `<tr><td colspan="6">${note("No month matches that search.")}</td></tr>`;
    $("reportShowing").innerHTML = `Showing ${shown.length} of ${rows.length} Entries <i class="bi bi-arrow-right ms-2 fw-semibold"></i>`;
    const li = (label, page, { active = false, disabled = false } = {}) => `<li class="page-item${active ? " active" : ""}${disabled ? " disabled" : ""}"><a class="page-link${label === "next" && !disabled ? " text-primary" : ""}" href="javascript:void(0);" data-page="${page}">${label}</a></li>`;
    $("reportPages").innerHTML = li("Prev", reports.page - 1, { disabled: reports.page === 1 }) + Array.from({ length: pages }, (_, i) => li(String(i + 1), i + 1, { active: i + 1 === reports.page })).join("") + li("next", reports.page + 1, { disabled: reports.page === pages });
  }
  function wireReports() {
    $("reportSearch").addEventListener("input", (e) => {
      reports.q = e.target.value.trim();
      reports.page = 1;
      drawReports();
    });
    $("reportSort").addEventListener("click", (e) => {
      const a = e.target.closest("[data-sort]");
      if (!a) return;
      reports.sort = a.dataset.sort;
      drawReports();
    });
    $("reportPages").addEventListener("click", (e) => {
      const a = e.target.closest("[data-page]");
      if (!a || a.parentElement.classList.contains("disabled")) return;
      reports.page = Number(a.dataset.page);
      drawReports();
    });
  }

  // --------------------------------------------------------------------------
  // Start: every card asks at once and fills in as its answer comes.
  // --------------------------------------------------------------------------
  function weekBounds() {
    const d = new Date();
    const monday = new Date(d);
    monday.setDate(d.getDate() - ((d.getDay() + 6) % 7));
    const sunday = new Date(monday);
    sunday.setDate(monday.getDate() + 6);
    return { from: iso(monday), to: iso(sunday) };
  }
  function init() {
    wireReports();
    const now = new Date();
    const yearAgo = new Date(now.getFullYear(), now.getMonth() - 11, 1);
    const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;

    api("accounting/overview").then(renderMoney);
    if (CTX.staff) api("hr/dashboard", { year: YEAR }).then(renderStaff);
    api("attendance-reports/analytics", { territory_id: CTX.place.id, from: ym(yearAgo), to: ym(now) }).then(renderAttendance);
    api("people/overview").then(renderMembers);
    Promise.all([api("activities", { kind: "event", scope: "own" }), api("activities", { kind: "initiative", scope: "own" })]).then(renderNext);
    api("approvals", { tab: "waiting" }).then(renderApprovals);
    api("visitors/overview").then(renderVisitors);
    Promise.all([api("care/overview"), api("care/hospital")]).then(([c, h]) => renderCare(c, h));
    api("budgets/dashboard", { year: YEAR }).then(renderBudget);
    api("calendar/events", weekBounds()).then(renderWeek);
    api("monthly-reports", { year: YEAR }).then(renderReports);
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
