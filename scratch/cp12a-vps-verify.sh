#!/bin/bash
set -euo pipefail
SA=/www/wwwroot/seo-ops-addons/social-automation
CL=/www/wwwroot/seo-ops-client

echo "===SHA256==="
sha256sum \
  "$SA/src/Http/Controllers/ScriptRegistry/StaffScriptRegistryController.php" \
  "$SA/src/PackageRegistry/ScriptRegistryUiService.php" \
  "$SA/src/SocialAutomationServiceProvider.php" \
  "$SA/tests/Unit/StaffScriptRegistryContractTest.php" \
  "$SA/openapi/openapi.yaml"

echo
echo "===ROUTES==="
sudo -u www php "$CL/artisan" route:list --name=social-automation.script-registry 2>&1

echo
echo "===MARKERS==="
grep -n "api/script-registry\|StaffScriptRegistryController\|is_authentication\|script_id" \
  "$SA/src/SocialAutomationServiceProvider.php" \
  "$SA/src/PackageRegistry/ScriptRegistryUiService.php" \
  "$SA/src/Http/Controllers/ScriptRegistry/StaffScriptRegistryController.php" | head -40
