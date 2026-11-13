#!/usr/bin/env bash
# Refresh this repo from the working folders (~/selgem-api, ~/selgem-frontend) + fresh DB dump.
# Scrubs live legacy-system credentials so the repo is safe to publish.
set -euo pipefail
R="$(cd "$(dirname "$0")" && pwd)"
cd "$R"

rsync -a --delete --exclude vendor --exclude node_modules --exclude .env --exclude 'storage/logs/*' \
  --exclude 'storage/framework/*/*' --exclude database.sqlite --exclude .phpunit.result.cache \
  ~/selgem-api/ "$R/api/"
rsync -a --delete --exclude node_modules --exclude dist --exclude test-results --exclude .vite \
  ~/selgem-frontend/ "$R/frontend/"

# scrub live legacy credentials (real system login must never be committed)
perl -pi -e "s/fill\('azlina\@semesta\.com\.my'\)/fill(process.env.LEGACY_EMAIL)/g;
             s/(inputs\[0\]\.|em\.)fill\('azlina'\)/\$1fill(process.env.LEGACY_USER)/g;
             s/fill\('azlina'\)/fill(process.env.LEGACY_PASSWORD)/g" \
  frontend/login_fix.mjs frontend/capture.mjs frontend/cap_more.mjs
perl -pi -e 's/\{"identifier":"azlina","password":"azlina"\}/{"identifier":os.environ["LEGACY_USER"],"password":os.environ["LEGACY_PASSWORD"]}/' \
  api/database/sync_latest.py
perl -pi -e "s/azlina\@semesta\.com\.my/admin\@selgem.local/g" frontend/src/App.vue frontend/src/pages/Account.vue

if grep -rnE "fill\('azlina'|\"password\":\"azlina\"" api frontend --exclude-dir=public; then
  echo "ERROR: legacy credentials still present" >&2; exit 1
fi

mkdir -p database
rm -f database/smart_mining.dump
pg_dump -h 127.0.0.1 -Fc -Z 9 --no-owner --no-acl \
  --exclude-table-data=personal_access_tokens --exclude-table-data=sessions \
  --exclude-table-data=password_reset_tokens --exclude-table-data=cache \
  selgem -f database/selgem.dump
ls -lh database/selgem.dump
