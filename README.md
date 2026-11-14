# Selgem — Smart Monitoring System Rebuild

Reverse-engineered rebuild of the KSSB **Smart Monitoring System** (mining site monitoring,
F001 weekly reports, attendance, permits, maps).

| Folder | Contents |
|---|---|
| [`SRS.md`](SRS.md), [`ERD.md`](ERD.md), [`ROLES.md`](ROLES.md), [`TRACEABILITY.md`](TRACEABILITY.md), [`REVERSE-ENGINEERING.md`](REVERSE-ENGINEERING.md) | Requirements, data model, RBAC, and traceability documents |
| [`api/`](api) | Backend: Laravel 13 + Filament 5 + PostgreSQL 17/PostGIS + Sanctum + spatie/permission ([api/README.md](api/README.md)) |
| [`frontend/`](frontend) | Frontend: Vue 3 + Vite + Bootstrap 5 + Leaflet SPA |
| [`database/`](database) | Full PostgreSQL dump (`selgem.dump`, ~285k rows; refresh with `./sync-repo.sh`) |
| [`compare/`](compare) | Old vs new UI comparison screenshots and the narrated demo video (`demo-walkthrough- kssb.mp4`) |

## Quick start

```bash
# 1. Database (PostgreSQL 17 with PostGIS)
createdb selgem
psql selgem -c "CREATE EXTENSION IF NOT EXISTS postgis"
pg_restore --no-owner --no-acl -d selgem database/selgem.dump

# 2. Backend
cd api
composer install
cp .env.example .env && php artisan key:generate   # set DB_CONNECTION=pgsql, DB_DATABASE=selgem
php artisan serve --port=8008                     # admin: http://127.0.0.1:8008/admin

# 3. Frontend
cd ../frontend
npm install
npm run dev                                       # http://localhost:5178
```

Default login (local seed data): `admin@kssb.local` / `password`.

## Notes
- The raw JSON export of the legacy system (~1.3 GB) is not committed; the database dump contains the imported data.
- Scripts that talk to the legacy system (`api/database/sync_latest.py`, `frontend/capture.mjs`, etc.) read
  credentials from the environment: `LEGACY_USER`, `LEGACY_EMAIL`, `LEGACY_PASSWORD`.

## Deployment
See [DEPLOY.md](DEPLOY.md) for installing on a server (nginx + PHP-FPM + PostgreSQL/PostGIS), including https://smart-mining.sena.my.
