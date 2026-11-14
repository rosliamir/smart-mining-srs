# Deploying to https://smart-mining.sena.my

This repo contains two apps that run behind one domain:

```
https://smart-mining.sena.my/            → Vue SPA (frontend/dist, static files)
https://smart-mining.sena.my/api/...     → Laravel API         (api/public/index.php)
https://smart-mining.sena.my/admin       → Filament admin panel (same Laravel app)
https://smart-mining.sena.my/storage/... → uploaded selfies     (api/storage/app/public)
                                            PostgreSQL 17 + PostGIS
```

> **Current server state (checked 14 Nov 2026):** the domain serves an older static build whose API URL is
> `http://localhost:8008/api`, so visitors' browsers cannot reach an API and the app falls back to the bundled mock
> JSON. `/api/*` currently returns the SPA's `index.html`. Deploying as below replaces it with the full system on live data.

---

## 1. Server requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.3+ | extensions: `pdo_pgsql pgsql intl zip mbstring bcmath gd exif curl xml` |
| Composer | 2.x | |
| Node.js | 20+ | only to build the SPA (can build on CI / a laptop and upload `frontend/dist`) |
| PostgreSQL | 17 | with **PostGIS 3.4+** (`CREATE EXTENSION postgis` needs a superuser once) |
| nginx + PHP-FPM | any recent | sample config: [`deploy/nginx-smart-mining.sena.my.conf`](deploy/nginx-smart-mining.sena.my.conf) |
| HTTPS | required | browsers only allow the **camera (selfie) and GPS** on HTTPS |

## 2. Get the code

```bash
sudo mkdir -p /var/www/selgem && sudo chown $USER /var/www/selgem
git clone https://github.com/rosliamir/selgem.git /var/www/selgem
cd /var/www/selgem
```

## 3. Database

```bash
sudo -u postgres createuser --pwprompt selgem
sudo -u postgres createdb -O selgem selgem
sudo -u postgres psql -d selgem -c "CREATE EXTENSION IF NOT EXISTS postgis;"
# restore the full data snapshot (sites, F001 reports, sales, attendance, knowledge base, staff accounts, …)
pg_restore --no-owner --no-acl -h 127.0.0.1 -U selgem -d selgem database/selgem.dump
```

`pg_restore` may print a warning that the `postgis` extension already exists — that is harmless.

## 4. API (Laravel)

```bash
cd /var/www/selgem/api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `api/.env`:

```dotenv
APP_NAME="Smart Monitoring System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://smart-mining.sena.my        # used for selfie URLs
APP_TIMEZONE=Asia/Kuala_Lumpur

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=selgem
DB_USERNAME=selgem
DB_PASSWORD=********

SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local

# Same domain as the SPA → CORS not needed. Only set this if the SPA is hosted elsewhere.
CORS_ALLOWED_ORIGINS=https://smart-mining.sena.my

# Optional — AI answers for the "Ask" assistant. Leave empty to answer from knowledge-base search.
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-5-5
```

Then:

```bash
php artisan migrate --force          # no-op on a fresh restore; applies new migrations on updates
php artisan storage:link             # /storage → storage/app/public (selfies)
php artisan filament:assets
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 5. Frontend (Vue SPA)

`frontend/.env.production` already points the SPA at `/api` on the same domain.

```bash
cd /var/www/selgem/frontend
npm ci
npm run build                        # → frontend/dist
```

## 6. nginx

```bash
sudo cp /var/www/selgem/deploy/nginx-smart-mining.sena.my.conf /etc/nginx/sites-available/smart-mining.sena.my
# adjust ssl_certificate paths and the php-fpm socket, then:
sudo ln -sf /etc/nginx/sites-available/smart-mining.sena.my /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 7. Secure the accounts — do this before announcing the URL

The snapshot contains **demo credentials**:

| Account | Default password |
|---|---|
| `admin@kssb.local` (super admin) | `password` |
| 91 staff accounts (username = staff code, e.g. `KS0294`) | `password` |

```bash
cd /var/www/selgem/api
# 1) random password for every staff account, written to a CSV for HR to hand out
php artisan ess:reset-passwords --export=storage/app/staff-passwords.csv
# 2) change the admin password
php artisan tinker --execute="App\Models\User::where('email','admin@kssb.local')->first()->forceFill(['password'=>Hash::make('NEW-STRONG-PASSWORD')])->save();"
```

Give the CSV to HR, then delete it from the server. Staff can change their password under **ESS → Profil & Akaun**.

## 8. Smoke test

| Check | Expected |
|---|---|
| `curl -s -X POST https://smart-mining.sena.my/api/login -H 'Accept: application/json' -d 'email=x&password=y'` | `401 {"message":"Invalid credentials"}` (not HTML) |
| Open `/` and sign in as admin | Map shows 77 sites; header shows **Live · PostgreSQL** on the Dashboard |
| Open `/admin` | Filament login page |
| Sign in with a staff code | Lands on `/ess`; **Clock In** asks for camera + location |
| HR account → ESS → Pemantauan Kehadiran → Geofence | HQ / PSU / PSS offices shown on the map |

## 9. Optional: pull fresh data from the legacy system

**Sync Data** in the admin menu runs `api/database/sync_latest.py`, which logs in to the legacy Strapi API. Provide its
credentials as environment variables of the PHP-FPM pool (never commit them):

```
LEGACY_USER=...
LEGACY_PASSWORD=...
```

The sync replaces legacy attendance rows only; clock-ins made through the self-service portal are kept.

## 10. Updating later

```bash
cd /var/www/selgem && git pull
cd api && composer install --no-dev --optimize-autoloader && php artisan migrate --force \
  && php artisan config:cache && php artisan route:cache && php artisan view:cache
cd ../frontend && npm ci && npm run build
sudo systemctl reload php8.3-fpm nginx
```

Do **not** re-run `pg_restore` on update — it would overwrite live data (leave requests, clock-ins, memos).

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| App works but shows old/mock data | SPA built without `.env.production` → rebuild with `npm run build` |
| `/api/...` returns HTML | nginx `location` for `/api` missing; check the config in step 6 |
| Selfie / GPS button does nothing on phones | site not on HTTPS, or `Permissions-Policy` header blocks camera/geolocation |
| Selfie images 404 | `php artisan storage:link` not run, or `/storage/` location missing |
| Clock-in always "di luar kawasan" | office coordinates — adjust on ESS → Pemantauan Kehadiran → Geofence (HR) |
| Times off by 8 hours | `APP_TIMEZONE=Asia/Kuala_Lumpur` missing, then `php artisan config:cache` |
