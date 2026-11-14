# Software Requirements Specification (SRS)
## Smart Monitoring System — KSSB

- **Document type:** Reverse‑engineering SRS (for full system reconstruction)
- **Prepared from:** Live production system `https://smart-mining.selgem.com` (frontend) + `https://selgem-api.inference.asia` (API)
- **Captured:** 2026‑11‑11, authenticated as super‑admin account
- **Status:** Draft v1.0 — companion to `ERD.md`

> This SRS was produced by inspecting the deployed application (SPA JS bundle, API endpoints, live data and schemas). It is written so the system can be rebuilt from scratch. All collections, fields, enums, relationships, counts and workflow states listed here were observed in the live system.

---

## 1. Introduction

### 1.1 Purpose
Define the complete functional and non‑functional requirements of the **Smart Monitoring System** — a web platform used by KSSB to supervise sand‑mining / quarry sites across Malaysia. It covers live CCTV/drone monitoring, geospatial site mapping, employee attendance, incident/docket reporting, and the **F001 weekly reporting workflow** (the core operational artefact).

### 1.2 Scope
The system provides:
- Centralised **monitoring** of mining sites grouped by region.
- **Live view** of site cameras (CCTV in‑cabin / external, and drone) via HLS streams.
- **Geospatial map** of sites, regions and site boundaries (GeoJSON / point cloud / tiles).
- **Attendance** tracking of employees with geolocation and status.
- **Incident & docket reporting** (e.g. docket cancellation/correction, Gemma/CTY system corrections).
- **F001 weekly reports**: structured daily operational records (weather, manpower, production/sales dockets, weighbridge AVR readings, environment & system compliance) rolled up to a weekly document with a multi‑role approval workflow.
- **Site, user, employee and contractor management**.

### 1.3 Definitions, acronyms, abbreviations
| Term | Meaning |
|---|---|
| **F001** | The standardised weekly site report form (header = weekly report; body = daily reports). |
| **KSSB** | Operating company (owner of the sites). `employees.type = kssb`. |
| **AVR** | Weighbridge automated reading (input/output weight per shift). |
| **Docket (KSSB/PTD)** | Sales docket number ranges issued per day (`doket_kssb_*`, `doket_ptd_*`). |
| **Gemma / CTY** | External government/weighing systems reconciled against (see `cty_gemma_status`, docket corrections). |
| **Stockpile / Topup** | Material inventory balances (`baki_stockpile`, `baki_topup`, `stok_mula`). |
| **Cycle type** | `open_circuit` / `closed_circuit` — the site's water/processing circuit mode. |
| **Region** | Geographic grouping of sites (Northern / Southern). |
| Malay terms | `jualan`=sales, `baki`=balance, `mula`=start, `akhir`=end, `lori_trip`=lorry trips, `pengeluaran_cuci`=washed output, `tarikh`=date, `tindakan`=action, `ulasan`=remarks, `jabatan`=department, `papan_tanda`=signboard, `tanda_sempadan`=boundary marker, `laluan_keluar_masuk`=entry/exit route, `effluent pagi/tghari/petang`=effluent morning/noon/evening. |

### 1.4 System overview (as‑built architecture)
- **Frontend:** Vue 3 SPA, built with Vite. Client‑side routing, token (JWT) stored client‑side and sent as `Authorization: Bearer <jwt>`. Libraries observed: Mapbox GL + Mapbox GL Draw (polygon/point drawing), an HLS video player for camera feeds, a charting component (dashboards), date‑fns. Served as static files behind **nginx**.
- **Backend:** **Strapi v3** headless CMS on **MongoDB** (Mongoose — documents carry `_id`, `__v`). Auth via the Strapi **users‑permissions** plugin (`POST /auth/local`, roles & permissions). File/media via the Strapi **upload** plugin.
- **Streaming:** Camera HLS streams are proxied via **RTSPtoWeb** (`https://rtsptoweb.selgem.com/stream/<uuid>/channel/0/hls/live/index.m3u8`).
- **Hosting/domains:** app `smart-mining.selgem.com`, API `selgem-api.inference.asia`, streams `rtsptoweb.selgem.com`.

