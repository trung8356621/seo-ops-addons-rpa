#!/bin/bash
mysql -N -e "SHOW TABLES FROM omi_social_automation;" 
echo '---missing check---'
for t in sa_client_api_keys sa_rpa_admin_sessions sa_action_tags sa_sites sa_paths sa_script_tombstones sa_client_notifications; do
  mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='omi_social_automation' AND table_name='$t';" | awk -v t="$t" '{print t":"$1}'
done
echo '---service db---'
mysql -N omi_client -e "SHOW TABLES LIKE '%service_database%';"
mysql -N omi_client -e "SELECT * FROM service_database_connections WHERE service_id=18 OR connection_name LIKE '%social%' LIMIT 20;" 2>/dev/null || true
grep -n SOCIAL_AUTOMATION /www/wwwroot/seo-ops-client/config/database.php | head
