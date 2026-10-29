#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
echo "===MACHINE==="
hostname
echo "===FILES==="
for f in \
  src/Http/Controllers/RpaAdmin/RpaAdminSpaController.php \
  src/SocialAutomationServiceProvider.php \
  tests/Unit/RpaAdminSpaMountTest.php
do
  echo "-- $f"
  if [ -f "$SA/$f" ]; then
    md5sum "$SA/$f"
    stat -c 'mtime=%y size=%s' "$SA/$f"
  else
    echo MISSING
  fi
done
echo "===SPA_MARKERS==="
grep -n "RpaAdminSpaController\|social-automation.spa\." "$SA/src/SocialAutomationServiceProvider.php" | head -40
echo "===RECENT_PHP==="
find "$SA" -type f -name '*.php' -mtime -2 -printf '%T+\t%s\t%p\n' 2>/dev/null | sort -r | head -30