---

## 2. Overall description

### 2.1 User roles (observed in `users-permissions/roles`)
| Role `type` | Name | Authority |
|---|---|---|
| `super_admin` | Super Admin | Full access to all modules and data; can override any F001 workflow transition (return/submit from any state). |
| `manager` | Manager | Views and performs **final approval** on F001 reports submitted by Executive (`pending_approval → submitted`). |
| `executive` | Executive | Views, **validates/approves** F001 and submits to Manager (`pending_review → pending_approval`), or returns to Site Officer. |
| `site_officer` | Site Officer | **Creates and updates** F001 weekly reports; submits drafts for review (`draft → pending_review`); resubmits returned reports. |
| `po` | PO | Project/operational user (limited; "user po"). |
| `authenticated` | Authenticated | Default authenticated baseline. |
| `public` | Public | Unauthenticated baseline. |

### 2.2 Product functions (modules → routes)
| Module | Route(s) | Summary |
|---|---|---|
| Authentication | `/login`, `/forgot-password`, `/reset-password` | Login, password reset via email token. |
| Dashboard | `/dashboard` | KPI summary across regions/sites (counts, charts). |
| Map | `/map` | Sites plotted by lat/lng; region centroids; site boundaries/GeoJSON. |
| Live View | `/live-view` | Grid of live CCTV/drone HLS streams per site. |
| Monitoring | `/monitoring` | Per‑site operational/compliance monitoring. |
| Reports | `/reports` | Incident & docket reports list + create/edit. |
| Attendance | `/attendance` | Employee attendance log (geolocated, by site/date/status). |
| Site Management | `/site-management`, `/site/:siteId` | CRUD sites, cameras, contractors, permits & files. |
| F001 Weekly Report | `/site/:siteId/f001-weekly-report/:weeklyId` | Create/edit/approve the weekly report and its daily records. |
| User Management | `/user-management` | CRUD users, roles, employees. |
| Account | `/account` | Current user profile & password change. |
| Error / Under development | `/error`, `/under-development` | Fallback pages. |

### 2.3 Operating environment
- Modern desktop/tablet browsers. SPA requires JavaScript.
- Backend: Node.js (Strapi v3), MongoDB, file storage for uploads, nginx reverse proxy, HTTPS everywhere.
- Camera sources reachable by RTSPtoWeb gateway.

### 2.4 Constraints
- Must preserve the **F001 approval state machine** exactly (see §3.9).
- Bilingual domain content (English + Bahasa Melayu field names) must be retained to match operational forms.
- Weather is recorded at **30‑minute granularity** (48 slots/day).
- Data volumes are significant (see §2.6) — list endpoints must paginate.

### 2.5 Assumptions & dependencies
- External dependency on RTSPtoWeb for camera streams; cameras store a ready HLS URL (`hlsUrl`).
- Reconciliation with external Gemma/CTY/weighbridge systems is manual (captured as statuses/reports), not a live integration.

### 2.6 Data volumes (live counts, 2026‑11‑11 — rebuild/load‑test target)
| Collection | Count | Collection | Count |
|---|---:|---|---:|
| regions | 2 | daily-reports | 5,626 |
| sites | 77 | daily-system-statuses | 5,626 |
| contractors | 83 | daily-environment-statuses | 5,627 |
| users | 115 | daily-sales-records | 6,815 |
| roles | 7 | avr-readings | 50,634 |
| employees | 972 | report-attendances | 27,125 |
| cameras | 7 | weekly-assignments | 5,567 |
| reports | 27 | weekly-sales-products | 1,270 |
| attendances | 9,050 | weekly-notes | 5,679 |
| weekly-reports | 1,062 | report-activities | 4,295 |
| non-compliances | 101 | equipment-daily-counts | 127,248 |
| weekly-equipment-entries | 24,008 | equipment-item-types | 23 |
| site-gallery-captions | 1,668 | tiles | 1,497 |
| site-standoffs | 724 | point-clouds | 71 |
| site-polygons | 56 | site-galleries | 43 |
| monitoring-reports | 32 | landowner-monthly-reports | 82 |
| site-products | 12 | site-types | 11 |
| complaint-files | 3 | site-sales / site-budgets / iso-files | 0 (empty) |

