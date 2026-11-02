#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client

echo "===SHA256==="
for f in \
  src/Domain/OnlineStateCalculator.php \
  src/Registry/SessionSyncService.php \
  src/Http/Controllers/SocialAutomationBrowserInventoryController.php \
  tests/Unit/StaffBrowserInventoryContractTest.php
do
  if [ -f "$SA/$f" ]; then
    sha256sum "$SA/$f"
  else
    echo "MISSING $f"
  fi
done

echo
echo "===ROUTE==="
sudo -u www php "$CL/artisan" route:list --name=social-automation.browsers 2>&1 || true

echo
echo "===PROVIDER_MARKERS==="
grep -n "api/browsers\|BrowserInventory\|script-registry\|StaffScriptRegistry" \
  "$SA/src/SocialAutomationServiceProvider.php" | head -30 || true

echo
echo "===CONTROLLER_MARKERS==="
grep -n "staffBrowserInventory\|assertCanAccess" \
  "$SA/src/Http/Controllers/SocialAutomationBrowserInventoryController.php" 2>/dev/null || echo CONTROLLER_MISSING

echo
echo "===SERVICE_MARKERS==="
grep -n "function staffBrowserInventory\|function ttlSeconds\|assembleStaffBrowserInventoryResponse\|mapStaffBrowserRow" \
  "$SA/src/Registry/SessionSyncService.php" \
  "$SA/src/Domain/OnlineStateCalculator.php" | head -30
