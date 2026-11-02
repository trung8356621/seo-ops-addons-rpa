#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client

echo "===SHA256==="
sha256sum \
  "$SA/src/SocialAutomationServiceProvider.php" \
  "$SA/openapi/openapi.yaml" \
  "$SA/src/Http/Controllers/SocialAutomationBrowserInventoryController.php" \
  "$SA/src/Domain/OnlineStateCalculator.php" \
  "$SA/src/Registry/SessionSyncService.php" \
  "$SA/tests/Unit/StaffBrowserInventoryContractTest.php"

echo
echo "===ROUTE==="
sudo -u www php "$CL/artisan" route:list --name=social-automation.browsers 2>&1
sudo -u www php "$CL/artisan" route:list --name=social-automation.script-registry 2>&1 | head -10
sudo -u www php "$CL/artisan" route:list --name=social-automation.spa 2>&1 | head -15

echo
echo "===PROVIDER_MARKERS==="
grep -n "api/browsers\|BrowserInventory\|script-registry\|StaffScriptRegistry\|RpaAdminSpaController\|api/action-registry\|api/accounts\|api/client-keys" \
  "$SA/src/SocialAutomationServiceProvider.php" | head -40
