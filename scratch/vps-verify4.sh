#!/bin/bash
set -euo pipefail
HOST=seo-ops.duckdns.org
BASE=https://127.0.0.1
CURL=(curl -sk --resolve "${HOST}:443:127.0.0.1")
cd /www/wwwroot/seo-ops-client
sudo -u www php /tmp/vps-auth-smoke.php
TOKEN=$(cat /tmp/sa-smoke-token.txt)

"${CURL[@]}" -D /tmp/h1.txt -o /tmp/a.json "$BASE/social-automation/api-docs" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" >/dev/null
"${CURL[@]}" -D /tmp/h2.txt -o /tmp/b.json "$BASE/api/v1/social-automation/openapi.json" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" >/dev/null
"${CURL[@]}" -D /tmp/h3.txt -o /tmp/c.yaml "$BASE/api/v1/social-automation/openapi.yaml" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" >/dev/null
"${CURL[@]}" -D /tmp/h4.txt -o /tmp/d.html "$BASE/api/v1/social-automation/docs" -H "Host: $HOST" -H "Authorization: Bearer $TOKEN" >/dev/null

echo "1.api-docs $(grep -i ^HTTP/ /tmp/h1.txt | head -1 | tr -d '\r') $(grep -i ^content-type: /tmp/h1.txt | tr -d '\r')"
echo "2.openapi.json $(grep -i ^HTTP/ /tmp/h2.txt | head -1 | tr -d '\r') $(grep -i ^content-type: /tmp/h2.txt | tr -d '\r')"
echo "3.openapi.yaml $(grep -i ^HTTP/ /tmp/h3.txt | head -1 | tr -d '\r') $(grep -i ^content-type: /tmp/h3.txt | tr -d '\r')"
echo "4.docs $(grep -i ^HTTP/ /tmp/h4.txt | head -1 | tr -d '\r') $(grep -i ^content-type: /tmp/h4.txt | tr -d '\r')"
python3 - <<'PY'
import json
a=json.load(open("/tmp/a.json"))
b=json.load(open("/tmp/b.json"))
print("json_equal", a==b)
print("openapi", a["openapi"], "paths", len(a["paths"]), "info", a["info"]["title"])
print("yaml_starts", open("/tmp/c.yaml").read(30).replace("\n","\\n"))
print("docs_has_swagger", "swagger-ui" in open("/tmp/d.html").read())
PY
mysql omi_social_automation -e "UPDATE sa_rpa_admin_sessions SET revoked_at=NOW() WHERE client_label='vps-auth-smoke';"
rm -f /tmp/sa-smoke-token.txt
echo OK
