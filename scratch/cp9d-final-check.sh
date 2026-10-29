#!/bin/bash
set -euo pipefail
HOST=seo-ops.duckdns.org
CURL=(curl -sk --resolve "${HOST}:443:127.0.0.1")

echo "=== static + protection ==="
for u in \
  /build-rpa-web/index.html \
  /build-rpa-web/assets/index-OEOE0sHf.js \
  /build-rpa-web/assets/index-WlJ7lH4E.css \
  /build-rpa-web/favicon.svg \
  /build-rpa-web/.vite/manifest.json
do
  code=$("${CURL[@]}" -o /tmp/out.bin -w "%{http_code}" -D /tmp/h.txt "https://127.0.0.1${u}" -H "Host: ${HOST}")
  ctype=$(grep -i '^Content-Type:' /tmp/h.txt | tr -d '\r' | head -1 || true)
  bytes=$(wc -c < /tmp/out.bin)
  echo "URL=${u} STATUS=${code} BYTES=${bytes} ${ctype}"
done

echo
echo "=== anonymous SPA ==="
for p in action-tags scripts accounts clients client-api-keys; do
  code=$("${CURL[@]}" -o /tmp/b.txt -w "%{http_code}" -D /tmp/h.txt \
    "https://127.0.0.1/social-automation/${p}" -H "Host: ${HOST}")
  loc=$(grep -i '^Location:' /tmp/h.txt | tr -d '\r' | head -1 || true)
  echo "PATH=/social-automation/${p} STATUS=${code} ${loc}"
done

echo
echo "=== nginx fingerprint ==="
md5sum /www/server/panel/vhost/nginx/221.132.16.15.conf
ls -la /www/server/panel/vhost/nginx/extension/221.132.16.15/
nginx -t 2>&1 | tail -2
echo "backup=$(ls -d /root/backups/cp9d-* | tail -1)"
echo "CP9D_FINAL_OK"