> Total ≈ **38 content types**, ~**290,000 records**. First‑pass documented 21; the equipment/geo/compliance groups above were found via the role permission model.

---

## 3. System features / functional requirements

### 3.1 Authentication & authorization
- **FR‑A1** Users log in with identifier (username or email) + password via `POST /auth/local` → returns `{ jwt, user }`. (Strapi v3 semantics: endpoints are **un‑prefixed**, e.g. `/auth/local`, not `/api/...`.)
- **FR‑A2** JWT is attached to all subsequent requests as `Authorization: Bearer <jwt>`.
- **FR‑A3** Forgot/reset password: `POST /auth/forgot-password` (email), `POST /auth/reset-password` (token + new password).
- **FR‑A4** Route guards: unauthenticated users are redirected to `/login`; role‑gated routes/actions enforce the role matrix in §2.1 (client‑side guard + server‑side Strapi permissions).
- **FR‑A5** `GET /users/me` returns the current user with `role`, `regions`, `sites`, `employee_id`, `position`.

### 3.2 Dashboard (`/dashboard`)
- **FR‑D1** Show aggregate KPIs: number of sites (by region, active/inactive), employees, open reports, attendance summary, F001 reports by status.
- **FR‑D2** Charts for trends (production/sales, attendance, report status distribution).
- **FR‑D3** Filter by region and/or site.

### 3.3 Map (`/map`)
- **FR‑M1** Render a Mapbox map centred on Malaysia; plot each **site** at its `lat`/`lng`.
- **FR‑M2** Plot **region** centroids (`regions.lat/lng`) and allow grouping/filtering by region.
- **FR‑M3** Display site **boundaries** from `sites.geojson` / `geojson_url` / `boundary`, and optional raster `tile`/`tiles` and `point_cloud` overlays.
- **FR‑M4** Drawing tools (Mapbox GL Draw): draw/edit polygons and points to define/update site boundary (`draw_polygon`, `draw_point`, `direct_select`) — used in Site Management.
- **FR‑M5** Click a site marker → open Site Details.

### 3.4 Live View (`/live-view`)
- **FR‑LV1** List cameras (`GET /cameras`), each with `name`, `type` (`cctv` | `drone`), `location`, `lat/lng`, `site`, and `hlsUrl`.
- **FR‑LV2** Play HLS streams in a multi‑camera grid; group/filter by site.
- **FR‑LV3** Handle offline/unavailable streams gracefully.

### 3.5 Monitoring (`/monitoring`)
- **FR‑MON1** Per‑site operational snapshot combining latest daily system status (`cctv_dalam_kabin_status`, `cctv_luar_status`, `cty_gemma_status`, `e_docket_status`) and environment status (effluent readings, ponds `kolam1..3`, spillway, spilltray, signboards, boundary markers, entry/exit route).
- **FR‑MON2** Highlight non‑compliant items; link to the source F001 daily report.

### 3.6 Attendance (`/attendance`)
- **FR‑AT1** Record/list **attendances**: `employee`, `site`, `date`, `lat`, `status` ∈ {`present`, `late`, `absent`, `on_leave`}.
- **FR‑AT2** Filter by site, date range, status; export.
- **FR‑AT3** Distinct from **report-attendances** (§3.8) which are F001 daily manpower records tied to a daily report and weekly assignment.

### 3.7 Reports — incidents & dockets (`/reports`)
- **FR‑R1** List/create **reports** with: `name`, `date`, `type` ∈ {`docket_cancelation`, `docket_correction`, null/general}, `severity` ∈ {`Low`, `Normal`, `High`}, `status` (e.g. `New`), `scope` (free text — site/zone/system), `issue`, `action`, `remarks`, `media` (uploaded files/photos), `polygon` (GeoJSON geometry on map), `site`, `user` (author).
- **FR‑R2** Attach photos/media and draw an affected area polygon.
- **FR‑R3** Filter by site, type, severity, status, date.

