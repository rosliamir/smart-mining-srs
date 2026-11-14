# Roles & Permissions Matrix (revalidated)
## Smart Monitoring System — companion to `SRS.md`, `ERD.md`, `TRACEABILITY.md`

- **Source:** `GET /users-permissions/roles` + `GET /users-permissions/roles/:id` (Strapi v3 users‑permissions plugin)
- **Revalidated:** 2026‑11‑11 as `azlina` (super_admin)
- **Answer to "can roles be detected?":** ✅ Yes — all **7 roles** detected, each with full id, description and permission set.

---

## 1. Roles (all 7 detected)
| `type` | Name | Role id | Business authority |
|---|---|---|---|
| `super_admin` | Super Admin | `691ad11c4b9aeb05e22bac01` | Full access; overrides any F001 state. |
| `manager` | Manager | `69f870821a16aa82bb0e7588` | Final approval of F001 (`pending_approval → submitted`). |
| `executive` | Executive | `69f86ec71a16aa82bb0e74d6` | Validate/approve F001 and submit to manager (`pending_review → pending_approval`). |
| `site_officer` | Site Officer | `6a0d6403d8bac3e875e6748a` | Create/update F001, submit for review, resubmit returned. |
| `po` | PO | `6a39072932a7aa0cedac1446` | Operational user (import/template privileges). |
| `authenticated` | Authenticated | `604abb0f05fd013db6c7cd29` | Baseline authenticated. |
| `public` | Public | `604abb0f05fd013db6c7cd2a` | Unauthenticated baseline (no app permissions). |

---

## 2. ⚠️ Critical finding — RBAC is in application logic, not API permissions
At the **Strapi route‑permission** level, **all six non‑public roles have near‑identical, near‑full CRUD** (`find, findOne, count, create, update, delete`) on essentially every collection. The F001 approval authority (who may validate vs. final‑approve) is **NOT** enforced by Strapi permissions — it is enforced in **application/business logic keyed on `role.type`** (frontend guards + controller logic on `report-activities`/`weekly-reports`).

**Rebuild implication:** Do **not** assume the current API permissions implement least‑privilege. For the rebuild, implement real server‑side RBAC so the F001 state machine (§3.9 SRS) and destructive actions are enforced by role on the backend — the current setup is permissive.

### Observed per‑role differences (only these differ; everything else is equal)
| Capability | super_admin | manager | executive | site_officer | po | authenticated |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| `weekly-note` CRUD | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| `weekly-reports.summary` action | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ |
| `attendance-upload-template` | find | find | find | find | delete,find,update | find |
| `site-import-template` | find | — | — | — | delete,find,update | — |
| All other ~35 collections (full CRUD) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Custom (non‑CRUD) controller actions present: `attendance.monthly`, `attendance.upload`, `weekly-reports.summary`, `weekly-equipment-entry.clonefromprevious`.

---

## 3. ⚠️ Critical finding — the data model is LARGER than first documented
The permission response is the authoritative list of backend models. It revealed **~17 additional content types** that the first endpoint sweep (which guessed plural names) missed. These must be added to the ERD/SRS for a complete rebuild.

### Already documented (21) — have data & schema
region, site, contractor, user, role, employee, camera, report, attendance, weekly-report, daily-report, daily-system-status, daily-environment-status, daily-sales-record, avr-reading, report-attendance, weekly-assignment, weekly-sales-product, weekly-note, report-activity, non-compliance.

### Newly discovered (not yet schema‑captured) — TODO
| Content type (controller) | Likely purpose |
|---|---|
| `weekly-equipment-entry` | F001 equipment list per week (the real name; earlier `equipment-entries` 404). Has `clonefromprevious` action. |
| `equipment-item-type` | Master list of equipment/item types. |
| `equipment-daily-count` | Daily equipment counts. |
| `site-product` | Products per site (master). |
| `site-sale` | Site sales records. |
| `site-budget` | Site budget data. |
| `site-polygon` | Site boundary polygons (geo). |
| `site-standoff` | Site stand‑off / buffer zones (geo). |
| `site-gallery` + `site-gallery-caption` | Site photo gallery + captions. |
| `site-type` | Master list of site types (enum source for `sites.site_type`). |
| `point-cloud` | 3D point‑cloud assets per site. |
| `tile` | Map raster tiles per site. |
| `monitoring-report` | Monitoring module records. |
| `landowner-monthly-reports` | Monthly landowner reports. |
| `complaint-files` | Complaint attachments. |
| `iso-files` | ISO/compliance document files. |
| `attendance-upload-template` / `site-import-template` | Bulk import template helpers (not data entities). |

**Status:** names confirmed from permissions; **record counts & field schemas for these 17 are still to be captured** (the deeper crawl was blocked by the safety classifier and needs re‑authorization).

---

## 4. Checklist delta
- [x] 7 roles detected with ids + descriptions
- [x] Per‑role permission sets pulled and compared
- [x] RBAC enforcement location identified (app logic, not API)
- [x] ~17 additional content types discovered (names)
- [ ] Capture counts + schemas for the 17 new content types
- [ ] Verify effective access by logging in as each role (only super_admin creds available)

---
*Revalidated 2026‑11‑11. Roles fully detected; see §2–§3 for the two findings that change the rebuild scope.*
