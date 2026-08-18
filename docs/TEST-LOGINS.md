# Test Logins (Dev Only)

Real credentials produced by the current seeders (`backend/database/seeders/`), extracted directly from source — not invented. **Dev/local database only. Never use these values in a production seed.**

Login accepts either **email + password**, or **employee code + PIN** (dual-mode, see `authentication/login.php`). Calling the API directly (`POST /api/auth/login`), the field is named `identifier` — not `email` — and accepts either the email or the employee code:

```bash
curl -X POST http://127.0.0.1:8004/api/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"identifier":"joshujohn03@gmail.com","password":"password"}'
```

Verified working end-to-end against the real seeded data on 2026-08-15 (MySQL `makueni_west_diocese_dev` via MAMP, backend served on port 8004).

**Bishop identity bug fixed 2026-08-18**: `SampleUsersSeeder` and `DioceseLeadershipSeeder` used to both create a Bishop at the same email with different data, and `SampleUsersSeeder` ran first — so the login used to resolve to a placeholder ("David Mutua"). `SampleUsersSeeder` is now retired from `DatabaseSeeder`'s call list, and `FixBishopIdentityAndRetireSampleAccountsSeeder` corrects any database seeded before this fix. The Bishop login now reliably resolves to the real identity below.

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
| Peter Kilonzo | Bishop | `bishop@makueniwestdiocese.or.ke` | `100001` | `1234` |
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

**Test/dev account — no real person named in any source document**, seeded by `SubregionalLeadershipSeeder` so every territory level has a working test login for subregion-scoped read access (e.g. the Demographics review workflow).

| Name | Subregion | Email | Employee Code | PIN |
|---|---|---|---|---|
| Subregional Overseer | Kilungu Subregion | `subregional.overseer@makueniwestdiocese.or.ke` | `800001` | `1234` |

Password: `password`.

## Unused roles (no seeded user yet)

`Diocese Secretary`, `Diocese Treasurer`, `Diocese Administrator` — defined as roles, never assigned to any seeded user. Low priority (see `docs/ROADMAP.md` → 3, marked optional).

## `SampleUsersSeeder` (retired 2026-08-18)

Removed from `DatabaseSeeder`'s call list — its Bishop, 2 generic "Regional Overseer <region>" users, and 5 "Pastor Sample <n>" users (all with randomly generated, unrecoverable PINs) were fully superseded by `DioceseLeadershipSeeder`, `RegionalLeadershipSeeder`, and `FixAndSeedRealChurchLeadershipSeeder`. The seeder file is kept for history but no longer runs. A fresh `migrate:fresh --seed` no longer creates any of these placeholder accounts.
