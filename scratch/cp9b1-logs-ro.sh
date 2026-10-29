#!/bin/bash
set -euo pipefail
CL=/www/wwwroot/seo-ops-client
echo "=== LOG FILES ==="
ls -lt "$CL/storage/logs" | head -12
TODAY=$(date +%Y-%m-%d)
echo
for f in "$CL/storage/logs/laravel-${TODAY}.log" "$CL/storage/logs/laravel.log"; do
  if [ -f "$f" ]; then
    echo "FILE=$f size=$(wc -c < "$f")"
    echo "-- last 30 lines --"
    tail -n 30 "$f"
    echo "-- SPA/error filter last 300 --"
    tail -n 300 "$f" | grep -iE 'RpaAdminSpa|build-rpa-web|social-automation/(action-tags|scripts|accounts|clients|client-api-keys)|ERROR|CRITICAL' | tail -50 || echo '(no matches)'
  else
    echo "MISSING $f"
  fi
  echo
done
echo "LOGS_DONE"
