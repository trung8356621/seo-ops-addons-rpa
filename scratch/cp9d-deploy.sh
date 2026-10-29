#!/bin/bash
set -euo pipefail

# CP9D — deploy React build-rpa-web + Nginx protection (production)
# No Laravel code changes. No DB migrations. No CRUD.

TS=$(date +%Y%m%d-%H%M%S)
CL=/www/wwwroot/seo-ops-client
PUBLIC="$CL/public"
DEST="$PUBLIC/build-rpa-web"
STAGE="/tmp/cp9d-stage-${TS}"
ZIP=/tmp/build-rpa-web.zip
BK="/root/backups/cp9d-${TS}"
NGX_VHOST=/www/server/panel/vhost/nginx/221.132.16.15.conf
NGX_REWRITE=/www/server/panel/vhost/rewrite/221.132.16.15.conf
NGX_EXT_DIR=/www/server/panel/vhost/nginx/extension/221.132.16.15
NGX_SNIPPET="$NGX_EXT_DIR/cp9d-rpa-web.conf"
EXPECTED_SHA256="${EXPECTED_SHA256:-2D1F4E4E4B752953E735FB9D095829964F11166898B9EB4396A8B0D5B21EAE85}"
HOST=seo-ops.duckdns.org
CURL=(curl -sk --resolve "${HOST}:443:127.0.0.1")

echo "=== 1. ZIP integrity ==="
test -f "$ZIP"
ACTUAL=$(sha256sum "$ZIP" | awk '{print toupper($1)}')
echo "zip_sha256=$ACTUAL"
echo "expected_sha256=$EXPECTED_SHA256"
if [ "$ACTUAL" != "$EXPECTED_SHA256" ]; then
  echo "SHA256_MISMATCH"
  exit 1
fi
echo "SHA256_OK"

echo
echo "=== 2. Backup Nginx + existing assets ==="
mkdir -p "$BK"
cp -a "$NGX_VHOST" "$BK/221.132.16.15.conf"
cp -a "$NGX_REWRITE" "$BK/221.132.16.15.rewrite.conf"
mkdir -p "$BK/extension"
cp -a "$NGX_EXT_DIR/." "$BK/extension/" 2>/dev/null || true
if [ -e "$DEST" ]; then
  tar -C "$PUBLIC" -czf "$BK/build-rpa-web-pre.tar.gz" build-rpa-web
  echo "backed_up_existing_assets=yes"
else
  echo "backed_up_existing_assets=none"
fi
echo "backup_dir=$BK"
ls -la "$BK"

echo
echo "=== 3. Stage + verify ZIP contents ==="
rm -rf "$STAGE"
mkdir -p "$STAGE"
unzip -q "$ZIP" -d "$STAGE/extract"
# Accept either flat contents or nested build-rpa-web/
if [ -f "$STAGE/extract/index.html" ]; then
  SRC="$STAGE/extract"
elif [ -f "$STAGE/extract/build-rpa-web/index.html" ]; then
  SRC="$STAGE/extract/build-rpa-web"
else
  echo "INDEX_HTML_MISSING_IN_ZIP"
  find "$STAGE/extract" -maxdepth 3 -type f | head -40
  exit 1
fi
test -f "$SRC/index.html"
test -d "$SRC/assets"
test -f "$SRC/favicon.svg"
# Must reference /build-rpa-web/ asset prefix
grep -q '/build-rpa-web/assets/' "$SRC/index.html"
JS=$(grep -oE '/build-rpa-web/assets/[^"]+\.js' "$SRC/index.html" | head -1)
CSS=$(grep -oE '/build-rpa-web/assets/[^"]+\.css' "$SRC/index.html" | head -1)
echo "index_js=$JS"
echo "index_css=$CSS"
test -f "$SRC${JS#/build-rpa-web}" || test -f "$SRC/assets/$(basename "$JS")"
test -f "$SRC/assets/$(basename "$JS")"
test -f "$SRC/assets/$(basename "$CSS")"
# Count files
FILE_COUNT=$(find "$SRC" -type f | wc -l)
echo "staged_file_count=$FILE_COUNT"
# Forbidden: no .env / php / secrets
if find "$SRC" -type f \( -name '*.php' -o -name '.env*' -o -name '*.pem' \) | grep -q .; then
  echo "FORBIDDEN_FILE_IN_BUILD"
  exit 1
