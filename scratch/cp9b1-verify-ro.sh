#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client
HOST=seo-ops.duckdns.org
CURL=(curl -sk --resolve "${HOST}:443:127.0.0.1")

echo "=== SYNC: controller ==="
stat "$SA/src/Http/Controllers/RpaAdmin/RpaAdminSpaController.php"
md5sum "$SA/src/Http/Controllers/RpaAdmin/RpaAdminSpaController.php"
test -f "$CL/addons/social-automation/src/Http/Controllers/RpaAdmin/RpaAdminSpaController.php" && echo junction_controller=OK

echo
echo "=== SYNC: provider SPA markers ==="
stat "$SA/src/SocialAutomationServiceProvider.php"
grep -n "RpaAdminSpaController\|social-automation.spa\.\|CP9B\|build-rpa-web\|client-api-keys" \
  "$SA/src/SocialAutomationServiceProvider.php" || true
echo "--- snippet ---"
sed -n '230,250p' "$SA/src/SocialAutomationServiceProvider.php"

echo
echo "=== ROUTES: spa ==="
sudo -u www php "$CL/artisan" route:list --name=social-automation.spa 2>&1

echo
echo "=== ROUTES: five paths ==="
for p in action-tags scripts accounts clients client-api-keys; do
  sudo -u www php "$CL/artisan" route:list --path="social-automation/${p}" 2>&1 | sed -n '1,8p'
  echo
done

echo
echo "=== API_REGRESSION ==="
for name in \
  social-automation.script-media.show \
  social-automation.accounts.index \
  social-automation.action-registry.data \
  social-automation.client-keys.index \
  social-automation.rpa-admin.scripts.catalog \
  social-automation.rpa-admin.action-tags \
  social-automation.api-docs \
  social-automation.client.validate
do
  out=$(sudo -u www php "$CL/artisan" route:list --name="$name" 2>&1 | grep -E 'GET|POST|PUT|DELETE' || true)
  if [ -n "$out" ]; then
    echo "OK  $name :: $out"
  else
    echo "MISS $name"
  fi
done
sudo -u www php "$CL/artisan" route:list --path=social-automation-panel 2>&1 | sed -n '1,12p'

echo
echo "=== ASSETS build-rpa-web ==="
if [ -e "$CL/public/build-rpa-web" ]; then
  echo "UNEXPECTED_PRESENT"
  ls -la "$CL/public/build-rpa-web" | head -20
else
  echo "ABSENT_OK"
fi
find "$CL/public" -maxdepth 2 -name 'build-rpa*' 2>/dev/null || true

echo
echo "=== NGINX unchanged ==="
stat /www/server/panel/vhost/nginx/221.132.16.15.conf
wc -c /www/server/panel/vhost/nginx/221.132.16.15.conf
md5sum /www/server/panel/vhost/nginx/221.132.16.15.conf
if grep -E 'build-rpa-web|CP9B|client-api-keys|social-automation/action-tags' \
  /www/server/panel/vhost/nginx/221.132.16.15.conf \
  /www/server/panel/vhost/rewrite/221.132.16.15.conf 2>/dev/null; then
  echo NGINX_HAS_CP9B_UNEXPECTED
else
  echo NGINX_NO_CP9B_OK
fi
nginx -t 2>&1 | tail -3

echo
echo "=== HTTP SPA (anonymous) ==="
for p in \
  /social-automation/action-tags \
  /social-automation/scripts \
  /social-automation/accounts \
  /social-automation/clients \
  /social-automation/client-api-keys
do
  code=$("${CURL[@]}" -o /tmp/spa_body.txt -w "%{http_code}" -D /tmp/spa_hdrs.txt \
    "https://127.0.0.1${p}" -H "Host: ${HOST}")
  loc=$(grep -i '^Location:' /tmp/spa_hdrs.txt | tr -d '\r' | head -1 || true)
  ctype=$(grep -i '^Content-Type:' /tmp/spa_hdrs.txt | tr -d '\r' | head -1 || true)
  echo "PATH=${p} STATUS=${code} ${ctype} ${loc}"
  head -c 180 /tmp/spa_body.txt; echo
done

echo
echo "=== HTTP missing-build via artisan tinker (no auth bypass of middleware) ==="
# Directly exercise controller static method on VPS public_path (no assets).
sudo -u www php "$CL/artisan" tinker --execute="
\$r = \\Omnichannel\\Addons\\SocialAutomation\\Http\\Controllers\\RpaAdmin\\RpaAdminSpaController::responseForIndex(public_path('build-rpa-web/index.html'));
echo 'status=' . \$r->getStatusCode() . PHP_EOL;
echo 'ctype=' . \$r->headers->get('Content-Type') . PHP_EOL;
echo 'cache=' . \$r->headers->get('Cache-Control') . PHP_EOL;
\$body = (string) \$r->getContent();
echo 'body_snip=' . substr(\$body, 0, 160) . PHP_EOL;
echo 'has_path=' . (str_contains(\$body, 'build-rpa-web') ? 'yes' : 'no') . PHP_EOL;
echo 'has_exception=' . (str_contains(\$body, 'Exception') ? 'yes' : 'no') . PHP_EOL;
echo 'has_unavailable=' . (str_contains(\$body, 'temporarily unavailable') ? 'yes' : 'no') . PHP_EOL;
" 2>&1

echo
echo "=== API smoke anonymous ==="
for p in \
  /social-automation/api/accounts \
  /social-automation/api/action-registry/data \
  /api/v1/social-automation/rpa-admin/scripts \
  /social-automation/api-docs
do
  code=$("${CURL[@]}" -o /tmp/api_body.txt -w "%{http_code}" \
    "https://127.0.0.1${p}" -H "Host: ${HOST}" -H "Accept: application/json")
  echo "PATH=${p} STATUS=${code}"
  head -c 140 /tmp/api_body.txt; echo
done

echo
echo "=== LARAVEL ERRORS (recent) ==="
ls -lt "$CL/storage/logs" | head -10
TODAY=$(date +%Y-%m-%d)
for f in "$CL/storage/logs/laravel-${TODAY}.log" "$CL/storage/logs/laravel.log"; do
  if [ -f "$f" ]; then
    echo "FILE=$f"
    # last 200 lines; filter SPA-related / errors after sync window
    tail -n 200 "$f" | grep -iE 'RpaAdminSpa|build-rpa-web|social-automation/(action-tags|scripts|accounts|clients|client-api-keys)|ERROR|CRITICAL|Exception' | tail -40 || echo '(no matching lines in last 200)'
  fi
done

echo
echo "CP9B1_VERIFY_DONE"
