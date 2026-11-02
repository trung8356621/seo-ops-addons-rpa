#!/bin/bash
set +e
CL=/www/wwwroot/seo-ops-client
SA=/www/wwwroot/seo-ops-addons/social-automation
echo "===HOSTNAME==="; hostname
echo "===SHA256==="
for f in \
  src/Support/SocialAutomationServiceHealth.php \
  src/Http/Controllers/SocialAutomationBootstrapController.php \
  openapi/openapi.yaml \
  tests/Unit/SocialAutomationBootstrapRoleTest.php
do
  if [ -f "$SA/$f" ]; then sha256sum "$SA/$f"; else echo "MISSING $f"; fi
done
echo "===PHP_LINT==="
/www/server/php/83/bin/php -l "$SA/src/Support/SocialAutomationServiceHealth.php"
/www/server/php/83/bin/php -l "$SA/src/Http/Controllers/SocialAutomationBootstrapController.php"
echo "===ROUTE==="
cd "$CL"
sudo -u www /www/server/php/83/bin/php artisan route:list --path=api/social-automation/bootstrap 2>&1 | head -30
echo "===HEALTH_SOURCE==="
sed -n '45,85p' "$SA/src/Support/SocialAutomationServiceHealth.php"
echo "===MTIME==="
stat -c 'mtime=%y size=%s' "$SA/src/Support/SocialAutomationServiceHealth.php"
echo "===PROVIDER_BOOTSTRAP_ROUTE==="
grep -n "bootstrap\|SocialAutomationBootstrap" "$SA/src/SocialAutomationServiceProvider.php" | head -30
echo "===LOG_SCAN==="
python3 <<'PY'
from pathlib import Path
import re
p = Path('/www/wwwroot/seo-ops-client/storage/logs/laravel.log')
size = p.stat().st_size
with p.open('rb') as f:
    f.seek(max(0, size - 3_000_000))
    data = f.read().decode('utf-8', 'replace')
secret = re.compile(
    r'(Authorization:\s*\S+|Bearer\s+\S+|Cookie:\s*[^\n]+|password[=:]\s*\S+|remember_token[=:]\s*\S+|XSRF-TOKEN[=:]\s*\S+)',
    re.I,
)
blocks = re.split(r'(?=\[\d{4}-\d{2}-\d{2} )', data)
keys = (
    '/api/social-automation/bootstrap',
    'SocialAutomationBootstrap',
    'SocialAutomationServiceHealth',
    'publicUser',
)
hits = [b for b in blocks if any(k in b for k in keys)]
# also catch recent ERROR near social-automation api
api_hits = [b for b in blocks if 'social-automation' in b and ('ERROR' in b or 'Exception' in b or 'local.ERROR' in b)]
print(f'window_bytes={len(data)} blocks={len(blocks)} bootstrap_hits={len(hits)} api_error_hits={len(api_hits)}')
shown = hits[-15:] if hits else api_hits[-10:]
for b in shown:
    clean = secret.sub('[REDACTED]', b)
    print('=====BLOCK=====')
    print('\n'.join(clean.splitlines()[:120]))
PY
echo "===WEBAPP_SCAN==="
python3 <<'PY'
from pathlib import Path
import re
p = Path('/www/wwwroot/seo-ops-client/storage/logs/web-app-2026-10-29.log')
if not p.exists():
    print('MISSING web-app log')
    raise SystemExit(0)
size = p.stat().st_size
with p.open('rb') as f:
    f.seek(max(0, size - 800_000))
    data = f.read().decode('utf-8', 'replace')
secret = re.compile(r'(Authorization:\s*\S+|Bearer\s+\S+|Cookie:\s*[^\n]+|password[=:]\s*\S+)', re.I)
blocks = re.split(r'(?=\[\d{4}-\d{2}-\d{2} )', data)
hits = [b for b in blocks if 'bootstrap' in b.lower() or ('social-automation' in b and ('ERROR' in b or 'Exception' in b))]
print(f'hits={len(hits)}')
for b in hits[-10:]:
    print('=====BLOCK=====')
    print(secret.sub('[REDACTED]', '\n'.join(b.splitlines()[:100])))
PY
echo "===NGINX_ERROR_TAIL==="
# common aaPanel paths; ignore missing
for f in /www/wwwlogs/seo-ops.duckdns.org.error.log /www/wwwlogs/seo-ops.duckdns.org-error.log /www/server/nginx/logs/error.log; do
  if [ -f "$f" ]; then
    echo "-- $f --"
    grep -i 'bootstrap\|social-automation' "$f" | tail -20
  fi
done
echo "===DONE==="
