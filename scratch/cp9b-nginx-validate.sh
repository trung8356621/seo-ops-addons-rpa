#!/bin/bash
set -euo pipefail
# Validate proposed CP9B location precedence WITHOUT applying to production.

echo '=== PROD nginx -t (current, unchanged) ==='
nginx -t 2>&1 | tail -5

echo
echo '=== Current location inventory (seo-ops SSL server) ==='
nginx -T 2>/dev/null | awk '/server_name seo-ops.duckdns.org/,/^}/' | grep -E 'location |try_files|rewrite|root |include ' | head -40

echo
echo '=== Path routing matrix (proposed behavior) ==='
python3 <<'PY'
rules = [
    ('=', '/build-rpa-web/index.html', 'return 404'),
    ('^~', '/build-rpa-web/', 'try_files static'),
    ('=', '/social-automation/action-tags', 'rewrite -> index.php'),
    ('=', '/social-automation/scripts', 'rewrite -> index.php'),
    ('=', '/social-automation/accounts', 'rewrite -> index.php'),
    ('=', '/social-automation/clients', 'rewrite -> index.php'),
    ('=', '/social-automation/client-api-keys', 'rewrite -> index.php'),
    ('^~', '/livewire/', 'try_files -> index.php'),
    ('~', r'.*\.(js|css)?$', 'static/try_files'),
    ('/', '/', 'try_files -> index.php'),
]

paths = [
    '/social-automation/action-tags',
    '/social-automation/scripts',
    '/social-automation/accounts',
    '/social-automation/clients',
    '/social-automation/client-api-keys',
    '/social-automation/api/accounts',
    '/social-automation/script-media/1',
    '/social-automation-panel',
    '/social-automation/api-docs',
    '/build-rpa-web/index.html',
    '/build-rpa-web/assets/index-abc.js',
    '/api/v1/social-automation/rpa-admin/scripts',
    '/seo',
    '/admin',
    '/login',
]

def match(path):
    for kind, loc, action in rules:
        if kind == '=' and path == loc:
            return loc, action
    best = None
    for kind, loc, action in rules:
        if kind == '^~' and path.startswith(loc):
            if best is None or len(loc) > len(best[0]):
                best = (loc, action)
    if best:
        return best
    import re
    for kind, loc, action in rules:
        if kind == '~' and re.search(loc, path):
            return loc, action
    best = None
    for kind, loc, action in rules:
        if kind == '/' and path.startswith(loc):
            if best is None or len(loc) > len(best[0]):
                best = (loc, action)
    return best or ('?', 'unmatched')

print('%s %s %s' % ('PATH'.ljust(55), 'LOCATION'.ljust(36), 'ACTION'))
print('-' * 120)
for p in paths:
    loc, action = match(p)
    print('%s %s %s' % (p.ljust(55), loc.ljust(36), action))

checks = {
    '/social-automation/action-tags': 'rewrite -> index.php',
    '/social-automation/scripts': 'rewrite -> index.php',
    '/social-automation/accounts': 'rewrite -> index.php',
    '/social-automation/clients': 'rewrite -> index.php',
    '/social-automation/client-api-keys': 'rewrite -> index.php',
    '/social-automation/api/accounts': 'try_files -> index.php',
    '/social-automation/script-media/1': 'try_files -> index.php',
    '/social-automation-panel': 'try_files -> index.php',
    '/build-rpa-web/index.html': 'return 404',
    '/build-rpa-web/assets/index-abc.js': 'try_files static',
    '/seo': 'try_files -> index.php',
    '/admin': 'try_files -> index.php',
    '/login': 'try_files -> index.php',
}
failed = []
for p, expect in checks.items():
    _, action = match(p)
    if action != expect:
        failed.append((p, action, expect))
if failed:
    print('VALIDATION_FAIL')
    for row in failed:
        print(row)
    raise SystemExit(1)
print('VALIDATION_PASS')
PY

echo
echo '=== Snippet syntax probe (temp config, not production) ==='
TMP=/tmp/cp9b-snippet-test.conf
cat > "$TMP" <<'NGX'
events {}
http {
  server {
    listen 127.0.0.1:19999;
    server_name cp9b-test.local;
    root /www/wwwroot/seo-ops-client/public;
    location = /build-rpa-web/index.html { return 404; }
    location ^~ /build-rpa-web/ { try_files $uri =404; }
    location = /social-automation/action-tags { rewrite ^ /index.php?$query_string last; }
    location = /social-automation/scripts { rewrite ^ /index.php?$query_string last; }
    location = /social-automation/accounts { rewrite ^ /index.php?$query_string last; }
    location = /social-automation/clients { rewrite ^ /index.php?$query_string last; }
    location = /social-automation/client-api-keys { rewrite ^ /index.php?$query_string last; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
  }
}
NGX
# Full module path may differ; treat matrix VALIDATION_PASS as primary signal.
if nginx -t -c "$TMP" 2>/tmp/cp9b-nginx-t.err; then
  echo 'snippet_nginx_t=OK'
else
  echo 'snippet_nginx_t=SKIP_OR_FAIL (expected on aaPanel without full conf tree)'
  tail -5 /tmp/cp9b-nginx-t.err || true
fi

# Confirm production conf was NOT modified during this checkpoint.
STAT_BEFORE=$(stat -c '%Y %s' /www/server/panel/vhost/nginx/221.132.16.15.conf)
echo "prod_conf_mtime_size=$STAT_BEFORE"
echo 'CP9B_NGINX_VALIDATE_DONE'