### 3.8 F001 Weekly Report — data capture (`/site/:siteId/f001-weekly-report/:weeklyId`)
The F001 is the central feature. A **weekly-report** (header) aggregates seven days of **daily-reports**, plus weekly‑level assignments, sales products, notes, non‑compliances and an activity log.

**Weekly header (`weekly-reports`)**
- **FR‑F1** Header fields: `document_no`, `site`, `contractor`, `prepared_by` (user), `reviewed_by`, `status`, `cycle_type` ∈ {`open_circuit`, `closed_circuit`}, `start_effective_date`, `end_effective_date`.
- **FR‑F2** Product pricing & permit date ranges per material: `clay_*`, `granite_*`, `sand_*`, `laterite_*`, `ground_load_*` (each with `*_startDate`, `*_endDate`, and `*_price` where applicable), plus `kb_*`, `abstraksi_*`, `effluent_*`, `calibration_cert_t1/t2/t3_*` date ranges.
- **FR‑F3** CCTV inventory/status: `cctv_luar_qty`/`cctv_luar_status`, `cctv_dalam_kabin_qty`/`cctv_dalam_kabin_status`.

**Daily report (`daily-reports`, 7 per week)**
- **FR‑F4** Per day: `date`, `is_public_holiday` (+ `public_holiday_name`), `weather_slots` (object keyed by 48 half‑hour times `00:00..23:30`, each holding a weather code), `daily_system_status` (1:1), `daily_environment_status` (1:1).
- **FR‑F5** **Manpower counts** by job: `mekanik_count`, `operator_longarm_count`, `operator_palong_count`, `pekerja_am_count`, `pemandu_excavator_count`, `pemandu_lori_count`, `pemandu_motor_grader_count`, `pemandu_rb_count`, `pemandu_shovel_count`, `pemandu_tractor_count`, `pemandu_water_tanker_count`.
- **FR‑F6** Child records: `report_attendances[]`, `daily_sales_records[]`, `avr_readings[]`.

**Daily system status (`daily-system-statuses`, 1:1 daily)**
- **FR‑F7** Fields: `cctv_dalam_kabin_status`, `cctv_luar_status`, `cty_gemma_status`, `e_docket_status` (operational/compliance flags).

**Daily environment status (`daily-environment-statuses`, 1:1 daily)**
- **FR‑F8** Fields: `effluent_pagi/tghari/petang`, `kolam1/2/3`, `spillway`, `spilltray` (+`spilltray_images`), `papan_tanda` (+images), `tanda_sempadan` (+images), `laluan_keluar_masuk` (+images), `maintenance`. Image fields hold uploaded media.

**Daily sales record (`daily-sales-records`)**
- **FR‑F9** Production/sales dockets per product: `doket_kssb_mula/akhir/baki`, `doket_ptd_mula/akhir/baki`, `stok_mula`, `baki_stockpile`, `baki_topup`, `jualan`, `jualan_terkumpul`, `lori_trip`, `pengeluaran_cuci`; linked to `daily_report` and `weekly_sales_product`.

**AVR / weighbridge readings (`avr-readings`)**
- **FR‑F10** Per weighbridge per shift: `weighbridge`, `shift`, `input`, `output`; linked to `daily_report`. (Highest‑volume table — ~50k rows; design for scale.)

**Report attendance (`report-attendances`)**
- **FR‑F11** F001 daily manpower attendance: `employee`, `date`, `status` ∈ {`present`, `absent`, `on_leave`}, `note`; linked to `daily_report` and `weekly_assignment`.

**Weekly assignment (`weekly-assignments`)**
- **FR‑F12** Assign an `employee` to the week with `shift` ∈ {`am`, `pm`, `normal`} and `note`.

