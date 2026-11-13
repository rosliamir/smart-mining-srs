# Selgem — Backend (Laravel 13 + Filament + PostgreSQL/PostGIS)

Reverse-engineered rebuild of the Selgem/KSSB Selgem Monitoring System.
Companion to the SRS/ERD docs in `~/selgem-srs` and the Vue frontend in `~/selgem-frontend`.

## Stack
- **Laravel 13** (API + admin host)
- **Filament 5** — admin panel for all content types
- **PostgreSQL 17 + PostGIS 3.6** — relational F001 model + geospatial
- **spatie/laravel-permission** — real server-side RBAC (7 roles)
- **Laravel Sanctum** — token auth for the Vue SPA
- **S3 / MinIO** — object storage for point clouds, tiles, orthomosaics, media

## Run
```bash
# DB already created: selgem (PostGIS enabled)
cd ~/selgem-api
php artisan serve --port=8008
```
- **Admin panel:** http://127.0.0.1:8008/admin  — `admin@selgem.local` / `password`
- **API base:** http://127.0.0.1:8008/api

### Frontend (separate terminal)
```bash
cd ~/selgem-frontend && npm run dev   # http://localhost:5178
```
Login with `admin@selgem.local` / `password` — the SPA authenticates against the API
and loads live data (falls back to bundled mock JSON if the API is offline).

## Reset / reseed
```bash
php -d memory_limit=1024M artisan migrate:fresh --seed      # schema + roles + masters/headers
python3 <scratch>/import_children.py                        # deep F001 children (~285k rows)
```

## Data loaded
~285,000 rows: 77 sites (PostGIS geom), 972 employees, 116 users (with roles), 1,062 weekly reports,
5,626 daily reports, 50,634 AVR readings, 27,125 report attendances, 127,159 equipment daily counts, etc.

## API endpoints (auth: `Authorization: Bearer <token>`)
`POST /api/login` · `GET /api/me` · `POST /api/logout` · `GET /api/dashboard`
`GET /api/sites` · `/sites/{id}` · `/regions` · `/contractors` · `/employees` · `/cameras`
`/reports` · `/monitoring-reports` · `/users` · `/attendances` · `/roles`
`GET /api/weekly-reports` · `/weekly-reports/{id}`
`POST /api/weekly-reports/{id}/transition { to }`  ← enforces the F001 state machine

## F001 approval workflow (server-side RBAC)
`draft → pending_review → pending_approval → submitted`, plus `returned` for rework.
Enforced in `app/Services/F001Workflow.php`; every transition is logged to `report_activities`.
Role → allowed action:
- **site_officer** `f001.submit_review` (draft/returned → pending_review)
- **executive** `f001.validate` (pending_review → pending_approval), `f001.return`
- **manager** `f001.final_approve` (pending_approval → submitted), `f001.return`
- **super_admin** `f001.override` (any transition)

Verified: a site_officer calling final-approve gets **HTTP 422** (blocked) — the fix for the
original system, which enforced roles only on the client.

## Object storage (MinIO quickstart)
```bash
# run a local MinIO (or point AWS_* at real S3 and drop AWS_ENDPOINT)
docker run -p 9000:9000 -p 9001:9001 \
  -e MINIO_ROOT_USER=minioadmin -e MINIO_ROOT_PASSWORD=minioadmin \
  minio/minio server /data --console-address ":9001"
# then create the bucket "selgem" in the console (http://127.0.0.1:9001)
```
`config/filesystems.php` disk `s3` is pre-configured from `.env` (default disk stays `local`
so nothing breaks when MinIO is not running). Use `Storage::disk('s3')` for large assets.
