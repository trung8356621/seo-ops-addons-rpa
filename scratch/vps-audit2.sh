#!/bin/bash
CL=/www/wwwroot/seo-ops-client
grep SOCIAL "$CL/.env" || echo NO_SOCIAL_ENV
mysql -N -e "SHOW TABLES FROM omi_social_automation;" 2>/dev/null | head -50
mysql -N omi_client -e "SELECT id,slug,name,is_active FROM services WHERE slug LIKE '%social%';" 2>/dev/null
mysql -N omi_client -e "SELECT service_slug,connection_name,database_name FROM service_database_connections WHERE service_slug LIKE '%social%' OR connection_name LIKE '%social%';" 2>/dev/null
grep -A20 omi_social_automation "$CL/config/database.php" | head -25
