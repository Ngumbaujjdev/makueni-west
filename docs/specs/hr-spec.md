# Staff (HR) - Spec

**Status:** building (2026-10-10). Backend first; pages in a second PR.
**Levels:** church, region, diocese. Subregions have no staff or payroll.
**Test suite:** `HR` (`backend/tests/Feature/HR`).

## Why

Payroll (accounting-spec A7) kept its own simple list of the people a place pays:
- position as free text;
- no grades;
- allowances typed in per person;
- no link to the member register or to a login;
- no way to move a pastor from one church to another.

The user asked for a Staff module that every level uses. Each level sets up its own positions, grades and allowances, with the diocese's as defaults. Accounting payroll just reads it. **HR has no deductions:** payroll keeps its one optional per-month deduction (a SACCO, a loan); churches deduct no PAYE, NSSF, SHIF or Housing Levy.

## Decisions (2026-10-10)

- **Lists per level with defaults.** Positions, grades and allowance types each belong to a place:
  - the diocese's apply everywhere;
  - a region's apply to the region and its churches;
  - a church's apply to itself.
  - A place switches off a default it doesn't use. Only the owner changes a row.
- **Linking.** A staff member is one of:
  - a person from a church's member register (region and diocese staff use their home church's record);
  - and/or a system login;
  - or just a typed name (a caretaker who isn't a member).
- **Oversight.** The region and the diocese see the names, positions and pay of staff in the places below (`hr.below.read`). Congregation members' names still stay at the church (people-and-care-spec); staff are the exception, because the levels above oversee them.
- **One record.** Staff are the existing `employees` rows, extended, so payroll, payslips and the trail keep working and today's people carry over.

## Data model

| Table | Columns |
|---|---|
| `hr_positions` | territory_id (owner), name, description, levels (json: where it can be used - church / region / diocese; null = any), grade_id (default grade), is_active, display_order, created_by. Unique (territory_id, name). |
| `hr_grades` | territory_id, code, name, min_pay, max_pay, default_pay, description, is_active, display_order, created_by. Unique (territory_id, code). |
| `hr_allowance_types` | territory_id, name, default_amount, description, is_active, display_order, created_by. Unique (territory_id, name). |
| `hr_hidden` | territory_id, kind (position \| grade \| allowance), item_id - a default this place has switched off. Unique. |
| `employees` (extended) | + person_id (people), position_id, grade_id, employment_type (full_time \| part_time \| contract \| casual), contract_end. `user_id` is now set. `position` keeps the position's name as text (payslips copy it). `allowances` json entries may carry `type_id`. |
| `staff_postings` | employee_id, territory_id, position, position_id, from_date, to_date, reason (hired \| transferred \| changed \| left), note, created_by. Every existing employee gets a "hired" posting at their place. |
| media `documents` on Employee | contract, ID copy... up to 5 files (jpg, png, webp, pdf, 5 MB). |

**Pay rules:**
- A grade with a minimum or maximum bounds the basic pay.
- A position may suggest a grade.
- An allowance type may suggest an amount.

**Who pays a month.** The place a person was **last posted to during that month** pays the whole month:
- a new hire on the 15th is paid that month;
- someone transferred on the 15th is paid that month by the new place;
- someone who leaves on the 20th is paid by their place.

Partial months are paid in full (no pro-rating).

## API (`/api/hr`, `?territory_id=` for a place below)

**Staff:**
- `GET overview`: totals here; for a region or the diocese, one row per place below too.
- `GET staff?q&position_id&type&status=active|left|all&below=1&page&per`: `below=1` lists the places below as well (needs `below`).
- `GET staff/{id}`, `POST staff`, `PUT staff/{id}`, `DELETE staff/{id}` (only if never paid).
- `POST staff/{id}/transfer {to_territory_id, date, position_id?, note?}`: by whoever manages staff at a place above both (the region between its churches, the diocese anywhere).
- `POST staff/{id}/end {date, reason?}`: they leave; payroll stops after that month.
- `POST|GET|DELETE staff/{id}/documents[/{media}]`.

**Pickers:**
- `GET options`: the positions, grades and allowance types usable here, employment types, how-to-pay methods, the churches whose members can be picked, and places a transfer can go to.
- `GET people?q&church_id`: members of that church (this place, or a church below).
- `GET logins?q`: users with a role at this place or below.

**Setup:**
- `GET setup`: `{positions, grades, allowances}`. Each row carries `owner: {id, name, level}`, `own`, `hidden`, `in_use` and `can`.
- `POST setup/{kind}`, `PUT setup/{kind}/{id}` (owner only), `DELETE setup/{kind}/{id}` (owner only, if unused; else switch it off).
- `POST setup/{kind}/{id}/here {on}`: use or don't use a default here.

Staff replies show ID number and KRA PIN masked, always.

## Permissions

| Permission | Lets you | Starting grants (the admin changes them in Roles) |
|---|---|---|
| `{level}.hr.staff.read` | see staff and pay here | Church Treasurer, plus everyone who manages |
| `{level}.hr.staff.manage` | add, change, end, delete, documents; transfer between places below | Church: Senior Pastor, Church Administrator. Region: Regional Overseer, Regional Treasurer, Regional Secretary. Diocese: Bishop, Diocese Finance Officer, Diocese Administrator. |
| `{level}.hr.setup.manage` | this place's positions, grades, allowance types; switch defaults off | the same as manage |
| `{level}.hr.below.read` (region, diocese) | see staff of the places below | the same as manage |

- Writing is in your own place only. The exception is a transfer, which the level above makes.
- Grants are made once, ever (the `accounting_seeded_grants` record), so a grant the admin removes stays removed.

**Menu:** a **Staff** group after Finance at each level, holding:
- **Staff** (`/{level}/hr/`);
- **Positions & pay** (`/{level}/hr/positions.php`).

## Payroll reads Staff

- `Payroll::start` takes everyone whose month this place pays (the posting rule), with their basic pay and allowances.
- "Add someone to this run" uses the same rule.
- `POST|PUT /accounting/payroll/employees` stay and save through the Staff service.
- The Payroll People tab points to Staff for changes.

## Acceptance criteria

1. **Lists:**
   - A church sees the diocese's, its region's and its own positions, grades and allowance types.
   - It switches a default off here; the same default stays on at another church.
   - Only the owner edits or removes a row.
   - A row in use can't be removed, only switched off.
2. **Adding staff:**
   - A staff member can be added from a member of the church: name and phone come from the record.
   - A staff member can be linked to a login.
   - A staff member can be added by name only.
   - A region adds its own staff from a member of a church below it.
   - Basic pay outside the grade's range is refused, saying the range.
3. **Transfer:**
   - The region transfers a pastor from church A to church B on a date. B's payroll for that month includes them; A's doesn't. The history shows both postings.
   - A church can't transfer.
4. **Ending:** someone who leaves is paid for their last month and not after.
5. **Deleting and visibility:**
   - Delete works only for someone never on a payslip.
   - The region sees the names and pay of its churches' staff.
   - Another church sees nothing.
   - The Church Treasurer (read) can't change anything.
6. **Payroll:** it uses the HR allowances (with their types) and the position name.
