# Specs

Spec-driven development: every new module or non-trivial feature gets a spec file here, written **before** any code, following the template below. See root `../../CLAUDE.md` → "Spec-Driven Development" for the full process rules.

This convention starts now — the existing Auth, Roles & Permissions, Territory, and Budget modules were built before it existed and don't have retroactive specs. Don't write specs for those unless you're changing them substantially.

## Template

```markdown
# <Module Name> Spec

## Data Model
Tables, columns, relationships. State which existing patterns this follows
(e.g. "territory_type + territory_id, like Budget.php") and call out any
deliberate deviation.

## API Contract
Route list: method, path, request shape, response shape, and which
permission each route requires.

## Permission Rules
Who can do what, scoped by territory level. Be explicit about read vs.
write, and about which territory levels get which access.

## Acceptance Criteria
A checklist of concrete, testable statements. These become the Feature
test cases directly — if a criterion can't be turned into a test
assertion, rewrite it until it can.
```

## Specs

| Spec | Status |
|---|---|
| [demographics-module-spec.md](demographics-module-spec.md) | Outline only — data model shape and territory-level rules are decided (see `../ROADMAP.md`), full contract not yet written |
| [attendance-pdf-reports-spec.md](attendance-pdf-reports-spec.md) | Complete — `GET /attendance-reports/export-pdf`, one endpoint |
| [appearance-settings-spec.md](appearance-settings-spec.md) | Complete — `GET`/`PUT`/`DELETE /api/appearance`, self-scoped per-user preferences |
| [budgets-spec.md](budgets-spec.md) | Complete — budgets for church, region and diocese: a month or a year, no approval, spending, deductions, Overview, reports, the places below read-only; old approval-era code removed |
| [settings-spec.md](settings-spec.md) | Planned (2026-10-02) — one Settings hub for church, region and diocese: profile, service times, team, finance, communication, module settings, diocese system settings; built in phases S0–S5 |
| [calendar-spec.md](calendar-spec.md) | Planned (2026-10-05) — one Calendar for church, region and diocese with the CCI national calendar on top (global admins type it in or import it); each place sees the levels above, view only; phases C1–C2 |
| [events-initiatives-spec.md](events-initiatives-spec.md) | In progress (2026-10-05; Events L1a–L1c done) — Church life: Events (L1) and Initiatives (L2) for church, region and diocese; invitations come down, places register counts; plus the shared foundation (PlaceAccess, in-app notifications, the header bell) |
| [monthly-reports-spec.md](monthly-reports-spec.md) | Planned (2026-10-05) — Church life L4: each church and region sends a monthly report with figures filled in for it; those above see and comment; reminders by app and SMS |
