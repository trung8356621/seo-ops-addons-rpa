#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client
PHP=php
BACKUP=/tmp/sa-backup-$(date +%Y%m%d%H%M%S)
echo "=== backup empty/old SA dir ==="
mkdir -p "$BACKUP"
if [ -d "$SA" ]; then
  cp -a "$SA" "$BACKUP/social-automation" || true
fi
echo "BACKUP=$BACKUP"
echo "=== extract ==="
rm -rf "$SA"
mkdir -p "$SA"
tar -xzf /tmp/social-automation-deploy.tgz -C "$SA"
chown -R www:www "$SA"
echo "=== verify addon.json ==="
cat "$SA/addon.json"
test -f "$SA/openapi/openapi.yaml"
test -f "$SA/src/SocialAutomationServiceProvider.php"
echo "=== composer dump-autoload ==="
cd "$CL"
sudo -u www composer dump-autoload -o --no-interaction 2>&1 | tail -20
echo "=== clear caches ==="
sudo -u www $PHP artisan optimize:clear 2>&1 | tail -20
echo "=== migrate SA connection ==="
sudo -u www $PHP artisan migrate --database=omi_social_automation --path=addons/social-automation/database/migrations --force 2>&1 | tail -40
echo "=== activate service row ==="
mysql omi_client -e "UPDATE services SET is_active=1, updated_at=NOW() WHERE slug='social-automation'; SELECT id,slug,is_active,db_connection FROM services WHERE slug='social-automation';"
echo "=== route check ==="
sudo -u www $PHP artisan route:list --path=social-automation 2>&1 | head -80
echo "=== tables after migrate ==="
mysql -N -e "SHOW TABLES FROM omi_social_automation;" | wc -l
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='omi_social_automation' AND table_name IN ('sa_client_api_keys','sa_rpa_admin_sessions','sa_action_tags','sa_sites');"
echo "DEPLOY_DONE"