fi
echo "STAGE_OK src=$SRC"

echo
echo "=== 4. Write Nginx protection snippet (extension) ==="
cat > "$NGX_SNIPPET" <<'NGX'
# CP9D — RPA Admin React static + SPA shell protection
# Direct index.html denied; hashed assets public; five UI shells → Laravel.

location = /build-rpa-web/index.html {
    return 404;
}

location ^~ /build-rpa-web/ {
    try_files $uri =404;
    expires 7d;
    add_header Cache-Control "public";
    access_log off;
}

location = /social-automation/action-tags {
    rewrite ^ /index.php?$query_string last;
}
location = /social-automation/scripts {
    rewrite ^ /index.php?$query_string last;
}
location = /social-automation/accounts {
    rewrite ^ /index.php?$query_string last;
}
location = /social-automation/clients {
    rewrite ^ /index.php?$query_string last;
}
location = /social-automation/client-api-keys {
    rewrite ^ /index.php?$query_string last;
}
NGX
cp -a "$NGX_SNIPPET" "$BK/cp9d-rpa-web.conf"
echo "snippet=$NGX_SNIPPET"

echo
echo "=== 5. nginx -t then reload ==="
nginx -t
nginx -s reload
echo "NGINX_RELOAD_OK"

echo
echo "=== 6. Deploy assets (atomic replace) ==="
STAGE_DEST="$STAGE/build-rpa-web"
rm -rf "$STAGE_DEST"
mkdir -p "$STAGE_DEST"
cp -a "$SRC/." "$STAGE_DEST/"
chown -R www:www "$STAGE_DEST"
find "$STAGE_DEST" -type d -exec chmod 755 {} \;
find "$STAGE_DEST" -type f -exec chmod 644 {} \;

# Atomic swap
if [ -e "$DEST" ]; then
  rm -rf "${DEST}.old"
  mv "$DEST" "${DEST}.old"
fi
mv "$STAGE_DEST" "$DEST"
rm -rf "${DEST}.old"
chown -R www:www "$DEST"
echo "deployed=$DEST"
ls -la "$DEST"
ls -la "$DEST/assets" | head -20
echo "deployed_index_sha=$(sha256sum "$DEST/index.html" | awk '{print $1}')"
echo "deployed_js_sha=$(sha256sum "$DEST/assets/$(basename "$JS")" | awk '{print $1}')"

echo
echo "=== 7. Route + HTTP verify ==="
sudo -u www php "$CL/artisan" route:list --name=social-automation.spa 2>&1 | sed -n '1,20p'

echo "--- anonymous SPA shells ---"
for p in \
  /social-automation/action-tags \
  /social-automation/scripts \
  /social-automation/accounts \
  /social-automation/clients \
  /social-automation/client-api-keys
do
  code=$("${CURL[@]}" -o /tmp/cp9d_body.txt -w "%{http_code}" -D /tmp/cp9d_hdrs.txt \
    "https://127.0.0.1${p}" -H "Host: ${HOST}")
  loc=$(grep -i '^Location:' /tmp/cp9d_hdrs.txt | tr -d '\r' | head -1 || true)
  echo "PATH=${p} STATUS=${code} ${loc}"
done

echo "--- direct index.html protected ---"
code=$("${CURL[@]}" -o /tmp/cp9d_idx.txt -w "%{http_code}" \
  "https://127.0.0.1/build-rpa-web/index.html" -H "Host: ${HOST}")
echo "PATH=/build-rpa-web/index.html STATUS=${code}"

echo "--- static assets ---"
for rel in \
  "assets/$(basename "$JS")" \
  "assets/$(basename "$CSS")" \
  "favicon.svg"
do
  code=$("${CURL[@]}" -o "/tmp/cp9d_asset.bin" -w "%{http_code}" -D /tmp/cp9d_ah.txt \
    "https://127.0.0.1/build-rpa-web/${rel}" -H "Host: ${HOST}")
  ctype=$(grep -i '^Content-Type:' /tmp/cp9d_ah.txt | tr -d '\r' | head -1 || true)
  bytes=$(wc -c < /tmp/cp9d_asset.bin)
  echo "ASSET=/build-rpa-web/${rel} STATUS=${code} BYTES=${bytes} ${ctype}"
done

echo
echo "CP9D_DEPLOY_CORE_DONE backup=$BK"
