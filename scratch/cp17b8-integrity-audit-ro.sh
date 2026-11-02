#!/bin/bash
# CP17B.8 Phase C — read-only remote inventory for RPA sync scope
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
cd "$SA"

echo "===ZERO_BYTE_ALL==="
# any empty regular files (not dirs)
find . -type f -size 0 \
  ! -path './.git/*' \
  ! -path './node_modules/*' \
  ! -path './vendor/*' \
  ! -path './storage/*' \
  ! -path './scratch/*' \
  2>/dev/null | sort

echo "===ZERO_BYTE_PHP==="
find . -type f -name '*.php' -size 0 \
  ! -path './.git/*' \
  ! -path './vendor/*' \
  ! -path './scratch/*' \
  2>/dev/null | sort

echo "===MANIFEST_SHA256==="
# relative paths + size + sha256 for sync-relevant trees
find . -type f \
  ! -path './.git/*' \
  ! -path './node_modules/*' \
  ! -path './vendor/*' \
  ! -path './storage/*' \
  ! -path './scratch/*' \
  ! -path './.phpunit*' \
  ! -name '*.log' \
  ! -name '.env' \
  ! -name '.env.*' \
  2>/dev/null | sort | while IFS= read -r f; do
  rel=${f#./}
  sz=$(wc -c < "$f" | tr -d ' ')
  hash=$(sha256sum "$f" | cut -d' ' -f1)
  printf '%s\t%s\t%s\n' "$rel" "$sz" "$hash"
done