**Weekly sales products (`weekly-sales-products`)**
- **FR‑F13** Products sold that week: `product`, `permit_code`, `permit_start_date`, `permit_end_date`.

**Weekly notes (`weekly-notes`)**
- **FR‑F14** Action log: `tarikh`, `tindakan`, `ulasan`, `deadline`.

**Non‑compliances (`non-compliances`)**
- **FR‑F15** Issues raised: `jabatan` (department), `tarikh`, `ulasan`.

**PDF/preview**
- **FR‑F16** Generate an F001 preview/printable document (observed `F001PreviewModal`) assembling header + 7 daily reports + summaries.

### 3.9 F001 approval workflow (state machine — observed from `report-activities`)
Every transition is logged in **report-activities** (`from_status`, `to_status`, `action_by`, `action_by_name`, `action_by_role`, `weekly_report`).

States: `draft → pending_review → pending_approval → submitted` (terminal/approved), with `returned` as a rework state.

```
            (create)                 site_officer              executive                 manager
   ∅  ──────────────────▶  draft  ───────────────▶  pending_review  ──────────▶  pending_approval  ──────────▶  submitted
                             ▲                           │  │                         │  │                      (final)
                             │ returned→pending_review   │  │ returned                │  │ returned
          site_officer ──────┘ (resubmit)                │  ▼                         │  ▼
                                                          └ returned ◀────────────────┘ returned
```

- **FR‑W1** `∅ → draft` by **site_officer** (create).
- **FR‑W2** `draft → pending_review` by **site_officer** (submit for review).
- **FR‑W3** `pending_review → pending_approval` by **executive** (validate/approve).
- **FR‑W4** `pending_review → returned` by **executive** (send back for correction).
- **FR‑W5** `pending_approval → submitted` by **manager** (final approval).
- **FR‑W6** `pending_approval → returned` (reject back).
- **FR‑W7** `returned → pending_review` by **site_officer** (resubmit after fixes).
- **FR‑W8** **super_admin** may perform any transition (override), including `pending_review/pending_approval → returned`.
- **FR‑W9** Record who/when/role for every transition; display the activity timeline on the F001.

### 3.10 Site management (`/site-management`, `/site/:siteId`)
- **FR‑S1** CRUD sites with: `name`, `site_code`, `region`, `contractor`, `supervisor`, `product`, `site_type`, `type` (e.g. `Sand_Mining`), `is_activated`, `lat`, `lng`, `total_acreage`, `reserve_land`, `remaining_land`, permit dates (`permit_date`, `permit_date_end`, `luas_permit_date`), geometry (`geojson`/`geojson_url`/`boundary`/`tile`/`tiles`/`point_cloud`).
- **FR‑S2** Upload site documents: `eia_file`, `oms_file`, `luas_approval_file`, `permit_OR_license_approval_file`, `site_report_file`.
- **FR‑S3** Manage the site's **cameras** (name, type, location, lat/lng, hlsUrl).
- **FR‑S4** Draw/edit site boundary on the map (§FR‑M4).

### 3.11 User / employee / contractor management (`/user-management`)
- **FR‑U1** CRUD **users**: `username`, `email`, `role`, `regions[]`, `sites[]`, `company`, `employee_id`, `position`, `confirmed`, `blocked`.
- **FR‑U2** CRUD **employees**: `name`, `type` ∈ {`kssb`, `contractor`}, `job_level` ∈ {`pengurus`, `penolong_pengurus`, `eksekutif_kanan`, `eksekutif`, `eksekutif_muda`, `penyelia`, `penolong_penyelia`, `operator`, `po`}, `sites[]`, `is_activated`, `is_deleted`.
- **FR‑U3** CRUD **contractors**: `name` (referenced by sites and weekly reports).
- **FR‑U4** Manage roles/permissions (Strapi users‑permissions).

### 3.12 Account (`/account`)
- **FR‑AC1** View/update own profile; change password.

