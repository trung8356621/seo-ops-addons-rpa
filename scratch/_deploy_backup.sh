#!/bin/bash
set -euo pipefail
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_ROOT=/www/backup/rpa-owner-assign-client-keys/${STAMP}
SRC=/www/wwwroot/seo-ops-addons/social-automation
mkdir -p "$BACKUP_ROOT/addon-src" "$BACKUP_ROOT/db"

# Backup changed/related addon source tree slices
for rel in \
  src/Http/Controllers/RpaClientApiKeyManagementController.php \
  src/Services/RpaClientApiKeyService.php \
  src/Services/StaffClientEnrollmentService.php \
  src/Models/SaClientApiKey.php \
  src/Support/SocialAutomationAccess.php \
  src/SocialAutomationServiceProvider.php \
  openapi/openapi.yaml \
  database/migrations
do
  if [ -e "$SRC/$rel" ]; then
    mkdir -p "$BACKUP_ROOT/addon-src/$(dirname "$rel")"
    cp -a "$SRC/$rel" "$BACKUP_ROOT/addon-src/$rel"
  fi
done

# DB dump (structure + data for sa_client_api_keys; full DB dump for safety)
# Prefer mysqldump from existing backup tooling pattern
MYSQL_CNF=/www/server/mysql/my.cnf
if command -v mysqldump >/dev/null 2>&1; then
  # Use Laravel .env credentials via php helper written next to this script
  /www/server/php/83/bin/php /tmp/_deploy_dump_keys.php "$BACKUP_ROOT/db"
else
  echo "mysqldump missing" >&2
  exit 1
fi

# Verify backups
find "$BACKUP_ROOT" -type f | sort > "$BACKUP_ROOT/MANIFEST.txt"
KEY_SQL=$(ls "$BACKUP_ROOT/db"/sa_client_api_keys_*.sql 2>/dev/null | head -1 || true)
FULL_GZ=$(ls "$BACKUP_ROOT/db"/omi_social_automation_*.sql.gz 2>/dev/null | head -1 || true)
META=$(ls "$BACKUP_ROOT/db"/keys_meta_*.json 2>/dev/null | head -1 || true)

echo "BACKUP_ROOT=$BACKUP_ROOT"
echo "MANIFEST_LINES=$(wc -l < "$BACKUP_ROOT/MANIFEST.txt")"
if [ -n "$KEY_SQL" ] && [ -s "$KEY_SQL" ]; then echo "KEY_SQL_OK=$(basename "$KEY_SQL") bytes=$(wc -c < "$KEY_SQL")"; else echo "KEY_SQL_FAIL"; exit 2; fi
if [ -n "$FULL_GZ" ] && [ -s "$FULL_GZ" ]; then echo "FULL_GZ_OK=$(basename "$FULL_GZ") bytes=$(wc -c < "$FULL_GZ")"; else echo "FULL_GZ_FAIL"; exit 3; fi
if [ -n "$META" ] && [ -s "$META" ]; then echo "META_OK=$(basename "$META")"; else echo "META_FAIL"; exit 4; fi
echo "BACKUP_OK=yes"
