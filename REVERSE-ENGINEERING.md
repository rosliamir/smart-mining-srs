# How we reverse-engineered the Selgem system
Method used to reconstruct the system from the live deployment — repeatable for any similar SPA + API app.

## Principle
No source code was available. We treated the **running system as the specification** and recovered it from three observable surfaces: the **frontend bundle**, the **API**, and the **rendered screens**. Everything was verified against live responses, not guessed.

## Step 1 — Fingerprint the frontend
- Fetched the login page HTML → found a Vite/Vue SPA (`/assets/index-*.js`).
- Downloaded the JS bundle + lazy chunks, grepped for: `baseURL` (→ API host `selgem-api.inference.asia`), route table (`/dashboard`, `/map`, `/site/:id/f001-weekly-report/:weeklyId`, …), and auth strings (`/auth/local`, Bearer tokens) → identified **Strapi v3** backend.
- Extracted the design system from the CSS bundle (Bootstrap 5 + Inter, colors, radii).

## Step 2 — Authenticate & map the API
- `POST /auth/local` with the supplied credentials → JWT + user object (role `super_admin`).
- Probed Strapi REST endpoints with the token: each collection exposes `GET /{col}`, `/{col}/count`, `/{col}/:id`.
- Pulled **one record per collection** → inferred fields, types, enums, and relations (populated objects = foreign keys).

## Step 3 — Discover the *complete* model (the key trick)
- Guessing plural endpoint names found only ~21 collections. The authoritative list came from **role permissions**: `GET /users-permissions/roles/:id` returns every backend controller the app defines → revealed 17 more content types (equipment subsystem, galleries, polygons, ISO files, …) = **~38 total**.
- Lesson: the permission model is the backend's own manifest — read it instead of guessing.

## Step 4 — Recover business logic & enums
- Enumerated distinct values across many records → real enum sets (status, severity, job_level, shift, cycle_type, …).
- Reconstructed the **F001 approval state machine** from the `report-activities` audit log (`from_status → to_status` + `action_by_role`): draft → pending_review → pending_approval → submitted, with `returned` for rework.

## Step 5 — Capture the UI exactly (Playwright)
- Installed Playwright + Chromium in the shell, logged in headless (email identifier), navigated every route.
- For each screen captured: **full-page screenshot**, the **navbar/DOM outerHTML**, and **computed styles** (font 15.2px Inter, primary `#0d6efd`, 12px radii, FontAwesome icons, KSSB/KSMM logos).
- Downloaded real assets (logos, login background) directly from `/assets/`.

## Step 6 — Produce artifacts
- **Docs:** SRS, ERD (Mermaid + data dictionary), TRACEABILITY (100%), ROLES (permission matrix).
- **Data:** full JSON export of all 37 collections (`/data`, 285,238 rows).
- **Prototype:** Vue 3 + Bootstrap 5 rebuild wired to the exported data, styled from the captured computed styles.
- **Verification:** screenshotted the rebuild with Playwright and placed it side-by-side with the live screens (`/compare`).

## Step 7 — Validate
- Re-ran the endpoint + route sweep → 16/16 routes, 37/37 collections, 7/7 roles live = 100% traceability.
- Side-by-side comparison confirms visual fidelity per screen.

## Tooling summary
`curl` (API probing) · `grep` on the JS/CSS bundles · Python (enum/relation analysis, data export, image composition) · **Playwright + Chromium** (login, screen capture, computed styles) · Pillow (comparison video/composites).

## To rebuild from here
1. Stand up Strapi v5 (or Node/Nest) using `ERD.md` as the schema.
2. Seed from `/data/*.json`.
3. Point the Vue prototype's API layer at it (swap the mock loader for the real base URL + JWT).
4. Implement real server-side RBAC (the live app enforces roles in app logic only — see `ROLES.md`).
