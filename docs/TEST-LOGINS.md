# Test Logins (Dev Only)

Real credentials produced by the current seeders (`backend/database/seeders/`), extracted directly from source — not invented. **Dev/local database only. Never use these values in a production seed.**

Login accepts either **email + password**, or **employee code + PIN** (dual-mode, see `authentication/login.php`). Calling the API directly (`POST /api/auth/login`), the field is named `identifier` — not `email` — and accepts either the email or the employee code:

```bash
curl -X POST http://127.0.0.1:8004/api/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"identifier":"joshujohn03@gmail.com","password":"password"}'
```

Verified working end-to-end against the real seeded data on 2026-08-15 (MySQL `makueni_west_diocese_dev` via MAMP, backend served on port 8004).

⚠️ **Known bug — read before using the Bishop login**: `SampleUsersSeeder` and `DioceseLeadershipSeeder` both create a Bishop at the same email (`bishop@makueniwestdiocese.or.ke`) with different data, and `SampleUsersSeeder` runs first in `DatabaseSeeder`. Until this is fixed (`docs/ROADMAP.md` → 2), that login resolves to **"David Mutua," PIN `2468`** — not the "Peter Kilonzo," PIN `1234` shown in the Diocese Leadership table below, which is what it *should* be after the fix.

## Super Admin

| Field | Value |
|---|---|
| Name | Joshua John |
| Email | `joshujohn03@gmail.com` |
| Password | `password` |
| Employee Code | `000001` |
| PIN | `123456` |
| Access | Global — `Global Administrator` role, `global_access = true` |

## Diocese Leadership

| Name | Role | Email | Employee Code | PIN |
|---|---|---|---|---|
| Peter Kilonzo *(see bug note above)* | Bishop | `bishop@makueniwestdiocese.or.ke` | `100001` | `1234` |
| Damaris Makau | Associate Pastor (Bishop Spouse) | `damaris.makau@makueniwestdiocese.or.ke` | `100002` | `1234` |

Password for both: `password`.

## Regional Overseers (also Diocese Council Members, ex-officio)

| Name | Region | Email | Employee Code | PIN |
|---|---|---|---|---|
| Rev. Titus Kenzi | CCI-MWD-SHR | `titus.kenzi@makueniwestdiocese.or.ke` | `200001` | `1234` |
| Rev. Philip Mutiso | CCI-MWD-KLG | `philip.mutiso@makueniwestdiocese.or.ke` | `200002` | `1234` |
| Rev. Christopher Owuor Misori | CCI-MWD-KLB | `christopher.owuor@makueniwestdiocese.or.ke` | `200003` | `1234` |
| Prof. Rev. Moses Makonjio Okello | CCI-MWD-KJS | `moses.makonjio@makueniwestdiocese.or.ke` | `200004` | `1234` |
| Rev. Dishon Maweu | CCI-MWD-MML | `dishon.maweu@makueniwestdiocese.or.ke` | `200005` | `1234` |

Password for all: `password`. These users hold **two** territory assignments each (primary: Regional Overseer at their region; secondary: Diocese Council Member at the diocese) — useful for testing multi-assignment / role-switch behavior.

## Senior Pastors (Church level — 13 seeded, one per church)

Email pattern: `firstname.lastname@makueniwestdiocese.or.ke` (lastname truncated to its first word where a pastor has a compound lastname). Password `password`, PIN `1234` for all.

| Region | Pastors (employee codes) |
|---|---|
| CCI-MWD-SHR | Benson Manoo (`300000`), Stephen Mutisya (`300001`), Robinson Nganda (`300002`) |
| CCI-MWD-KLG | Mark Muema (`301000`), Justus Munyao (`301001`) |
| CCI-MWD-KLB | Titus Nthumbi (`302000`), John Ngala (`302001`), David Kanyenyea (`302002`) |
| CCI-MWD-KJS | Stephen Wambua (`303000`), Julius Mutua (`303001`) |
| CCI-MWD-MML | Alice Muia (`304000`), Zacchaeus Kavai (`304001`), Jairus Nzuki (`304002`) |

Each is assigned `Senior Pastor` at their church (primary, `can_manage_finances = true`) plus a secondary Regional Committee Member assignment at their region — good for testing "church-scoped write, region-scoped read" permission boundaries, which is exactly the shape the Demographics module will need (see `docs/specs/demographics-module-spec.md`).

## Subregional Overseer

**Not currently seeded.** The `Subregional Overseer` role exists but no user has it — tracked in `docs/ROADMAP.md` → 2 as a gap to fill. Until then, there's no dedicated way to test subregion-level read-only access; use a Regional Overseer account and check the subregion-scoped views manually, or temporarily assign the role to a test user via `UserTerritoryAssignment` in Tinker.

## Unused roles (no seeded user yet)

`Diocese Secretary`, `Diocese Treasurer`, `Diocese Administrator` — defined as roles, never assigned to any seeded user. Low priority (see `docs/ROADMAP.md` → 2, marked optional).

## `SampleUsersSeeder` accounts (superseded, still present until the fix lands)

`SampleUsersSeeder` also creates 2 generic "Regional Overseer <region>" users and 5 "Pastor Sample <n>" users with **randomly generated PINs** (unrecoverable — only `password` login works for these). They're redundant with the named accounts above and scheduled for removal in `docs/ROADMAP.md` → 2. Don't rely on their PIN-based login.
