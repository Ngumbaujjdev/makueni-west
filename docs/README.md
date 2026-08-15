# Documentation Index

Start with `../CLAUDE.md` (root) for working conventions — git workflow, testing, design rules, spec-driven process. This folder is the deep-dive reference.

| Doc | What it covers |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Territory hierarchy pattern, dual permission-gate system, why the app is two co-located apps instead of one |
| [AUDIT-2026-08.md](AUDIT-2026-08.md) | Dated snapshot: what's actually built vs. stubbed, across backend and frontend, verified by reading the code — not assumed |
| [ROADMAP.md](ROADMAP.md) | The deferred implementation plan — demographics first, then multi-tenancy readiness, seeder fixes, port change, Larastan config, remaining budget pages |
| [TEST-LOGINS.md](TEST-LOGINS.md) | Real seeded dev-account credentials, by role, with a known bug flagged |
| [specs/](specs/README.md) | Spec-driven development specs — one file per module, written before that module's code |
| [design/](design/demographics-mobile-app-design.md) | Design references (currently: the Demographics mobile PWA design — a future build, kept here for its field lists and workflow) |

## Docs that already existed before this pass (not duplicated here — go read them directly)

- `../backend/docs/api-changes/` — `README.md`, `API_CHANGES_ROLE_PERMISSIONS.md`, `PERMISSIONS_FOR_ROLE_MANAGEMENT.md`, `FRONTEND_INTEGRATION_GUIDE.md`. Changelog-style docs for the roles/permissions API, written for frontend integration. Still accurate as of this audit.
- `../.docs/CREATE_BUDGET_WALKTHROUGH.md` and `CREATE_BUDGET_IMPLEMENTATION_SUMMARY.md` — how the Budget module's UI was built; the closest thing to a precedent for how to build the Demographics UI.
- `../.docs/creating-modules-page.md` — currently empty.
- `../makueni west diocese documents/` — the original 2025 scope docs (`.docx`). Historical: defines the original 6-feature vision and the 3 "core modules" (Demographics, Finance, Administration). Superseded in the details by what's actually been built (see AUDIT-2026-08.md), but still the source for the overall product vision and org-structure rationale.
