#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client
cd "$CL"
sudo -u www php artisan optimize:clear 2>&1 | tail -15
echo "=== route api-docs ==="
sudo -u www php artisan route:list --name=social-automation.api-docs 2>&1 | head -10
sudo -u www php artisan route:list --name=social-automation.openapi 2>&1 | head -15

# Issue short-lived token
sudo -u www php /tmp/vps-auth-smoke.php
TOKEN=$(cat /tmp/sa-smoke-token.txt)
HOST=seo-ops.duckdns.org
BASE=https://127.0.0.1
CURL="curl -sk --resolve ${HOST}:443:127.0.0.1"

check() {
  local path="$1"
  local out="/tmp/chk-$(echo "$path" | tr '/' '_')"
  local code ctype
  code=$($CURL -D /tmp/hdrs.txt -o "$out" -w "%{http_code}" "$BASE$path" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
  ctype=$(grep -i '^content-type:' /tmp/hdrs.txt | tr -d '\r' | head -1)
  echo "PATH=$path STATUS=$code $ctype"
  if [[ "$path" == *docs && "$path" != *api-docs && "$path" != *openapi* ]]; then
    # swagger html
    head -c 80 "$out"; echo
  else
    python3 - <<PY
import json
p="$out"
with open(p,'rb') as f:
    raw=f.read()
# swagger ui is html
if b'<html' in raw[:200].lower():
    print('BODY=html')
else:
    d=json.loads(raw)
    keys=list(d.keys())[:8]
    print('keys',keys,'openapi',d.get('openapi'),'info.title', (d.get('info') or {}).get('title'),'paths', len(d.get('paths') or {}))
PY
  fi
}

echo "=== unauth api-docs ==="
code=$($CURL -o /tmp/u.json -w "%{http_code}" "$BASE/social-automation/api-docs" -H "Host: $HOST" -H "Accept: application/json")
echo "unauth_api_docs=$code"; cat /tmp/u.json; echo

echo "=== authed four endpoints ==="
check /social-automation/api-docs
check /api/v1/social-automation/openapi.json
check /api/v1/social-automation/openapi.yaml
# docs is html - separate
code=$($CURL -D /tmp/hdrs.txt -o /tmp/docs.html -w "%{http_code}" "$BASE/api/v1/social-automation/docs" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN")
ctype=$(grep -i '^content-type:' /tmp/hdrs.txt | tr -d '\r' | head -1)
echo "PATH=/api/v1/social-automation/docs STATUS=$code $ctype"
grep -o swagger-ui /tmp/docs.html | head -1

echo "=== json equality api-docs vs openapi.json ==="
python3 - <<'PY'
import json, hashlib
a=json.load(open('/tmp/chk-_social-automation_api-docs'))
b=json.load(open('/tmp/chk-_api_v1_social-automation_openapi.json'))
print('equal', a==b)
print('openapi', a.get('openapi'), 'paths', len(a.get('paths',{})))
PY

mysql omi_social_automation -e "UPDATE sa_rpa_admin_sessions SET revoked_at=NOW() WHERE client_label='vps-auth-smoke';"
rm -f /tmp/sa-smoke-token.txt
echo SYNC_VERIFY_DONE
