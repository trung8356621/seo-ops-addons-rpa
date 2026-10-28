#!/bin/bash
set -euo pipefail
cd /www/wwwroot/seo-ops-client
sudo -u www php /tmp/vps-auth-smoke.php
TOKEN=$(cat /tmp/sa-smoke-token.txt)
HOST=seo-ops.duckdns.org
BASE=https://127.0.0.1
CURL="curl -sk --resolve ${HOST}:443:127.0.0.1"

echo "=== authed openapi.yaml ==="
code=$($CURL -o /tmp/oa.yaml -w "%{http_code}" "$BASE/api/v1/social-automation/openapi.yaml" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN")
echo "yaml_auth_http=$code"
head -n 5 /tmp/oa.yaml

echo "=== authed docs html ==="
code=$($CURL -o /tmp/docs.html -w "%{http_code}" "$BASE/api/v1/social-automation/docs" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN")
echo "docs_auth_http=$code"
grep -o 'swagger-ui' /tmp/docs.html | head -1

echo "=== authed session ==="
code=$($CURL -o /tmp/sess2.json -w "%{http_code}" "$BASE/api/v1/social-automation/rpa-admin/session" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
echo "session_auth_http=$code"
# redact email
python3 - <<'PY'
import json
d=json.load(open('/tmp/sess2.json'))
if isinstance(d.get('user'), dict) and 'email' in d['user']:
    d['user']['email']='[redacted]'
print(json.dumps(d))
PY

echo "=== action-tags ==="
code=$($CURL -o /tmp/tags.json -w "%{http_code}" "$BASE/api/v1/social-automation/rpa-admin/action-tags" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
echo "tags_http=$code"
head -c 300 /tmp/tags.json; echo

echo "=== scripts catalog ==="
code=$($CURL -o /tmp/scripts.json -w "%{http_code}" "$BASE/api/v1/social-automation/rpa-admin/scripts" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
echo "scripts_http=$code"
head -c 300 /tmp/scripts.json; echo

echo "=== revoke smoke sessions ==="
mysql omi_social_automation -e "UPDATE sa_rpa_admin_sessions SET revoked_at=NOW() WHERE client_label='vps-auth-smoke';"
rm -f /tmp/sa-smoke-token.txt
echo AUTH_SMOKE_DONE
