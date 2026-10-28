#!/bin/bash
set -euo pipefail
CL=/www/wwwroot/seo-ops-client
SA=/www/wwwroot/seo-ops-addons/social-automation
echo "=== SA dir ==="
ls -la "$SA" || true
echo "=== composer SA ==="
grep SocialAutomation /www/wwwroot/seo-ops-addons/composer.json || true
echo "=== env keys ==="
grep -E '^(APP_URL|APP_ENV|SOCIAL_AUTOMATION|omi_social|ADDON_)' "$CL/.env" | cut -d= -f1 || true
echo "=== mysql dbs ==="
mysql -N -e 'SHOW DATABASES;' 2>/dev/null | head -40 || echo 'mysql_failed'
echo "=== services table social ==="
cd "$CL"
sudo -u www php artisan tinker --execute='echo json_encode(\Illuminate\Support\Facades\DB::table("services")->where("slug","like","%social%")->get(["id","slug","name","is_active"])->toArray());' 2>/dev/null || echo 'tinker_failed'
echo "=== db connections ==="
sudo -u www php artisan tinker --execute='echo implode(",", array_keys(config("database.connections")));' 2>/dev/null || true
