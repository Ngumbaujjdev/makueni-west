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