### 3.13 Equipment tracking (F001 equipment section)
- **FR‑EQ1** Maintain an **equipment item type** master (`equipment-item-types`: `name`, `code`, `category`, `display_order`; 23 items).
- **FR‑EQ2** Per weekly report, list **equipment entries** (`weekly-equipment-entries`: `item_type`, `brand`, `owner`, `specification`, `note`, `images`; ~24k).
- **FR‑EQ3** Record a **daily count** per equipment entry per day (`equipment-daily-counts`: `weekly_equipment_entry`, `daily_report`, `count`; ~127k — largest table).
- **FR‑EQ4** `weekly-equipment-entry.clonefromprevious` action: copy last week's equipment list into a new week.

### 3.14 Site geospatial & media assets
- **FR‑GS1** `site-polygons` — named, dated boundary polygon sets per site (GeoJSON `features`; 56).
- **FR‑GS2** `site-standoffs` — buffer/stand‑off markers (`lat`, `lng`, `bearing`, `media`; 724).
- **FR‑GS3** `point-clouds` — 3D scan asset URLs per site (71).
- **FR‑GS4** `tiles` — dated map raster tile layers per site (`url`; 1,497).
- **FR‑GS5** `site-galleries` + `site-gallery-captions` — site photo galleries with per‑image captions (43 / 1,668).

### 3.15 Compliance & external reports
- **FR‑CR1** `monitoring-reports` — uploaded monitoring report files (`name`, `type`, `published_at`, `files`, `upload_by`; 32).
- **FR‑CR2** `landowner-monthly-reports` — monthly landowner reports with their own **submit → review → approve** workflow (`submitted_by`, `reviewed_by`, `approved_by`, `status`, `file`, `site`; 82).
- **FR‑CR3** `complaint-files` — complaint attachments per site (`name`, `type`, `files`, `upload_by`; 3).
- **FR‑CR4** `iso-files` — ISO/compliance document store (defined, currently empty).

### 3.16 Master data
- **FR‑MD1** `site-types` master (11) — source of a site's type classification.
- **FR‑MD2** `site-products` master (12) — product catalog.
- **FR‑MD3** `site-sales`, `site-budgets` — defined but **empty** (feature stubs; confirm intent before building).

### 3.17 Role & permission note (see `ROLES.md`)
- **FR‑RP1** 7 roles exist. ⚠️ Current Strapi API permissions are near‑identical across roles; the F001 approval authority is enforced in **application logic by `role.type`**, not by backend route permissions. The rebuild **must** implement real server‑side RBAC.

---

## 4. External interface requirements

### 4.1 API (Strapi v3 REST — base `https://selgem-api.inference.asia`, **no `/api` prefix**)
Standard Strapi v3 CRUD per collection: `GET /{col}`, `GET /{col}/:id`, `GET /{col}/count`, `POST /{col}`, `PUT /{col}/:id`, `DELETE /{col}/:id`. Query params: `_limit`, `_start`, `_sort=field:ASC|DESC`, `_where`/field filters, `_q` (search). Auth endpoints under `/auth/*`. See **Appendix B** for the full endpoint catalog.

### 4.2 Streaming interface
- HLS over HTTPS from RTSPtoWeb: `https://rtsptoweb.selgem.com/stream/<uuid>/channel/0/hls/live/index.m3u8`. Player must support HLS (native or hls.js).

### 4.3 Map/geo interface
- Mapbox GL JS + Mapbox GL Draw. Requires a Mapbox access token (provisioned via config). GeoJSON FeatureCollections for boundaries.

### 4.4 File/media interface
- Strapi upload plugin: `POST /upload` (multipart), media referenced from record fields (`media`, `*_file`, `*_images`, `point_cloud`).

---

## 5. Data requirements
Full logical data model, relationships and a per‑collection data dictionary are in the companion **`ERD.md`**. Summary: 21 primary collections centred on `site`, `weekly-report` (F001) and `daily-report`; MongoDB document store (Strapi v3). Enumerations are catalogued in **Appendix A**.

---

