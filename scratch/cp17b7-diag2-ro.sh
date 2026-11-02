#!/bin/bash
set +e
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client
echo "===EMPTY_FILE_CONFIRM==="
wc -c "$SA/src/Support/SocialAutomationServiceHealth.php"
ls -la "$SA/src/Support/SocialAutomationServiceHealth.php"
xxd "$SA/src/Support/SocialAutomationServiceHealth.php" | head -5
echo "===AUTLOAD_CLASSMAP==="
grep -n "SocialAutomationServiceHealth" "$CL/vendor/composer/autoload_classmap.php" 2>/dev/null | head -5
grep -n "SocialAutomationServiceHealth" "$CL/vendor/composer/autoload_psr4.php" 2>/dev/null | head -5
# addon path via composer
ls -la /www/wwwroot/seo-ops-addons/composer.json 2>/dev/null | head -2
php -r '
require "/www/wwwroot/seo-ops-client/vendor/autoload.php";
$ok = class_exists("Omnichannel\\Addons\\SocialAutomation\\Support\\SocialAutomationServiceHealth", true);
echo "class_exists=" . ($ok ? "1" : "0") . PHP_EOL;
$rf = new ReflectionClass("Composer\\Autoload\\ClassLoader");
' 2>&1 | head -20
/www/server/php/83/bin/php -r '
require "/www/wwwroot/seo-ops-client/vendor/autoload.php";
echo "class_exists=".(class_exists("Omnichannel\\Addons\\SocialAutomation\\Support\\SocialAutomationServiceHealth", true)?"1":"0").PHP_EOL;
$f = (new ReflectionClass("Omnichannel\\Addons\\SocialAutomation\\Http\\Controllers\\SocialAutomationBootstrapController"))->getFileName();
echo "bootstrap_ctrl=$f\n";
' 2>&1 | head -30
echo "===RECENT_WEBAPP_ERRORS==="
python3 <<'PY'
from pathlib import Path
import re
p = Path('/www/wwwroot/seo-ops-client/storage/logs/web-app-2026-10-29.log')
size = p.stat().st_size
with p.open('rb') as f:
    f.seek(max(0, size - 200_000))
    data = f.read().decode('utf-8', 'replace')
secret = re.compile(r'(Authorization:\s*\S+|Bearer\s+\S+|Cookie:\s*[^\n]+|password[=:]\s*\S+)', re.I)
# last lines with ERROR and bootstrap
lines = data.splitlines()
err = [ln for ln in lines if 'bootstrap' in ln.lower() or 'SocialAutomationServiceHealth' in ln]
print(f'recent_match_lines={len(err)}')
for ln in err[-15:]:
    print(secret.sub('[REDACTED]', ln)[:500])
print('===LAST_5_ERRORS_ANY===')
errs = [ln for ln in lines if 'production.ERROR' in ln or '.ERROR:' in ln]
for ln in errs[-8:]:
    print(secret.sub('[REDACTED]', ln)[:500])
PY
echo "===SUPPORT_DIR==="
ls -la "$SA/src/Support" | head -40
echo "===DONE==="
