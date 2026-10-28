#!/bin/bash
set -euo pipefail
CL=/www/wwwroot/seo-ops-client
BASE=https://127.0.0.1
HOST=seo-ops.duckdns.org
CURL="curl -sk --resolve ${HOST}:443:127.0.0.1"

echo "=== unauth docs expect 401/302/401 json ==="
code=$($CURL -o /tmp/docs_body.txt -w "%{http_code}" "$BASE/api/v1/social-automation/docs" -H "Host: $HOST")
echo "docs_http=$code"
head -c 200 /tmp/docs_body.txt; echo

code=$($CURL -o /tmp/yaml_body.txt -w "%{http_code}" "$BASE/api/v1/social-automation/openapi.yaml" -H "Host: $HOST" -H "Accept: application/yaml")
echo "yaml_http=$code"
head -c 120 /tmp/yaml_body.txt; echo

echo "=== unauth rpa-admin session ==="
code=$($CURL -o /tmp/sess.txt -w "%{http_code}" "$BASE/api/v1/social-automation/rpa-admin/session" -H "Host: $HOST" -H "Accept: application/json")
echo "session_http=$code"
cat /tmp/sess.txt; echo

echo "=== login with bad creds ==="
code=$($CURL -o /tmp/login.txt -w "%{http_code}" -X POST "$BASE/api/v1/social-automation/rpa-admin/login" -H "Host: $HOST" -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"nobody@example.com","password":"wrong"}')
echo "login_bad_http=$code"
cat /tmp/login.txt; echo

echo "=== client validate without key ==="
code=$($CURL -o /tmp/client.txt -w "%{http_code}" "$BASE/api/v1/social-automation/client/validate" -H "Host: $HOST" -H "Accept: application/json")
echo "client_http=$code"
cat /tmp/client.txt; echo

echo "=== health public? ==="
code=$($CURL -o /tmp/health.txt -w "%{http_code}" "$BASE/api/social-automation/health" -H "Host: $HOST" -H "Accept: application/json")
echo "health_http=$code"
head -c 200 /tmp/health.txt; echo

echo "=== duplicate SA paths ==="
find /www/wwwroot -maxdepth 3 -type d -name social-automation 2>/dev/null
echo "=== social vs social-automation providers loaded ==="
sudo -u www php "$CL/artisan" about 2>&1 | head -5
# Count route names conflict
sudo -u www php "$CL/artisan" route:list --path=social-automation 2>&1 | wc -l
echo "=== laravel log tail ==="
tail -n 30 "$CL/storage/logs/laravel.log" 2>/dev/null | tail -20 || true
ls -lt "$CL/storage/logs" | head -5
echo SMOKE_DONE