## 6. Non‑functional requirements
- **NFR‑1 Security:** JWT auth; role‑based access aligned to §2.1; server‑side permission enforcement (never trust client guards); HTTPS only; passwords hashed by Strapi; reset tokens time‑limited.
- **NFR‑2 Performance:** List endpoints must paginate (`avr-readings` ~50k, `report-attendances` ~27k). Dashboard aggregates should be pre‑computed or indexed. Index foreign keys (`site`, `weekly_report`, `daily_report`, `employee`) and `date`.
- **NFR‑3 Availability:** Streaming degrades gracefully if a camera/RTSPtoWeb is down; app remains usable.
- **NFR‑4 Usability:** Bilingual labels (EN/BM); mobile/tablet friendly for field attendance capture; map interactions intuitive.
- **NFR‑5 Auditability:** All F001 state changes logged with actor, role, timestamp (`report-activities`). Consider extending audit to reports and master‑data edits.
- **NFR‑6 Data integrity:** Enforce the F001 state machine server‑side; validate docket number continuity (`*_mula`/`*_akhir`/`*_baki`) and sales/stockpile balances.
- **NFR‑7 Scalability:** Partition/index high‑volume daily operational tables by `date`/`site`.
- **NFR‑8 Localization/timezone:** Malaysia (UTC+8); dates stored ISO‑8601 UTC, displayed local.

---

## Appendix A — Enumerations (observed values)
| Field | Collection | Values |
|---|---|---|
| role `type` | roles | super_admin, manager, executive, site_officer, po, authenticated, public |
| `status` | attendances | present, late, absent, on_leave |
| `status` | report-attendances | present, absent, on_leave |
| `shift` | weekly-assignments / avr-readings | am, pm, normal |
| `status` | weekly-reports | draft, pending_review, pending_approval, submitted, returned |
| `cycle_type` | weekly-reports | open_circuit, closed_circuit |
| `type` | reports | docket_cancelation, docket_correction, (null/general) |
| `severity` | reports | Low, Normal, High |
| `status` | reports | New (+ lifecycle extensions) |
| `type` | cameras | cctv, drone |
| `type` | employees | kssb, contractor |
| `job_level` | employees | pengurus, penolong_pengurus, eksekutif_kanan, eksekutif, eksekutif_muda, penyelia, penolong_penyelia, operator, po |
| `type` | sites | Sand_Mining, (others) |
| F001 state | report-activities | draft, pending_review, pending_approval, submitted, returned |

## Appendix B — API endpoint catalog (counts as of 2026‑11‑11)
`regions`(2), `sites`(77), `contractors`(83), `users`(115), `users-permissions/roles`(7), `employees`(972), `cameras`(7), `reports`(27), `attendances`(9050), `weekly-reports`(1062), `daily-reports`(5626), `daily-system-statuses`(5626), `daily-environment-statuses`(5627), `daily-sales-records`(6815), `avr-readings`(50634), `report-attendances`(27125), `weekly-assignments`(5567), `weekly-sales-products`(1270), `weekly-notes`(5679), `report-activities`(4295), `non-compliances`(101). Auth: `/auth/local`, `/auth/forgot-password`, `/auth/reset-password`, `/users/me`. Uploads: `/upload`.

## Appendix C — Technology stack (as‑built → recommended rebuild)
| Layer | As‑built | Rebuild note |
|---|---|---|
| Frontend | Vue 3 + Vite SPA, Mapbox GL + Draw, HLS player, charts, date‑fns | Keep Vue 3, or migrate to React/Next if preferred; keep Mapbox/MapLibre. |
| Backend | Strapi v3 on MongoDB (users‑permissions, upload) | Strapi v3 is EOL — rebuild on Strapi v5 or a custom Node/Nest API; preserve collection shapes & workflow. |
| Auth | Strapi JWT (`/auth/local`) | Keep JWT + RBAC per §2.1. |
| Streaming | RTSPtoWeb → HLS | Reuse RTSPtoWeb or go2rtc. |
| Infra | nginx, HTTPS, separate app/api/stream domains | Same. |

---
*End of SRS. See `ERD.md` for the full entity‑relationship model and data dictionary.*
