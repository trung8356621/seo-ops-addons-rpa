#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client
cd "$CL"
echo "=== composer dump-autoload --no-scripts ==="
sudo -u www composer dump-autoload -o --no-scripts --no-interaction 2>&1 | tail -15
echo "=== clear caches ==="
sudo -u www php artisan optimize:clear 2>&1 | tail -25
echo "=== migrate ==="
sudo -u www php artisan migrate --database=omi_social_automation --path=addons/social-automation/database/migrations --force 2>&1 | tail -60
echo "=== activate ==="
mysql omi_client -e "UPDATE services SET is_active=1, updated_at=NOW() WHERE slug='social-automation'; SELECT id,slug,is_active,db_connection FROM services WHERE slug IN ('social','social-automation');"
echo "=== routes sample ==="
sudo -u www php artisan route:list --path=api/v1/social-automation 2>&1 | head -60
echo "=== critical tables ==="
for t in sa_client_api_keys sa_rpa_admin_sessions sa_action_tags sa_sites sa_paths sa_script_tombstones; do
  c=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='omi_social_automation' AND table_name='$t'")
  echo "$t=$c"
done
echo "=== provider class ==="
sudo -u www php -r 'require "vendor/autoload.php"; echo class_exists("Omnichannel\\Addons\\SocialAutomation\\SocialAutomationServiceProvider") ? "PROVIDER_OK\n" : "PROVIDER_MISSING\n";'
echo "DONE2"
