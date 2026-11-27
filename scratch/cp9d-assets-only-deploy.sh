#!/bin/bash
set -euo pipefail

# CP9D assets-only — deploy React build-rpa-web static files.
# No Nginx rewrite, no Laravel/PHP/DB changes, no credentials.

TS=$(date +%Y%m%d-%H%M%S)
CL=/www/wwwroot/seo-ops-client
PUBLIC="$CL/public"
DEST="$PUBLIC/build-rpa-web"
STAGE="/tmp/cp9d-stage-${TS}"
ZIP=/tmp/build-rpa-web.zip
BK="/root/backups/cp9d-assets-${TS}"
EXPECTED_SHA256="${EXPECTED_SHA256:?EXPECTED_SHA256 required}"
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
echo "=== 2. Backup existing assets ==="
mkdir -p "$BK"
if [ -e "$DEST" ]; then
  tar -C "$PUBLIC" -czf "$BK/build-rpa-web-pre.tar.gz" build-rpa-web
  echo "backed_up_existing_assets=yes"
  ls -la "$DEST" | head -20
  if [ -f "$DEST/index.html" ]; then
    echo "pre_index_sha=$(sha256sum "$DEST/index.html" | awk '{print $1}')"
    grep -oE '/build-rpa-web/assets/[^"]+\.(js|css)' "$DEST/index.html" | head -5 || true
  fi
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
if [ -f "$STAGE/extract/index.html" ]; then
  SRC="$STAGE/extract"
elif [ -f "$STAGE/extract/build-rpa-web/index.html" ]; then
  SRC="$STAGE/extract/build-rpa-web"
elif [ -f "$STAGE/extract/dist/index.html" ]; then
  SRC="$STAGE/extract/dist"
else
  echo "INDEX_HTML_MISSING_IN_ZIP"
  find "$STAGE/extract" -maxdepth 3 -type f | head -40
  exit 1
fi
test -f "$SRC/index.html"
test -d "$SRC/assets"
test -f "$SRC/favicon.svg"
grep -q '/build-rpa-web/assets/' "$SRC/index.html"
JS=$(grep -oE '/build-rpa-web/assets/[^"]+\.js' "$SRC/index.html" | head -1)
CSS=$(grep -oE '/build-rpa-web/assets/[^"]+\.css' "$SRC/index.html" | head -1)
echo "index_js=$JS"
echo "index_css=$CSS"
test -f "$SRC/assets/$(basename "$JS")"
test -f "$SRC/assets/$(basename "$CSS")"
FILE_COUNT=$(find "$SRC" -type f | wc -l)
echo "staged_file_count=$FILE_COUNT"
if find "$SRC" -type f \( -name '*.php' -o -name '.env*' -o -name '*.pem' \) | grep -q .; then
  echo "FORBIDDEN_FILE_IN_BUILD"
  exit 1
fi
# LIVE create/assign markers (Owner UI)
JSFILE="$SRC/assets/$(basename "$JS")"
grep -q 'assigned_to_user_id' "$JSFILE"
grep -q 'assignable-users' "$JSFILE"
grep -q '/social-automation/api/client-keys' "$JSFILE"
grep -q 'Create Client API Key' "$JSFILE"
echo "STAGE_OK src=$SRC live_markers=ok"

echo
echo "=== 4. Deploy assets (atomic replace) — no nginx changes ==="
STAGE_DEST="$STAGE/build-rpa-web"
rm -rf "$STAGE_DEST"
mkdir -p "$STAGE_DEST"
cp -a "$SRC/." "$STAGE_DEST/"
chown -R www:www "$STAGE_DEST"
find "$STAGE_DEST" -type d -exec chmod 755 {} \;
find "$STAGE_DEST" -type f -exec chmod 644 {} \;

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
echo "deployed_js_name=$(basename "$JS")"
echo "deployed_css_name=$(basename "$CSS")"

echo
echo "=== 5. Read-only HTTP verify ==="
echo "--- direct index.html (nginx may 404 protect) ---"
code=$("${CURL[@]}" -o /tmp/cp9d_idx.txt -w "%{http_code}" \
  "https://127.0.0.1/build-rpa-web/index.html" -H "Host: ${HOST}")
echo "PATH=/build-rpa-web/index.html STATUS=${code}"

echo "--- hashed assets ---"
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

echo "--- SPA shells (auth redirect expected for anonymous) ---"
for p in \
  /social-automation/action-tags \
  /social-automation/client-api-keys
do
  code=$("${CURL[@]}" -o /tmp/cp9d_body.txt -w "%{http_code}" -D /tmp/cp9d_hdrs.txt \
    "https://127.0.0.1${p}" -H "Host: ${HOST}")
  loc=$(grep -i '^Location:' /tmp/cp9d_hdrs.txt | tr -d '\r' | head -1 || true)
  echo "PATH=${p} STATUS=${code} ${loc}"
done

echo "--- bundle marker probe on disk ---"
grep -c 'assigned_to_user_id' "$DEST/assets/$(basename "$JS")" || true
grep -c 'assignable-users' "$DEST/assets/$(basename "$JS")" || true
grep -c 'MOCK_ONLY' "$DEST/assets/$(basename "$JS")" || true
grep -c 'Create Client API Key' "$DEST/assets/$(basename "$JS")" || true
grep -c 'LIVE MODE' "$DEST/assets/$(basename "$JS")" || true

echo
echo "CP9D_ASSETS_ONLY_DONE backup=$BK js=$(basename "$JS") css=$(basename "$CSS")"
